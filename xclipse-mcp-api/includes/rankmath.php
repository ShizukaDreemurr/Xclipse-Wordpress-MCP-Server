<?php
/** RankMath SEO, SERP tools, Rich Results, Schema, Taxonomy SEO */
defined('ABSPATH') || exit;

// RANKMATH — helpers

/** Bypass WP object cache — đọc thẳng từ DB để tránh stale data sau update */
function xclipse_rm_opt_fresh(string $key) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $key
    ));
    return $row ? maybe_unserialize($row->option_value) : null;
}

/** Xoá tất cả cache liên quan Rank Math sau khi update */
function xclipse_rm_flush_cache(string $group): void {
    wp_cache_delete("rank-math-options-{$group}", 'options');
    delete_transient('rank_math_options_' . $group);
    delete_transient('rank_math_options');
    if (function_exists('wp_cache_flush_group')) wp_cache_flush_group('options');
}

/**
 * Sanitize 1 value cho Rank Math settings — hỗ trợ string/bool/number/array.
 * Array được giữ nguyên cấu trúc (dùng cho opening_hours, phone_numbers, v.v.)
 */
function xclipse_rm_sanitize_value($v) {
    if (is_array($v)) {
        // Sanitize đệ quy từng phần tử
        return array_map(fn($item) => is_array($item)
            ? array_map('sanitize_text_field', array_map('strval', $item))
            : sanitize_text_field((string)$item),
        $v);
    }
    if (is_bool($v) || is_numeric($v)) return $v;
    if (is_string($v)) return sanitize_text_field($v);
    return null;
}

// RANKMATH — endpoints

function xclipse_rm_overview(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    global $wpdb;
    $pt    = sanitize_key($r['post_type'] ?? '');
    $min_s = $r['min_score'] !== null ? (int)$r['min_score'] : null;
    $max_s = $r['max_score'] !== null ? (int)$r['max_score'] : null;

    $pt_clause  = $pt ? $wpdb->prepare(" AND p.post_type=%s", $pt) : " AND p.post_type IN ('post','page','product')";
    $no_kw      = (int)$wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='rank_math_focus_keyword' WHERE p.post_status='publish'{$pt_clause} AND (m.meta_value='' OR m.meta_value IS NULL)");

    // Filter theo score nếu có
    $score_rows = [];
    if ($min_s !== null || $max_s !== null) {
        $min_s ??= 0; $max_s ??= 100;
        $score_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, m.meta_value score FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='rank_math_seo_score' WHERE p.post_status='publish'{$pt_clause} AND CAST(m.meta_value AS UNSIGNED) BETWEEN %d AND %d ORDER BY CAST(m.meta_value AS UNSIGNED) ASC LIMIT 50",
            $min_s, $max_s
        ), ARRAY_A);
    }
    return xclipse_ok(array_filter([
        'version'    => RANK_MATH_VERSION,
        'posts_no_kw'=> $no_kw,
        'score_filter'=> $score_rows ?: null,
    ]));
}

/** Dump TOÀN BỘ Rank Math options — đọc thẳng DB, bypass cache */
function xclipse_rm_dump(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $group  = sanitize_key($r['group'] ?? 'all');
    $groups = $group === 'all' ? ['general', 'titles', 'sitemap'] : [$group];
    $out    = [];
    foreach ($groups as $g) {
        $opt    = xclipse_rm_opt_fresh("rank-math-options-{$g}");
        $out[$g] = $opt !== null ? json_decode(json_encode($opt), true) : null;
    }
    // Postmeta của post cụ thể (nếu truyền post_id) hoặc bài mới nhất
    global $wpdb;
    $pid = $r['post_id'] ? (int)$r['post_id'] : (int)$wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY ID DESC LIMIT 1"
    );
    $meta = [];
    if ($pid) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE 'rank_math%'", $pid
        ), ARRAY_A);
        foreach ($rows as $row) $meta[$row['meta_key']] = $row['meta_value'];
    }
    return xclipse_ok(['version' => RANK_MATH_VERSION, 'options' => $out, 'sample_post_id' => $pid, 'postmeta_keys' => $meta]);
}

function xclipse_rm_get(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $id = (int)$r['id'];
    // Clear cache để luôn đọc fresh từ DB
    clean_post_cache($id);
    wp_cache_delete($id, 'post_meta');
    global $wpdb;
    // Đọc custom schema nếu có
    $schema_keys = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE 'rank_math_schema%'", $id
    ), ARRAY_A);
    $custom_schema = [];
    foreach ($schema_keys as $row) $custom_schema[$row['meta_key']] = $row['meta_value'];
    return xclipse_ok(array_filter([
        'id'            => $id,
        'seo'           => xclipse_seo($id),
        'custom_schema' => $custom_schema ?: null,
    ]));
}

function xclipse_rm_set(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    xclipse_save_seo($id, $b);
    // Ghi custom_schema nếu có
    if (isset($b['custom_schema']) && is_array($b['custom_schema'])) {
        foreach ($b['custom_schema'] as $k => $v) {
            $k = sanitize_key($k);
            if (str_starts_with($k, 'rank_math_schema'))
                update_post_meta($id, $k, is_array($v) ? $v : sanitize_textarea_field($v));
        }
    }
    // Flush toàn bộ WordPress object cache cho post này
    // (bao gồm cả in-process cache của get_post_meta)
    clean_post_cache($id);
    wp_cache_delete($id, 'post_meta');
    // Xóa thêm các cache keys khác mà WP dùng internally
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('post_meta');
    }
    // Đọc thẳng từ DB để verify (dùng $wpdb tránh cache)
    return xclipse_ok(['ok' => true, 'id' => $id, 'seo' => xclipse_seo($id)]);
}

/**
 * Bulk update SEO cho nhiều posts cùng lúc.
 * Body: { "items": [{"id":123,"seo_title":"...","seo_desc":"...","focus_kw":"..."},…] }
 */
function xclipse_rm_bulk(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $b     = $r->get_json_params();
    $items = $b['items'] ?? [];
    if (empty($items) || !is_array($items))
        return xclipse_err('empty', 'items phải là array');
    $ok = []; $fail = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if (!$id || !get_post($id)) { $fail[] = $id; continue; }
        xclipse_save_seo($id, $item);
        // Flush cache sau mỗi lần write
        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');
        $ok[] = $id;
    }
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('post_meta');
    }
    return xclipse_ok(['ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}

/** Đọc Rank Math global settings — bypass cache */
function xclipse_rm_settings_get(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $group   = sanitize_key($r['group'] ?? 'titles');
    $allowed = ['general', 'titles', 'sitemap'];
    if (!in_array($group, $allowed, true))
        return xclipse_err('bad_group', 'group phải là: general | titles | sitemap');
    $opt = xclipse_rm_opt_fresh("rank-math-options-{$group}") ?: [];
    // titles: chỉ trả keys Local SEO + KG để tiết kiệm token
    if ($group === 'titles') {
        $local_keys = ['knowledgegraph_type','knowledgegraph_name','url','email',
            'local_seo','local_address','local_business_type','opening_hours','opening_hours_format',
            'phone_numbers','price_range','social_url_facebook','social_url_twitter',
            'social_url_instagram','social_url_youtube','organization_description',
            'knowledgegraph_logo','open_graph_image','geo','facebook_app_id'];
        $opt = array_intersect_key((array)$opt, array_flip($local_keys));
    }
    return xclipse_ok(['group' => $group, 'settings' => $opt]);
}

/**
 * Ghi Rank Math global settings — hỗ trợ array values (opening_hours, phone_numbers, v.v.)
 * Body: { "group": "titles", "settings": { "url": "...", "opening_hours": [{...}], ... } }
 */
function xclipse_rm_settings_set(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $b       = $r->get_json_params();
    $group   = sanitize_key($b['group'] ?? 'titles');
    $allowed = ['general', 'titles', 'sitemap'];
    if (!in_array($group, $allowed, true))
        return xclipse_err('bad_group', 'group phải là: general | titles | sitemap');
    $incoming = (array)($b['settings'] ?? []);
    if (empty($incoming)) return xclipse_err('empty', 'settings không được rỗng');

    $clean = [];
    foreach ($incoming as $k => $v) {
        $k = sanitize_key($k);
        $sanitized = xclipse_rm_sanitize_value($v);
        if ($sanitized !== null) $clean[$k] = $sanitized;
    }

    // Đọc thẳng từ DB để tránh merge với stale cache
    $current = (array)(xclipse_rm_opt_fresh("rank-math-options-{$group}") ?: []);
    $merged  = array_merge($current, $clean);
    update_option("rank-math-options-{$group}", $merged);
    xclipse_rm_flush_cache($group);

    return xclipse_ok(['ok' => true, 'group' => $group, 'updated' => array_keys($clean)]);
}

// ─── Rank Math Redirections ───────────────────────────────────────────────────
function xclipse_rm_redirects_list(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    global $wpdb;
    $t = $wpdb->prefix . 'rank_math_redirections';
    if (!$wpdb->get_var("SHOW TABLES LIKE '{$t}'"))
        return xclipse_err('no_table', 'Rank Math Redirections chưa được kích hoạt', 404);
    $limit = min((int)($r['per_page'] ?? 50), 200);
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, sources, url_to, header_code, hits, created, updated FROM {$t} WHERE status='active' ORDER BY updated DESC LIMIT %d",
        $limit
    ), ARRAY_A);
    return xclipse_ok(['total' => count($rows), 'redirects' => array_map(fn($row) => [
        'id'      => (int)$row['id'],
        'from'    => maybe_unserialize($row['sources']),
        'to'      => $row['url_to'],
        'code'    => (int)$row['header_code'],
        'hits'    => (int)$row['hits'],
        'updated' => $row['updated'],
    ], $rows)]);
}

function xclipse_rm_redirect_create(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $b    = $r->get_json_params();
    $from = sanitize_text_field($b['from'] ?? '');
    $to   = esc_url_raw($b['to'] ?? '');
    $code = in_array((int)($b['code'] ?? 301), [301, 302, 307, 410, 451]) ? (int)$b['code'] : 301;
    if (!$from || !$to) return xclipse_err('missing', 'from và to là bắt buộc');
    global $wpdb;
    $t = $wpdb->prefix . 'rank_math_redirections';
    if (!$wpdb->get_var("SHOW TABLES LIKE '{$t}'"))
        return xclipse_err('no_table', 'Rank Math Redirections chưa được kích hoạt', 404);
    $sources = maybe_serialize([['pattern' => $from, 'comparison' => 'exact']]);
    $wpdb->insert($t, ['sources' => $sources, 'url_to' => $to, 'header_code' => $code, 'status' => 'active', 'hits' => 0, 'created' => current_time('mysql'), 'updated' => current_time('mysql')]);
    return xclipse_ok(['ok' => true, 'id' => $wpdb->insert_id, 'from' => $from, 'to' => $to, 'code' => $code]);
}

function xclipse_rm_redirect_delete(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    global $wpdb;
    $id = (int)$r['id'];
    $t  = $wpdb->prefix . 'rank_math_redirections';
    // Xoá cả sources liên quan
    $wpdb->delete($wpdb->prefix . 'rank_math_redirection_sources', ['redirection_id' => $id]);
    $deleted = $wpdb->delete($t, ['id' => $id]);
    // Clear Rank Math redirection cache
    wp_cache_delete('rank_math_redirections', 'rank_math');
    wp_cache_delete('redirections', 'rank_math');
    delete_transient('rank_math_redirections');
    if (class_exists('\RankMath\Redirections\Cache')) {
        \RankMath\Redirections\Cache::clear();
    }
    return $deleted ? xclipse_ok(['ok' => true, 'id' => $id, 'deleted' => true])
                    : xclipse_err('not_found', 'Redirect không tồn tại');
}

// SERP / SEO TOOLS

/**
 * Helper: lấy SerpApi key từ WP options (hoặc hardcoded fallback).
 */
function xclipse_serpapi_key(): string {
    return get_option('xclipse_serpapi_key', '235177831a63d1c93aad6b4353fac1c90db2fcdbafc4ab44b55044e76bdc02b6');
}

/**
 * Helper: gọi HTTP GET tới URL, trả về array hoặc WP_Error.
 */
function xclipse_http_get(string $url): array {
    $resp = wp_remote_get($url, ['timeout' => 45, 'sslverify' => true]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true) ?? [];
    if ($code !== 200) {
        $err = $body['error'] ?? "HTTP {$code}";
        $msg = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
        return new WP_Error('api_error', $msg, ['status' => $code >= 400 && $code < 500 ? 422 : 502]);
    }
    return $body;
}

function xclipse_rank_check(WP_REST_Request $r) {
    $keyword = sanitize_text_field($r['keyword'] ?? '');
    if (!$keyword) return xclipse_err('missing', 'Thiếu tham số keyword', 400);

    $domain = sanitize_text_field($r['domain'] ?? '') ?: parse_url(home_url(), PHP_URL_HOST);
    $domain = preg_replace('/^www\./', '', $domain);
    $gl     = sanitize_key($r['gl'] ?? 'vn');
    $hl     = sanitize_key($r['hl'] ?? 'vi');
    $num    = in_array((int)($r['num'] ?? 100), [10, 20, 50, 100]) ? (int)$r['num'] : 100;

    $url = add_query_arg([
        'engine'  => 'google',
        'q'       => $keyword,
        'gl'      => $gl,
        'hl'      => $hl,
        'num'     => $num,
        'api_key' => xclipse_serpapi_key(),
    ], 'https://serpapi.com/search');

    $data = xclipse_http_get($url);
    if (is_wp_error($data)) return $data;

    $organic = $data['organic_results'] ?? [];
    $position = null;
    $matched_url = null;

    foreach ($organic as $item) {
        $link = $item['link'] ?? '';
        $link_domain = preg_replace('/^www\./', '', parse_url($link, PHP_URL_HOST) ?? '');
        if ($link_domain === $domain) {
            $position    = $item['position'] ?? null;
            $matched_url = $link;
            break;
        }
    }

    return xclipse_ok(xclipse_compact([
        'keyword'     => $keyword,
        'domain'      => $domain,
        'position'    => $position,
        'url'         => $matched_url,
        'not_found'   => $position === null,
        'total_scanned' => count($organic),
        'gl'          => $gl,
        'hl'          => $hl,
        'search_info' => $data['search_information'] ?? null,
    ]));
}

function xclipse_serp_overview(WP_REST_Request $r) {
    $keyword = sanitize_text_field($r['keyword'] ?? '');
    if (!$keyword) return xclipse_err('missing', 'Thiếu tham số keyword', 400);

    $gl  = sanitize_key($r['gl']  ?? 'vn');
    $hl  = sanitize_key($r['hl']  ?? 'vi');
    $num = min((int)($r['num'] ?? 10), 10);

    $url = add_query_arg([
        'engine'  => 'google',
        'q'       => $keyword,
        'gl'      => $gl,
        'hl'      => $hl,
        'num'     => 10,
        'api_key' => xclipse_serpapi_key(),
    ], 'https://serpapi.com/search');

    $data = xclipse_http_get($url);
    if (is_wp_error($data)) return $data;

    $organic = array_slice($data['organic_results'] ?? [], 0, $num);
    $results = array_map(fn($item) => xclipse_compact([
        'pos'     => $item['position'] ?? null,
        'title'   => $item['title'] ?? null,
        'url'     => $item['link'] ?? null,
        'domain'  => parse_url($item['link'] ?? '', PHP_URL_HOST) ?: null,
        'snippet' => isset($item['snippet']) ? mb_substr($item['snippet'], 0, 200) : null,
    ]), $organic);

    return xclipse_ok([
        'keyword'      => $keyword,
        'gl'           => $gl,
        'hl'           => $hl,
        'search_info'  => $data['search_information'] ?? null,
        'related_searches' => array_column($data['related_searches'] ?? [], 'query'),
        'results'      => $results,
    ]);
}

function xclipse_rank_bulk(WP_REST_Request $r) {
    $raw = $r['keywords'] ?? [];
    // Hỗ trợ cả keywords[]=a&keywords[]=b lẫn keywords=a,b,c
    if (is_string($raw)) {
        $raw = array_map('trim', explode(',', $raw));
    }
    $keywords = array_filter(array_unique(array_map('sanitize_text_field', (array)$raw)));
    if (!$keywords) return xclipse_err('missing', 'Thiếu keywords[]', 400);
    if (count($keywords) > 10) return xclipse_err('too_many', 'Tối đa 10 từ khoá / request', 400);

    $domain = sanitize_text_field($r['domain'] ?? '') ?: parse_url(home_url(), PHP_URL_HOST);
    $domain = preg_replace('/^www\./', '', $domain);
    $gl     = sanitize_key($r['gl'] ?? 'vn');
    $hl     = sanitize_key($r['hl'] ?? 'vi');

    $results = [];
    foreach ($keywords as $kw) {
        $url = add_query_arg([
            'engine'  => 'google',
            'q'       => $kw,
            'gl'      => $gl,
            'hl'      => $hl,
            'num'     => 100,
            'api_key' => xclipse_serpapi_key(),
        ], 'https://serpapi.com/search');

        $data = xclipse_http_get($url);
        if (is_wp_error($data)) {
            $results[] = ['keyword' => $kw, 'error' => $data->get_error_message()];
            continue;
        }

        $organic  = $data['organic_results'] ?? [];
        $position = null;
        $matched  = null;
        foreach ($organic as $item) {
            $ld = preg_replace('/^www\./', '', parse_url($item['link'] ?? '', PHP_URL_HOST) ?? '');
            if ($ld === $domain) { $position = $item['position']; $matched = $item['link']; break; }
        }

        $results[] = xclipse_compact([
            'keyword'  => $kw,
            'position' => $position,
            'url'      => $matched,
            'not_found'=> $position === null,
            'total_scanned' => count($organic),
        ]);
    }

    return xclipse_ok([
        'domain'  => $domain,
        'gl'      => $gl,
        'hl'      => $hl,
        'count'   => count($results),
        'results' => $results,
    ]);
}

function xclipse_url_inspect(WP_REST_Request $r) {
    $inspect_url = esc_url_raw($r['url'] ?? '') ?: home_url('/');
    $host        = preg_replace('/^www\./', '', parse_url(home_url(), PHP_URL_HOST));
    $site        = sanitize_text_field($r['site'] ?? '') ?: "sc-domain:{$host}";

    // Thử lấy OAuth token từ Site Kit
    $token = null;
    if (defined('GOOGLESITEKIT_VERSION')) {
        $auth = get_option('googlesitekit_auth');
        if (!empty($auth['access_token'])) {
            $token = $auth['access_token'];
        }
        // Thử cách khác qua transient
        if (!$token) {
            $token = get_transient('googlesitekit_access_token');
        }
    }

    // Thử lấy từ option riêng
    if (!$token) {
        $token = get_option('xclipse_gsc_token', '');
    }

    if (!$token) {
        return xclipse_err('no_token',
            'Chưa có GSC OAuth token. Cài Google Site Kit và kết nối GSC, hoặc lưu token vào option "xclipse_gsc_token".',
            501
        );
    }

    $api_url = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
    $body    = json_encode([
        'inspectionUrl' => $inspect_url,
        'siteUrl'       => $site,
    ]);

    $resp = wp_remote_post($api_url, [
        'timeout' => 20,
        'headers' => [
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
    ]);

    if (is_wp_error($resp)) return $resp;
    $code    = wp_remote_retrieve_response_code($resp);
    $result  = json_decode(wp_remote_retrieve_body($resp), true) ?? [];

    if ($code !== 200) {
        $msg = $result['error']['message'] ?? "GSC API HTTP {$code}";
        return xclipse_err('gsc_error', $msg, 502);
    }

    $ir = $result['inspectionResult'] ?? [];
    $ix = $ir['indexStatusResult'] ?? [];
    $mo = $ir['mobileUsabilityResult'] ?? [];
    $rm = $ir['richResultsResult'] ?? [];

    return xclipse_ok([
        'url'            => $inspect_url,
        'site'           => $site,
        'verdict'        => $ix['verdict'] ?? null,
        'coverage_state' => $ix['coverageState'] ?? null,
        'crawled_as'     => $ix['crawledAs'] ?? null,
        'last_crawl'     => $ix['lastCrawlTime'] ?? null,
        'index_date'     => $ix['indexingState'] ?? null,
        'canonical_google'    => $ix['googleCanonical'] ?? null,
        'canonical_declared'  => $ix['userCanonical'] ?? null,
        'sitemap'        => $ix['sitemap'] ?? null,
        'referring_urls' => $ix['referringUrls'] ?? null,
        'mobile_verdict' => $mo['verdict'] ?? null,
        'mobile_issues'  => $mo['issues'] ?? null,
        'rich_results'   => $rm['verdict'] ?? null,
    ]);
}

// RICH RESULTS / STRUCTURED DATA VALIDATION — v3.3.1
// Dùng Google Search Console URL Inspection + tự parse + validator.schema.org

function xclipse_rich_results(WP_REST_Request $r) {
    $url      = esc_url_raw($r['url'] ?? '') ?: home_url('/');
    $do_valid = ($r['validate'] ?? 'true') !== 'false';

    // ── 1. Fetch trang HTML ───────────────────────────────────────────────
    $html_resp = wp_remote_get($url, [
        'timeout'   => 20,
        'sslverify' => false,
        'headers'   => ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    ]);
    if (is_wp_error($html_resp)) return $html_resp;
    $html = wp_remote_retrieve_body($html_resp);
    $http_code = wp_remote_retrieve_response_code($html_resp);

    // ── 2. Extract JSON-LD ───────────────────────────────────────────────
    $json_ld_blocks = [];
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if ($decoded !== null) $json_ld_blocks[] = $decoded;
        }
    }

    // ── 3. Extract Open Graph + Twitter meta (bonus) ────────────────────
    $og = [];
    if (preg_match_all('/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m)) {
        foreach ($m[1] as $i => $k) $og[$k] = $m[2][$i];
    }
    // title từ <title>
    $page_title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
        $page_title = trim(strip_tags($m[1]));
    }

    // ── 4. Phân tích nhanh từng schema block ────────────────────────────
    $analysis = [];
    $rich_types_supported = [
        'Article', 'NewsArticle', 'BlogPosting',
        'Product', 'Offer', 'AggregateOffer',
        'BreadcrumbList', 'ListItem',
        'FAQPage', 'Question', 'Answer',
        'HowTo', 'HowToStep',
        'Recipe',
        'Event',
        'JobPosting',
        'LocalBusiness', 'Store', 'JewelryStore',
        'Organization', 'Corporation',
        'Person',
        'WebSite', 'WebPage',
        'VideoObject',
        'ImageObject',
        'Review', 'AggregateRating',
        'SiteNavigationElement',
    ];

    // Required fields theo loại schema (Google's requirements)
    $required_fields = [
        'Article'         => ['headline', 'image', 'datePublished', 'author'],
        'NewsArticle'     => ['headline', 'image', 'datePublished', 'author'],
        'BlogPosting'     => ['headline', 'author'],
        'Product'         => ['name', 'image', 'description'],
        'Offer'           => ['price', 'priceCurrency'],
        'AggregateRating' => ['ratingValue', 'reviewCount'],
        'BreadcrumbList'  => ['itemListElement'],
        'FAQPage'         => ['mainEntity'],
        'HowTo'           => ['name', 'step'],
        'Recipe'          => ['name', 'image', 'author', 'recipeIngredient'],
        'Event'           => ['name', 'startDate', 'location'],
        'JobPosting'      => ['title', 'description', 'hiringOrganization', 'jobLocation'],
        'LocalBusiness'   => ['name', 'address', 'telephone'],
        'Organization'    => ['name', 'url'],
        'Person'          => ['name'],
        'VideoObject'     => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
        'Review'          => ['itemReviewed', 'reviewRating', 'author'],
        'WebSite'         => ['name', 'url'],
    ];

    foreach ($json_ld_blocks as $idx => $block) {
        // Handle @graph
        $items = isset($block['@graph']) && is_array($block['@graph']) ? $block['@graph'] : [$block];
        foreach ($items as $item) {
            $type = is_array($item['@type'] ?? null) ? implode(',', $item['@type']) : ($item['@type'] ?? 'Unknown');
            $base_type = explode(',', $type)[0];
            $is_rich = in_array($base_type, $rich_types_supported);

            $missing = [];
            $reqs = $required_fields[$base_type] ?? [];
            foreach ($reqs as $field) {
                if (empty($item[$field])) $missing[] = $field;
            }

            $warnings = [];
            // Image warnings
            if (isset($item['image'])) {
                $img = $item['image'];
                if (is_string($img) && !filter_var($img, FILTER_VALIDATE_URL)) $warnings[] = 'image không phải URL hợp lệ';
            }
            // datePublished format
            if (isset($item['datePublished']) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $item['datePublished']))
                $warnings[] = 'datePublished nên dùng format ISO 8601 (YYYY-MM-DD)';
            // author object
            if (isset($item['author']) && is_string($item['author']))
                $warnings[] = 'author nên là {"@type":"Person","name":"..."} thay vì string';

            $analysis[] = xclipse_compact([
                'block_idx'   => $idx,
                'type'        => $type,
                'rich_result' => $is_rich,
                'missing_required' => $missing ?: null,
                'warnings'    => $warnings ?: null,
                'has_id'      => !empty($item['@id']),
                'fields'      => array_keys(array_filter($item, fn($v) => !is_null($v))),
            ]);
        }
    }

    // ── 5. Validator.schema.org API ──────────────────────────────────────
    // API endpoint: https://validator.schema.org/validate (POST form)
    // Không cần key, public endpoint
    $validator_results = [];
    if ($do_valid && !empty($json_ld_blocks)) {
        // Gộp tất cả JSON-LD thành 1 chuỗi HTML để validate
        $jsonld_html = '';
        foreach ($json_ld_blocks as $block) {
            $jsonld_html .= '<script type="application/ld+json">' . json_encode($block) . '</script>';
        }
        $val_resp = wp_remote_post('https://validator.schema.org/validate', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            'body' => http_build_query(['html' => $jsonld_html]),
        ]);
        if (!is_wp_error($val_resp) && wp_remote_retrieve_response_code($val_resp) === 200) {
            $val_body = json_decode(wp_remote_retrieve_body($val_resp), true) ?? [];
            // Trích errors + warnings
            foreach ($val_body as $entity) {
                if (!empty($entity['errors']) || !empty($entity['warnings'])) {
                    $validator_results[] = [
                        'entity' => $entity['entityId'] ?? null,
                        'errors' => array_map(fn($e) => [
                            'prop'    => $e['property'] ?? null,
                            'message' => $e['message'] ?? null,
                        ], $entity['errors'] ?? []),
                        'warnings' => array_map(fn($w) => [
                            'prop'    => $w['property'] ?? null,
                            'message' => $w['message'] ?? null,
                        ], $entity['warnings'] ?? []),
                    ];
                }
            }
        }
    }

    // ── 6. GSC Rich Results verdict (nếu có token) ──────────────────────
    $gsc_verdict = null;
    $gsc_rich    = null;
    $token = null;
    if (defined('GOOGLESITEKIT_VERSION')) {
        $auth  = get_option('googlesitekit_auth');
        $token = !empty($auth['access_token']) ? $auth['access_token'] : get_transient('googlesitekit_access_token');
    }
    if (!$token) $token = get_option('xclipse_gsc_token', '');
    if ($token) {
        $host = preg_replace('/^www\./', '', parse_url(home_url(), PHP_URL_HOST));
        $site = "sc-domain:{$host}";
        $gsc_resp = wp_remote_post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'timeout' => 15,
            'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            'body'    => json_encode(['inspectionUrl' => $url, 'siteUrl' => $site]),
        ]);
        if (!is_wp_error($gsc_resp) && wp_remote_retrieve_response_code($gsc_resp) === 200) {
            $gsc_data    = json_decode(wp_remote_retrieve_body($gsc_resp), true) ?? [];
            $ir          = $gsc_data['inspectionResult'] ?? [];
            $rr          = $ir['richResultsResult'] ?? [];
            $gsc_verdict = $rr['verdict'] ?? null;
            $gsc_rich    = !empty($rr['detectedItems']) ? array_map(fn($d) => [
                'type'   => $d['richResultType'] ?? null,
                'items'  => array_map(fn($i) => [
                    'name'   => $i['name'] ?? null,
                    'issues' => $i['issues'] ?? [],
                ], $d['items'] ?? []),
            ], $rr['detectedItems']) : null;
        }
    }

    // ── 7. Tổng hợp kết quả ─────────────────────────────────────────────
    $total_schemas  = count($analysis);
    $rich_eligible  = count(array_filter($analysis, fn($a) => $a['rich_result']));
    $has_errors     = array_filter($analysis, fn($a) => !empty($a['missing_required']));
    $score          = $total_schemas > 0
        ? round(($rich_eligible / $total_schemas) * 100 * (1 - count($has_errors) / max($total_schemas, 1)))
        : 0;

    return xclipse_ok([
        'url'              => $url,
        'http_code'        => $http_code,
        'page_title'       => $page_title ?: null,
        'og'               => $og ?: null,
        'schema_count'     => $total_schemas,
        'rich_eligible'    => $rich_eligible,
        'schema_score'     => $score,  // 0-100 ước tính
        'schemas'          => $analysis,
        'validator'        => $validator_results ?: null,
        'gsc_verdict'      => $gsc_verdict,
        'gsc_rich_results' => $gsc_rich,
        'has_gsc_token'    => (bool)$token,
        'tip'              => !$token ? 'Kết nối Google Site Kit để lấy verdict chính thức từ Google' : null,
    ]);
}

function xclipse_schema_validate(WP_REST_Request $r) {
    $b = $r->get_json_params() ?? [];

    // Xây dựng HTML để gửi lên validator
    $html_to_validate = '';
    $schema_obj       = null;

    if (!empty($b['schema'])) {
        // Schema object trực tiếp
        $schema_obj = $b['schema'];
        $html_to_validate = '<script type="application/ld+json">' . json_encode($schema_obj) . '</script>';
    } elseif (!empty($b['html'])) {
        $html_to_validate = $b['html'];
        // Extract để analyze
        if (preg_match('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $b['html'], $m))
            $schema_obj = json_decode(trim($m[1]), true);
    } elseif (!empty($b['url'])) {
        // Fetch URL rồi validate
        $resp = wp_remote_get(esc_url_raw($b['url']), [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'Googlebot'],
        ]);
        if (is_wp_error($resp)) return $resp;
        $html_to_validate = wp_remote_retrieve_body($resp);
        if (preg_match('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html_to_validate, $m))
            $schema_obj = json_decode(trim($m[1]), true);
    } else {
        return xclipse_err('missing', 'Cần 1 trong 3: schema (object), html (string), url (string)', 400);
    }

    // Gọi validator.schema.org
    $validator_result = null;
    $val_resp = wp_remote_post('https://validator.schema.org/validate', [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ],
        'body' => http_build_query(['html' => $html_to_validate]),
    ]);

    $raw_validator = null;
    if (!is_wp_error($val_resp) && wp_remote_retrieve_response_code($val_resp) === 200) {
        $raw_validator = json_decode(wp_remote_retrieve_body($val_resp), true) ?? [];
    }

    // Parse errors/warnings từ validator
    $errors = []; $warnings = [];
    if ($raw_validator) {
        foreach ($raw_validator as $entity) {
            foreach ($entity['errors'] ?? [] as $e) {
                $errors[] = ['property' => $e['property'] ?? null, 'message' => $e['message'] ?? null, 'severity' => 'error'];
            }
            foreach ($entity['warnings'] ?? [] as $w) {
                $warnings[] = ['property' => $w['property'] ?? null, 'message' => $w['message'] ?? null, 'severity' => 'warning'];
            }
        }
    }

    // Quick analysis nếu có schema object
    $quick = null;
    if ($schema_obj) {
        $items = isset($schema_obj['@graph']) ? $schema_obj['@graph'] : [$schema_obj];
        $required_map = [
            'Article'         => ['headline', 'image', 'datePublished', 'author'],
            'NewsArticle'     => ['headline', 'image', 'datePublished', 'author'],
            'BlogPosting'     => ['headline', 'author'],
            'Product'         => ['name', 'image', 'description'],
            'Offer'           => ['price', 'priceCurrency'],
            'AggregateRating' => ['ratingValue', 'reviewCount'],
            'BreadcrumbList'  => ['itemListElement'],
            'FAQPage'         => ['mainEntity'],
            'HowTo'           => ['name', 'step'],
            'Recipe'          => ['name', 'image', 'author', 'recipeIngredient'],
            'Event'           => ['name', 'startDate', 'location'],
            'LocalBusiness'   => ['name', 'address', 'telephone'],
            'Organization'    => ['name', 'url'],
            'VideoObject'     => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
        ];
        $quick = [];
        foreach ($items as $item) {
            $type  = is_array($item['@type'] ?? null) ? implode(',', $item['@type']) : ($item['@type'] ?? 'Unknown');
            $base  = explode(',', $type)[0];
            $reqs  = $required_map[$base] ?? [];
            $missing = array_filter($reqs, fn($f) => empty($item[$f]));
            $quick[] = ['type' => $type, 'missing_required' => array_values($missing), 'fields_present' => count(array_filter($item))];
        }
    }

    $is_valid = empty($errors);

    return xclipse_ok([
        'valid'      => $is_valid,
        'errors'     => $errors ?: null,
        'warnings'   => $warnings ?: null,
        'quick'      => $quick,
        'validator'  => 'validator.schema.org',
        'tip'        => $is_valid
            ? 'Schema hợp lệ! Dùng /rich-results?url=... để kiểm tra verdict từ Google.'
            : 'Sửa các errors trên rồi test lại.',
    ]);
}

// RANKMATH TAXONOMY — v3.3.2
// Rank Math lưu SEO cho terms qua get_term_meta / update_term_meta
// Meta keys: rank_math_title, rank_math_description, rank_math_focus_keyword,
//            rank_math_robots, rank_math_canonical_url, rank_math_seo_score
// Route: GET /rankmath/tax/{taxonomy}/{term_id}
//        PUT /rankmath/tax/{taxonomy}/{term_id}
//        GET /rankmath/tax/{taxonomy}         (list all terms + SEO)

/**
 * Helper: đọc Rank Math meta của 1 term từ DB (bỏ qua cache).
 */
function xclipse_rm_tax_get_meta(int $term_id): array {
    global $wpdb;
    $keys = ['rank_math_title','rank_math_description','rank_math_focus_keyword',
             'rank_math_seo_score','rank_math_robots','rank_math_canonical_url'];
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key IN ({$placeholders})",
        array_merge([$term_id], $keys)
    ), ARRAY_A);
    $meta = [];
    foreach ($rows as $row) $meta[$row['meta_key']] = $row['meta_value'];
    return array_filter([
        't'         => $meta['rank_math_title']         ?? null,
        'd'         => $meta['rank_math_description']   ?? null,
        'k'         => $meta['rank_math_focus_keyword'] ?? null,
        's'         => $meta['rank_math_seo_score']     ?? null,
        'robots'    => $meta['rank_math_robots']        ?? null,
        'canonical' => $meta['rank_math_canonical_url'] ?? null,
    ], fn($v) => $v !== '' && $v !== false && $v !== null);
}

/**
 * Helper: ghi Rank Math meta cho term, flush cache sau khi ghi.
 */
function xclipse_rm_tax_save(int $term_id, array $b): void {
    $fields = [
        'seo_title' => 'rank_math_title',
        'seo_desc'  => 'rank_math_description',
        'focus_kw'  => 'rank_math_focus_keyword',
        'canonical' => 'rank_math_canonical_url',
        'robots'    => 'rank_math_robots',
    ];
    foreach ($fields as $param => $meta_key) {
        if (!isset($b[$param])) continue;
        $val = $param === 'canonical'
            ? esc_url_raw($b[$param])
            : sanitize_text_field($b[$param]);
        // Force ghi lại kể cả khi value không đổi
        delete_term_meta($term_id, $meta_key);
        if ($val !== '') update_term_meta($term_id, $meta_key, $val);
    }
    // Flush WP cache
    clean_term_cache([$term_id]);
    wp_cache_delete("rank_math_term_meta_{$term_id}", 'rank_math');
}

function xclipse_rm_tax_list(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $taxonomy   = sanitize_key($r['taxonomy']);
    $hide_empty = ($r['hide_empty'] ?? 'false') !== 'false';
    $per_page   = min((int)($r['per_page'] ?? 200), 500);
    $search     = sanitize_text_field($r['search'] ?? '');

    $args = ['taxonomy' => $taxonomy, 'hide_empty' => $hide_empty, 'number' => $per_page, 'orderby' => 'count', 'order' => 'DESC'];
    if ($search) $args['search'] = $search;

    $terms = get_terms($args);
    if (is_wp_error($terms)) return $terms;
    if (empty($terms)) return xclipse_ok(['taxonomy' => $taxonomy, 'count' => 0, 'items' => []]);

    $items = array_map(fn($t) => [
        'term_id'  => $t->term_id,
        'name'     => $t->name,
        'slug'     => $t->slug,
        'count'    => $t->count,
        'parent'   => $t->parent ?: null,
        'link'     => get_term_link($t),
        'seo'      => xclipse_rm_tax_get_meta($t->term_id),
    ], $terms);

    return xclipse_ok(['taxonomy' => $taxonomy, 'count' => count($items), 'items' => $items]);
}

function xclipse_rm_tax_get(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $taxonomy = sanitize_key($r['taxonomy']);
    $term_id  = (int)$r['term_id'];
    $term     = get_term($term_id, $taxonomy);
    if (is_wp_error($term) || !$term) return xclipse_err('not_found', "Term {$term_id} không tồn tại trong {$taxonomy}", 404);

    return xclipse_ok([
        'term_id'  => $term->term_id,
        'name'     => $term->name,
        'slug'     => $term->slug,
        'taxonomy' => $taxonomy,
        'count'    => $term->count,
        'link'     => get_term_link($term),
        'seo'      => xclipse_rm_tax_get_meta($term_id),
    ]);
}

function xclipse_rm_tax_set(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $taxonomy = sanitize_key($r['taxonomy']);
    $term_id  = (int)$r['term_id'];
    $term     = get_term($term_id, $taxonomy);
    if (is_wp_error($term) || !$term) return xclipse_err('not_found', "Term {$term_id} không tồn tại trong {$taxonomy}", 404);

    $b = $r->get_json_params() ?? [];
    if (empty($b)) return xclipse_err('empty', 'Body rỗng — cần ít nhất 1 trong: seo_title, seo_desc, focus_kw, canonical, robots');

    xclipse_rm_tax_save($term_id, $b);

    return xclipse_ok([
        'ok'      => true,
        'term_id' => $term_id,
        'name'    => $term->name,
        'seo'     => xclipse_rm_tax_get_meta($term_id),
    ]);
}

function xclipse_rm_tax_bulk(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $b     = $r->get_json_params() ?? [];
    $items = $b['items'] ?? [];
    if (empty($items) || !is_array($items))
        return xclipse_err('empty', 'items phải là array [{taxonomy,term_id,seo_title,...},…]');

    $ok = []; $fail = [];
    foreach ($items as $item) {
        $term_id  = (int)($item['term_id'] ?? 0);
        $taxonomy = sanitize_key($item['taxonomy'] ?? '');
        if (!$term_id || !$taxonomy) { $fail[] = $item; continue; }
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) { $fail[] = $term_id; continue; }
        xclipse_rm_tax_save($term_id, $item);
        $ok[] = $term_id;
    }

    return xclipse_ok(['ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}