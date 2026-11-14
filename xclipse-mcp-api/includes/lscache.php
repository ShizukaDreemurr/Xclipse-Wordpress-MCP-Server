<?php
/** LiteSpeed Cache — purge, CDN, CCSS, object cache, crawl, image optm */
defined('ABSPATH') || exit;

// LITESPEED CACHE — v3.3.0
// Hỗ trợ toàn bộ tính năng: purge, object cache, CDN, CCSS, image optm, crawl

/** Kiểm tra LiteSpeed Cache có cài và active không */
function xclipse_lsc_check() {
    return defined('LSCWP_V') ? true : xclipse_err('no_lscache', 'LiteSpeed Cache chưa được cài hoặc chưa active', 501);
}

/** Đọc LiteSpeed Cache option (namespace litespeed.conf) */
function xclipse_lsc_opt(string $key) {
    // LiteSpeed lưu config trong option 'litespeed.conf' (serialized array)
    static $conf = null;
    if ($conf === null) {
        $conf = get_option('litespeed.conf', []);
        if (!is_array($conf)) $conf = [];
    }
    return $conf[$key] ?? null;
}

/** Reload conf cache (sau khi update) */
function xclipse_lsc_reload_conf(): void {
    // Force reload bằng cách xoá static cache qua trick closure
    $conf = get_option('litespeed.conf', []);
}

function xclipse_lsc_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);
    return xclipse_ok([
        'version'         => LSCWP_V,
        'cache_enabled'   => (bool)(($conf['cache-enabled'] ?? $conf['cache'] ?? false)),
        'cache_mobile'    => (bool)($conf['cache-mobile'] ?? false),
        'cache_login'     => (bool)($conf['cache-login'] ?? false),
        'cache_ttl'       => (int)($conf['cache-ttl_pub'] ?? $conf['ttl-pub'] ?? 604800),
        'object_cache'    => (bool)($conf['object-enabled'] ?? false),
        'object_driver'   => $conf['object-kind'] ?? null,      // 0=Memcached, 1=Redis
        'cdn_enabled'     => (bool)($conf['cdn-enabled'] ?? false),
        'cdn_url'         => $conf['cdn-ori'] ?? null,
        'ccss_enabled'    => (bool)($conf['optm-css_comb'] ?? false),
        'lazyload_img'    => (bool)($conf['optm-lazyload_img'] ?? false),
        'webp_enabled'    => (bool)($conf['img-webp'] ?? false),
        'esi_enabled'     => (bool)($conf['esi-enabled'] ?? false),
        'quic_cloud'      => $conf['cloud-server'] ?? null,
    ]);
}

function xclipse_lsc_purge_all(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b = $r->get_json_params() ?? [];

    $purged = [];

    // Purge page cache qua LiteSpeed Cache API
    if (class_exists('\LiteSpeed\Purge')) {
        \LiteSpeed\Purge::purge_all();
        $purged[] = 'page_cache';
    } elseif (function_exists('litespeed_purge_all')) {
        litespeed_purge_all();
        $purged[] = 'page_cache';
    } else {
        // Fallback: trigger action hook
        do_action('litespeed_purge_all');
        $purged[] = 'page_cache_hook';
    }

    // Object cache
    if (!empty($b['include_object'])) {
        do_action('litespeed_purge_object_cache');
        $purged[] = 'object_cache';
    }

    // CDN purge
    if (!empty($b['include_cdn'])) {
        do_action('litespeed_cdn_purge_all');
        $purged[] = 'cdn';
    }

    // Clear WordPress built-in caches
    wp_cache_flush();

    return xclipse_ok(['ok' => true, 'purged' => $purged, 'time' => current_time('mysql')]);
}

function xclipse_lsc_purge_url(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b   = $r->get_json_params() ?? [];
    $url = esc_url_raw($b['url'] ?? '');
    $pid = (int)($b['post_id'] ?? 0);

    if (!$url && !$pid) return xclipse_err('missing', 'Cần url hoặc post_id', 400);

    $purged = [];

    if ($pid) {
        // Purge theo post ID
        if (class_exists('\LiteSpeed\Purge')) {
            \LiteSpeed\Purge::purge_post($pid);
        } else {
            do_action('litespeed_purge_post', $pid);
        }
        $url = get_permalink($pid) ?: $url;
        $purged[] = "post:{$pid}";
    }

    if ($url) {
        do_action('litespeed_purge_url', $url);
        $purged[] = $url;
    }

    return xclipse_ok(['ok' => true, 'purged' => $purged, 'time' => current_time('mysql')]);
}

function xclipse_lsc_purge_tag(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b    = $r->get_json_params() ?? [];
    $tags = array_map('sanitize_text_field', (array)($b['tags'] ?? []));
    if (empty($tags)) return xclipse_err('missing', 'Cần mảng tags', 400);

    foreach ($tags as $tag) {
        do_action('litespeed_purge_tag', $tag);
    }

    // Nếu có class Purge
    if (class_exists('\LiteSpeed\Purge')) {
        \LiteSpeed\Purge::add_tags($tags);
    }

    return xclipse_ok(['ok' => true, 'purged_tags' => $tags, 'count' => count($tags)]);
}

function xclipse_lsc_object_cache_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);
    $enabled = (bool)($conf['object-enabled'] ?? false);
    $driver  = (int)($conf['object-kind'] ?? 0); // 0=Memcached, 1=Redis

    $info = [
        'enabled' => $enabled,
        'driver'  => $driver === 1 ? 'redis' : 'memcached',
        'host'    => $conf['object-host'] ?? '127.0.0.1',
        'port'    => (int)($conf['object-port'] ?? ($driver === 1 ? 6379 : 11211)),
        'lifetime'=> (int)($conf['object-life'] ?? 1800),
        'db_id'   => $conf['object-db_id'] ?? 0,
        'user_cache' => (bool)($conf['object-user_info'] ?? false),
        'persistent' => (bool)($conf['object-persistent'] ?? true),
        'cache_woocommerce' => (bool)($conf['object-global_groups'] ?? false),
    ];

    // Kiểm tra kết nối thực tế
    if ($enabled) {
        $connected = false;
        if ($driver === 1 && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redis->connect($info['host'], $info['port'], 1);
                $connected = $redis->ping() === true || $redis->ping() === '+PONG';
                $redis->close();
            } catch (\Exception $e) {}
        } elseif ($driver === 0 && class_exists('Memcached')) {
            try {
                $mc = new Memcached();
                $mc->addServer($info['host'], $info['port']);
                $stats = $mc->getStats();
                $connected = !empty($stats);
            } catch (\Exception $e) {}
        }
        $info['connected'] = $connected;
    }

    return xclipse_ok($info);
}

function xclipse_lsc_object_cache_action(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_key($b['action'] ?? 'flush');

    $conf = get_option('litespeed.conf', []);

    switch ($action) {
        case 'flush':
            do_action('litespeed_purge_object_cache');
            wp_cache_flush();
            return xclipse_ok(['ok' => true, 'action' => 'flush', 'time' => current_time('mysql')]);

        case 'enable':
            $conf['object-enabled'] = true;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_setting_update', 'object-enabled', true);
            return xclipse_ok(['ok' => true, 'action' => 'enable', 'object_cache' => true]);

        case 'disable':
            $conf['object-enabled'] = false;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_setting_update', 'object-enabled', false);
            wp_cache_flush();
            return xclipse_ok(['ok' => true, 'action' => 'disable', 'object_cache' => false]);

        default:
            return xclipse_err('bad_action', 'action phải là: flush | enable | disable', 400);
    }
}

function xclipse_lsc_cdn_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);

    return xclipse_ok([
        'cdn_enabled'        => (bool)($conf['cdn-enabled'] ?? false),
        'cdn_url'            => $conf['cdn-ori'] ?? null,
        'cdn_mapping'        => $conf['cdn-mapping'] ?? [],
        'quic_cloud_server'  => $conf['cloud-server'] ?? null,
        'quic_email'         => $conf['_email'] ?? null,
        'cdn_exclude'        => $conf['cdn-exc'] ?? [],
        'cdn_original'       => $conf['cdn-ori'] ?? null,
        'http2_push'         => (bool)($conf['cdn-http2_push'] ?? false),
        'lazy_dns'           => (bool)($conf['cdn-lazy'] ?? false),
        'cloudflare_enabled' => (bool)($conf['cdn-cloudflare'] ?? false),
        'cloudflare_zone'    => $conf['cdn-cloudflare_zone'] ?? null,
    ]);
}

function xclipse_lsc_cdn_purge(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b = $r->get_json_params() ?? [];

    if (!empty($b['purge_all'])) {
        do_action('litespeed_cdn_purge_all');
        // QUIC.cloud specific
        if (class_exists('\LiteSpeed\Cloud')) {
            do_action('litespeed_cloud_req_may_send');
        }
        return xclipse_ok(['ok' => true, 'action' => 'cdn_purge_all', 'time' => current_time('mysql')]);
    }

    $urls = array_map('esc_url_raw', (array)($b['urls'] ?? []));
    if (empty($urls)) return xclipse_err('missing', 'Cần purge_all:true hoặc urls:[]', 400);

    foreach ($urls as $url) {
        do_action('litespeed_cdn_purge_url', $url);
    }
    return xclipse_ok(['ok' => true, 'purged_urls' => $urls, 'count' => count($urls)]);
}

function xclipse_lsc_ccss_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);

    // Lấy danh sách CCSS đã generate
    global $wpdb;
    $ccss_table = $wpdb->prefix . 'litespeed_css';
    $ccss_list  = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$ccss_table}'")) {
        $ccss_list = $wpdb->get_results(
            "SELECT url, type, status, dateline FROM `{$ccss_table}` ORDER BY dateline DESC LIMIT 20",
            ARRAY_A
        ) ?: [];
    }

    return xclipse_ok([
        'ccss_enabled'       => (bool)($conf['optm-css_comb'] ?? false),
        'ccss_async'         => (bool)($conf['optm-css_comb_async'] ?? false),
        'inline_css_async'   => (bool)($conf['optm-css_async_inline'] ?? false),
        'ucss_enabled'       => (bool)($conf['optm-ucss'] ?? false),
        'ucss_inline'        => (bool)($conf['optm-ucss_inline'] ?? false),
        'css_minify'         => (bool)($conf['optm-css_min'] ?? false),
        'css_combine'        => (bool)($conf['optm-css_comb'] ?? false),
        'generated_count'    => count($ccss_list),
        'recent'             => $ccss_list,
    ]);
}

function xclipse_lsc_ccss_action(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_key($b['action'] ?? 'generate');
    $conf   = get_option('litespeed.conf', []);

    switch ($action) {
        case 'generate':
            // Trigger CCSS generation qua QUIC.cloud
            do_action('litespeed_ccss_generate');
            // Nếu có URLs cụ thể
            $urls = array_map('esc_url_raw', (array)($b['urls'] ?? []));
            foreach ($urls as $url) {
                do_action('litespeed_ccss_generate_url', $url);
            }
            return xclipse_ok(['ok' => true, 'action' => 'generate', 'queued_urls' => $urls ?: 'all']);

        case 'clear':
            global $wpdb;
            $table = $wpdb->prefix . 'litespeed_css';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
                $deleted = $wpdb->query("DELETE FROM `{$table}`");
            }
            do_action('litespeed_purge_all');
            return xclipse_ok(['ok' => true, 'action' => 'clear', 'deleted_rows' => $deleted ?? 0]);

        case 'enable':
            $conf['optm-css_comb'] = true;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'action' => 'enable', 'ccss_enabled' => true]);

        case 'disable':
            $conf['optm-css_comb'] = false;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'action' => 'disable', 'ccss_enabled' => false]);

        default:
            return xclipse_err('bad_action', 'action phải là: generate | clear | enable | disable', 400);
    }
}

function xclipse_lsc_img_optm_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);

    // Thống kê từ postmeta
    global $wpdb;
    $total_images    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_litespeed_img_optm'");
    $optimized       = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_litespeed_img_optm' AND meta_value='2'"); // 2=done
    $pending         = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_litespeed_img_optm' AND meta_value='1'"); // 1=requested
    $total_media     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'");

    return xclipse_ok([
        'img_optm_enabled'  => (bool)($conf['img-optm-auto'] ?? false),
        'webp_enabled'      => (bool)($conf['img-webp'] ?? false),
        'avif_enabled'      => (bool)($conf['img-avif'] ?? false),
        'lossless'          => (bool)($conf['img-lossless'] ?? false),
        'lazyload_img'      => (bool)($conf['optm-lazyload_img'] ?? false),
        'lazyload_iframe'   => (bool)($conf['optm-lazyload_iframe'] ?? false),
        'responsive_placeholder' => (bool)($conf['optm-lazyload_responsive'] ?? false),
        'total_images'      => $total_media,
        'optimized'         => $optimized,
        'pending'           => $pending,
        'not_optimized'     => max(0, $total_media - $optimized - $pending),
        'optimize_level'    => (int)($conf['img-optm-lossless'] ?? 0), // 0=lossy,1=lossless,2=lossless_png
    ]);
}

function xclipse_lsc_img_optm_action(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_key($b['action'] ?? 'request');
    $conf   = get_option('litespeed.conf', []);

    switch ($action) {
        case 'request':
            // Gửi ảnh lên QUIC.cloud để optimize
            $limit = min((int)($b['limit'] ?? 100), 1000);
            do_action('litespeed_img_optm_request', $limit);
            return xclipse_ok(['ok' => true, 'action' => 'request', 'limit' => $limit]);

        case 'pull':
            // Kéo ảnh đã optimize về từ QUIC.cloud
            do_action('litespeed_img_optm_pull');
            return xclipse_ok(['ok' => true, 'action' => 'pull']);

        case 'revert':
            // Hoàn tác — xoá ảnh optimize, dùng lại ảnh gốc
            do_action('litespeed_img_optm_revert');
            return xclipse_ok(['ok' => true, 'action' => 'revert']);

        case 'enable_webp':
            $conf['img-webp'] = true;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_purge_all');
            return xclipse_ok(['ok' => true, 'webp' => true]);

        case 'disable_webp':
            $conf['img-webp'] = false;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_purge_all');
            return xclipse_ok(['ok' => true, 'webp' => false]);

        case 'enable_lazyload':
            $conf['optm-lazyload_img'] = true;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_purge_all');
            return xclipse_ok(['ok' => true, 'lazyload' => true]);

        case 'disable_lazyload':
            $conf['optm-lazyload_img'] = false;
            update_option('litespeed.conf', $conf);
            do_action('litespeed_purge_all');
            return xclipse_ok(['ok' => true, 'lazyload' => false]);

        case 'enable_avif':
            $conf['img-avif'] = true;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'avif' => true]);

        case 'disable_avif':
            $conf['img-avif'] = false;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'avif' => false]);

        default:
            return xclipse_err('bad_action', 'action phải là: request | pull | revert | enable_webp | disable_webp | enable_lazyload | disable_lazyload | enable_avif | disable_avif', 400);
    }
}

function xclipse_lsc_crawl_status() {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $conf = get_option('litespeed.conf', []);

    // Lấy trạng thái crawler từ option
    $crawl_status = get_option('litespeed-crawler-status', []);
    $crawl_map    = get_option('litespeed-crawler-map', []);

    return xclipse_ok([
        'crawler_enabled'   => (bool)($conf['crawler-enabled'] ?? false),
        'crawl_interval'    => (int)($conf['crawler-run_interval'] ?? 500),
        'crawl_timeout'     => (int)($conf['crawler-run_duration'] ?? 400),
        'crawl_threads'     => (int)($conf['crawler-threads'] ?? 3),
        'crawl_delay'       => (int)($conf['crawler-usleep'] ?? 500),
        'crawl_role_sitemaps'  => (bool)($conf['crawler-sitemap'] ?? false),
        'sitemap_url'          => $conf['crawler-sitemap_url'] ?? null,
        'last_crawl'        => $crawl_status['last_start'] ?? null,
        'total_crawled'     => (int)($crawl_status['done'] ?? 0),
        'total_urls'        => count($crawl_map),
    ]);
}

function xclipse_lsc_crawl_action(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_key($b['action'] ?? 'start');
    $conf   = get_option('litespeed.conf', []);

    switch ($action) {
        case 'start':
            do_action('litespeed_crawler_start');
            // Nếu có class Crawler
            if (class_exists('\LiteSpeed\Crawler')) {
                \LiteSpeed\Crawler::get_instance()->start_crawl();
            }
            return xclipse_ok(['ok' => true, 'action' => 'start', 'time' => current_time('mysql')]);

        case 'stop':
            do_action('litespeed_crawler_stop');
            update_option('litespeed-crawler-status', array_merge(
                (array)get_option('litespeed-crawler-status', []),
                ['stop' => true]
            ));
            return xclipse_ok(['ok' => true, 'action' => 'stop']);

        case 'reset':
            update_option('litespeed-crawler-map', []);
            update_option('litespeed-crawler-status', []);
            do_action('litespeed_crawler_reset');
            return xclipse_ok(['ok' => true, 'action' => 'reset']);

        case 'enable':
            $conf['crawler-enabled'] = true;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'crawler' => true]);

        case 'disable':
            $conf['crawler-enabled'] = false;
            update_option('litespeed.conf', $conf);
            return xclipse_ok(['ok' => true, 'crawler' => false]);

        default:
            return xclipse_err('bad_action', 'action phải là: start | stop | reset | enable | disable', 400);
    }
}

function xclipse_lsc_settings_get(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $group = sanitize_key($r['group'] ?? 'cache');
    $conf  = get_option('litespeed.conf', []);
    if (!is_array($conf)) return xclipse_ok(['group' => $group, 'settings' => []]);

    // Prefix map
    $prefix_map = [
        'cache'   => ['cache-'],
        'optm'    => ['optm-'],
        'img'     => ['img-'],
        'cdn'     => ['cdn-'],
        'object'  => ['object-'],
        'esi'     => ['esi-'],
        'crawler' => ['crawler-'],
        'debug'   => ['debug-'],
        'security'=> ['sec-'],
        'all'     => [''],
    ];

    $prefixes = $prefix_map[$group] ?? $prefix_map['cache'];
    $out = [];
    foreach ($conf as $k => $v) {
        foreach ($prefixes as $px) {
            if ($px === '' || str_starts_with($k, $px)) {
                $out[$k] = $v;
                break;
            }
        }
    }
    return xclipse_ok(['version' => LSCWP_V, 'group' => $group, 'settings' => $out]);
}

function xclipse_lsc_settings_set(WP_REST_Request $r) {
    if (($e = xclipse_lsc_check()) !== true) return $e;
    $b        = $r->get_json_params() ?? [];
    $incoming = (array)($b['settings'] ?? []);
    if (empty($incoming)) return xclipse_err('empty', 'settings không được rỗng', 400);

    $conf = get_option('litespeed.conf', []);
    if (!is_array($conf)) $conf = [];

    $updated = [];
    foreach ($incoming as $k => $v) {
        $k = sanitize_key($k);
        // Cho phép bool, int, string, array
        if (is_bool($v) || is_numeric($v)) {
            $conf[$k] = $v;
        } elseif (is_array($v)) {
            $conf[$k] = array_map('sanitize_text_field', array_map('strval', $v));
        } else {
            $conf[$k] = sanitize_text_field((string)$v);
        }
        $updated[] = $k;
        // Notify LiteSpeed via action
        do_action('litespeed_setting_update', $k, $conf[$k]);
    }

    update_option('litespeed.conf', $conf);

    // Purge cache nếu thay đổi cache settings
    $needs_purge = array_filter($updated, fn($k) => str_starts_with($k, 'cache-') || str_starts_with($k, 'optm-'));
    if (!empty($needs_purge)) {
        do_action('litespeed_purge_all');
    }

    return xclipse_ok(['ok' => true, 'updated' => $updated, 'purged' => !empty($needs_purge)]);
}

function xclipse_lsc_heartbeat() {
    $url  = home_url('/');
    $resp = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false, 'redirection' => 0]);
    if (is_wp_error($resp)) return $resp;
    $headers = wp_remote_retrieve_headers($resp)->getAll();
    $ls_keys = array_filter($headers, fn($k) => str_starts_with($k, 'x-litespeed'), ARRAY_FILTER_USE_KEY);
    return xclipse_ok([
        'url'        => $url,
        'http_code'  => wp_remote_retrieve_response_code($resp),
        'cache_hit'  => strtolower($headers['x-litespeed-cache'] ?? '') === 'hit',
        'ls_headers' => $ls_keys,
    ]);
}