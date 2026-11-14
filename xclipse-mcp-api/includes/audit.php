<?php
/** Audit tools + fix functions */
defined('ABSPATH') || exit;

// AUDIT TOOLS — v3.4.0
// Phục vụ kiểm tra toàn diện website: SEO, performance, security, content

// ─── SEO AUDIT ──────────────────────────────────────────────────────────────

function xclipse_audit_seo_issues(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'all');
    $per_page  = min((int)($r['per_page'] ?? 200), 500);
    $min_score = (int)($r['min_score'] ?? 50);

    $pt_in = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";
    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_status, post_content, post_date
         FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in})
         ORDER BY post_date DESC LIMIT {$per_page}"
    );

    $issues = [];
    foreach ($posts as $p) {
        $pid   = (int)$p->ID;
        $title = get_post_meta($pid, 'rank_math_title', true);
        $desc  = get_post_meta($pid, 'rank_math_description', true);
        $kw    = get_post_meta($pid, 'rank_math_focus_keyword', true);
        $score = (int)get_post_meta($pid, 'rank_math_seo_score', true);
        $thumb = (int)get_post_thumbnail_id($pid);
        $words = str_word_count(wp_strip_all_tags($p->post_content));
        $tlen  = mb_strlen($title);
        $dlen  = mb_strlen($desc);

        $post_issues = [];
        if (!$kw)              $post_issues[] = 'no_focus_kw';
        if (!$title)           $post_issues[] = 'no_seo_title';
        if (!$desc)            $post_issues[] = 'no_meta_desc';
        if ($title && $tlen < 30)  $post_issues[] = "title_too_short({$tlen})";
        if ($title && $tlen > 70)  $post_issues[] = "title_too_long({$tlen})";
        if ($desc && $dlen < 70)   $post_issues[] = "desc_too_short({$dlen})";
        if ($desc && $dlen > 160)  $post_issues[] = "desc_too_long({$dlen})";
        if ($score > 0 && $score < $min_score) $post_issues[] = "low_score({$score})";
        if (!$thumb)           $post_issues[] = 'no_featured_image';
        if ($words < 300)      $post_issues[] = "thin_content({$words}w)";

        if (!empty($post_issues)) {
            $issues[] = [
                'id'        => $pid,
                'title'     => $p->post_title,
                'type'      => $p->post_type,
                'url'       => get_permalink($pid),
                'score'     => $score ?: null,
                'words'     => $words,
                'issues'    => $post_issues,
                'issue_cnt' => count($post_issues),
            ];
        }
    }

    usort($issues, fn($a, $b) => $b['issue_cnt'] - $a['issue_cnt']);

    return xclipse_ok([
        'total_scanned' => count($posts),
        'total_issues'  => count($issues),
        'issues'        => $issues,
    ]);
}

function xclipse_audit_duplicate_meta(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'all');
    $field     = sanitize_text_field($r['field'] ?? 'both');
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $dupes = ['titles' => [], 'descs' => []];

    if (in_array($field, ['title', 'both'])) {
        $rows = $wpdb->get_results(
            "SELECT m.meta_value val, GROUP_CONCAT(p.ID ORDER BY p.ID) ids, GROUP_CONCAT(p.post_title ORDER BY p.ID SEPARATOR '|||') titles
             FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID=m.post_id
             WHERE m.meta_key='rank_math_title' AND m.meta_value!=''
               AND p.post_status='publish' AND p.post_type IN ({$pt_in})
             GROUP BY m.meta_value HAVING COUNT(*)>1"
        );
        foreach ($rows as $row) {
            $ids = explode(',', $row->ids);
            $dupes['titles'][] = [
                'value'  => $row->val,
                'count'  => count($ids),
                'post_ids' => array_map('intval', $ids),
                'post_titles' => explode('|||', $row->titles),
            ];
        }
    }

    if (in_array($field, ['desc', 'both'])) {
        $rows = $wpdb->get_results(
            "SELECT m.meta_value val, GROUP_CONCAT(p.ID ORDER BY p.ID) ids, GROUP_CONCAT(p.post_title ORDER BY p.ID SEPARATOR '|||') titles
             FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID=m.post_id
             WHERE m.meta_key='rank_math_description' AND m.meta_value!=''
               AND p.post_status='publish' AND p.post_type IN ({$pt_in})
             GROUP BY m.meta_value HAVING COUNT(*)>1"
        );
        foreach ($rows as $row) {
            $ids = explode(',', $row->ids);
            $dupes['descs'][] = [
                'value'  => substr($row->val, 0, 100) . '...',
                'count'  => count($ids),
                'post_ids' => array_map('intval', $ids),
                'post_titles' => explode('|||', $row->titles),
            ];
        }
    }

    return xclipse_ok([
        'duplicate_titles' => count($dupes['titles']),
        'duplicate_descs'  => count($dupes['descs']),
        'data'             => $dupes,
    ]);
}

function xclipse_audit_orphan_pages(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'post');
    $per_page  = min((int)($r['per_page'] ?? 500), 1000);
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    // Lấy tất cả bài publish
    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_type FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in}) LIMIT {$per_page}"
    );
    if (empty($posts)) return xclipse_ok(['orphans' => [], 'count' => 0]);

    // Map permalink → ID
    $url_to_id = [];
    foreach ($posts as $p) {
        $url = get_permalink((int)$p->ID);
        $path = parse_url($url, PHP_URL_PATH);
        $url_to_id[$path] = (int)$p->ID;
    }

    // Đếm inbound links bằng cách quét post_content của tất cả bài
    $inbound = array_fill_keys(array_column($posts, 'ID'), 0);
    $all_content = $wpdb->get_results(
        "SELECT ID, post_content FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ('post','page','product') LIMIT 2000"
    );
    $home = home_url();
    foreach ($all_content as $c) {
        if (empty($c->post_content)) continue;
        if (!preg_match_all('/href=["\']([^"\']+)["\']/', $c->post_content, $m)) continue;
        foreach ($m[1] as $href) {
            // Normalize
            if (str_starts_with($href, $home)) $path = parse_url($href, PHP_URL_PATH);
            elseif (str_starts_with($href, '/')) $path = $href;
            else continue;
            $path = rtrim($path, '/') . '/';
            if (isset($url_to_id[$path]) && $url_to_id[$path] !== (int)$c->ID) {
                $inbound[$url_to_id[$path]]++;
            }
        }
    }

    // Lọc orphan (inbound = 0)
    $orphans = [];
    foreach ($posts as $p) {
        $pid = (int)$p->ID;
        if (($inbound[$pid] ?? 0) === 0) {
            $orphans[] = [
                'id'       => $pid,
                'title'    => $p->post_title,
                'type'     => $p->post_type,
                'url'      => get_permalink($pid),
                'inbound'  => 0,
            ];
        }
    }

    return xclipse_ok(['count' => count($orphans), 'orphans' => $orphans]);
}

function xclipse_audit_internal_links(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'all');
    $per_page  = min((int)($r['per_page'] ?? 100), 500);
    $sort      = sanitize_text_field($r['sort'] ?? 'least_linked');
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in}) LIMIT 1000"
    );
    if (empty($posts)) return xclipse_ok(['items' => []]);

    $home = home_url();
    $url_to_id = [];
    foreach ($posts as $p) {
        $path = rtrim(parse_url(get_permalink((int)$p->ID), PHP_URL_PATH), '/') . '/';
        $url_to_id[$path] = (int)$p->ID;
    }

    $data = [];
    foreach ($posts as $p) {
        $pid = (int)$p->ID;
        $outbound = 0; $internal_out = 0; $external_out = 0;
        $linked_to = [];
        if (!empty($p->post_content) && preg_match_all('/href=["\']([^"\']+)["\']/', $p->post_content, $m)) {
            foreach ($m[1] as $href) {
                $outbound++;
                if (str_starts_with($href, $home) || str_starts_with($href, '/')) {
                    $internal_out++;
                    $path = rtrim(parse_url($href, PHP_URL_PATH), '/') . '/';
                    if (isset($url_to_id[$path]) && $url_to_id[$path] !== $pid)
                        $linked_to[] = $url_to_id[$path];
                } else {
                    $external_out++;
                }
            }
        }
        $data[$pid] = [
            'id'           => $pid,
            'title'        => $p->post_title,
            'type'         => $p->post_type,
            'url'          => get_permalink($pid),
            'outbound_internal' => $internal_out,
            'outbound_external' => $external_out,
            'inbound'      => 0,
            'links_to'     => array_values(array_unique($linked_to)),
        ];
    }

    // Tính inbound
    foreach ($data as $pid => $d) {
        foreach ($d['links_to'] as $target_id) {
            if (isset($data[$target_id])) $data[$target_id]['inbound']++;
        }
    }

    $items = array_values($data);
    if ($sort === 'inbound') {
        usort($items, fn($a,$b) => $b['inbound'] - $a['inbound']);
    } elseif ($sort === 'outbound') {
        usort($items, fn($a,$b) => $b['outbound_internal'] - $a['outbound_internal']);
    } else {
        usort($items, fn($a,$b) => $a['inbound'] - $b['inbound']);
    }

    return xclipse_ok(['count' => count($items), 'items' => array_slice($items, 0, $per_page)]);
}

// ─── IMAGE AUDIT ─────────────────────────────────────────────────────────────

function xclipse_audit_images(WP_REST_Request $r) {
    global $wpdb;
    $per_page = min((int)($r['per_page'] ?? 200), 500);
    $issue    = sanitize_text_field($r['issue'] ?? 'all');

    $images = $wpdb->get_results(
        "SELECT ID, post_title, post_excerpt, guid FROM {$wpdb->posts}
         WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'
         ORDER BY ID DESC LIMIT {$per_page}"
    );

    $results = []; $stats = ['no_alt' => 0, 'no_title' => 0, 'large' => 0, 'no_webp' => 0];
    foreach ($images as $img) {
        $id       = (int)$img->ID;
        $alt      = get_post_meta($id, '_wp_attachment_image_alt', true);
        $meta     = wp_get_attachment_metadata($id);
        $filesize = 0;
        if (!empty($meta['file'])) {
            $path = get_attached_file($id);
            $filesize = $path && file_exists($path) ? filesize($path) : 0;
        }
        $webp_done = (int)get_post_meta($id, '_litespeed_img_optm', true) === 2;
        $ext       = strtolower(pathinfo($img->guid, PATHINFO_EXTENSION));

        $img_issues = [];
        if (!$alt)                   $img_issues[] = 'no_alt';
        if (!$img->post_title)       $img_issues[] = 'no_title';
        if ($filesize > 200*1024)    $img_issues[] = 'large_' . round($filesize/1024) . 'kb';
        if (!$webp_done && in_array($ext, ['jpg','jpeg','png'])) $img_issues[] = 'no_webp';

        if ($issue !== 'all') {
            $filter = $issue === 'no_alt' ? ['no_alt'] : ($issue === 'no_title' ? ['no_title'] : ($issue === 'large' ? [] : []));
            $has_match = false;
            foreach ($img_issues as $i) {
                if ($issue === 'large' && str_starts_with($i, 'large_')) { $has_match = true; break; }
                if (in_array($i, $filter)) { $has_match = true; break; }
            }
            if (!$has_match) continue;
        }

        if (empty($img_issues)) continue;

        foreach (['no_alt','no_title'] as $k) if (in_array($k, $img_issues)) $stats[$k]++;
        if (array_filter($img_issues, fn($i) => str_starts_with($i,'large_'))) $stats['large']++;
        if (in_array('no_webp', $img_issues)) $stats['no_webp']++;

        $results[] = xclipse_compact([
            'id'       => $id,
            'title'    => $img->post_title,
            'alt'      => $alt ?: null,
            'url'      => $img->guid,
            'size_kb'  => $filesize ? round($filesize/1024) : null,
            'webp'     => $webp_done,
            'issues'   => $img_issues,
        ]);
    }

    return xclipse_ok(['stats' => $stats, 'count' => count($results), 'items' => $results]);
}

function xclipse_audit_images_in_content(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'all');
    $per_page  = min((int)($r['per_page'] ?? 100), 500);
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $posts = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in})
           AND post_content LIKE '%<img%' LIMIT {$per_page}"
    );

    $results = [];
    foreach ($posts as $p) {
        $pid     = (int)$p->ID;
        $content = get_post_field('post_content', $pid);
        if (!preg_match_all('/<img[^>]+>/i', $content, $matches)) continue;

        $no_alt = []; $empty_alt = [];
        foreach ($matches[0] as $tag) {
            preg_match('/src=["\']([^"\']+)["\']/', $tag, $src_m);
            $src = $src_m[1] ?? '';
            if (!preg_match('/alt=["\']([^"\']*)["\']/', $tag, $alt_m)) {
                $no_alt[] = $src;
            } elseif (trim($alt_m[1]) === '') {
                $empty_alt[] = $src;
            }
        }

        if ($no_alt || $empty_alt) {
            $results[] = [
                'id'         => $pid,
                'title'      => $p->post_title,
                'url'        => get_permalink($pid),
                'img_count'  => count($matches[0]),
                'no_alt'     => $no_alt,
                'empty_alt'  => $empty_alt,
                'issue_count'=> count($no_alt) + count($empty_alt),
            ];
        }
    }

    usort($results, fn($a,$b) => $b['issue_count'] - $a['issue_count']);
    return xclipse_ok(['posts_with_issues' => count($results), 'items' => $results]);
}

// ─── CONTENT AUDIT ───────────────────────────────────────────────────────────

function xclipse_audit_content(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'post');
    $per_page  = min((int)($r['per_page'] ?? 200), 500);
    $min_words = (int)($r['min_words'] ?? 500);
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_content, post_date, post_modified FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in})
         ORDER BY post_modified ASC LIMIT {$per_page}"
    );

    $results = []; $summary = ['thin' => 0, 'no_heading' => 0, 'no_internal_link' => 0, 'outdated' => 0, 'no_image' => 0];
    $home = home_url();
    foreach ($posts as $p) {
        $pid     = (int)$p->ID;
        $content = $p->post_content;
        $text    = wp_strip_all_tags($content);
        $words   = str_word_count($text);
        $days_old = (int)((time() - strtotime($p->post_modified)) / 86400);

        $issues = [];
        if ($words < 300)      $issues[] = "ultra_thin({$words}w)";
        elseif ($words < $min_words) $issues[] = "thin({$words}w)";
        if (!preg_match('/<h[23][^>]*>/i', $content)) $issues[] = 'no_h2_h3';
        if (!preg_match('/href=["\'][^"\']*' . preg_quote(parse_url($home, PHP_URL_HOST), '/') . '[^"\']*["\']/', $content) &&
            !preg_match('/href=["\']\/[^"\']+["\']/', $content)) $issues[] = 'no_internal_link';
        if ($days_old > 365)   $issues[] = "outdated({$days_old}d)";
        if (!has_post_thumbnail($pid) && !preg_match('/<img[^>]+>/i', $content)) $issues[] = 'no_image';

        if (!empty($issues)) {
            foreach (['no_h2_h3','no_internal_link'] as $k) if (in_array($k, $issues)) $summary[$k]++;
            if (array_filter($issues, fn($i) => str_contains($i,'thin'))) $summary['thin']++;
            if (array_filter($issues, fn($i) => str_contains($i,'outdated'))) $summary['outdated']++;
            if (in_array('no_image', $issues)) $summary['no_image']++;

            $results[] = [
                'id'       => $pid,
                'title'    => $p->post_title,
                'type'     => $post_type,
                'url'      => get_permalink($pid),
                'words'    => $words,
                'days_old' => $days_old,
                'modified' => substr($p->post_modified, 0, 10),
                'issues'   => $issues,
            ];
        }
    }

    usort($results, fn($a,$b) => count($b['issues']) - count($a['issues']));
    return xclipse_ok(['total_scanned' => count($posts), 'summary' => $summary, 'items' => $results]);
}

function xclipse_audit_broken_links(WP_REST_Request $r) {
    global $wpdb;
    $post_id  = (int)($r['post_id'] ?? 0);
    $per_page = min((int)($r['per_page'] ?? 5), 20);
    $timeout  = min((int)($r['timeout'] ?? 5), 10);
    $home     = home_url();
    $host     = parse_url($home, PHP_URL_HOST);

    if ($post_id) {
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID=%d LIMIT 1", $post_id
        ));
    } else {
        $pt = sanitize_text_field($r['post_type'] ?? 'post');
        $posts = $wpdb->get_results(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='{$pt}' AND post_content LIKE '%href=%'
             ORDER BY post_modified DESC LIMIT {$per_page}"
        );
    }

    $results = []; $checked = 0; $broken = 0;
    foreach ($posts as $p) {
        if (!preg_match_all('/href=["\']([^"\']+)["\']/', $p->post_content, $m)) continue;
        $links = array_unique($m[1]);
        $post_broken = [];
        $cnt = 0;
        foreach ($links as $url) {
            if ($cnt >= 20) break; // max 20 links/bài
            // Chỉ check external
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            if (str_contains($url, $host)) continue;
            $cnt++;
            $checked++;
            $resp = wp_remote_head($url, ['timeout' => $timeout, 'sslverify' => false, 'redirection' => 3]);
            $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
            if ($code === 0 || $code >= 400) {
                $broken++;
                $post_broken[] = ['url' => $url, 'code' => $code, 'error' => is_wp_error($resp) ? $resp->get_error_message() : null];
            }
        }
        if (!empty($post_broken)) {
            $results[] = [
                'post_id'    => (int)$p->ID,
                'post_title' => $p->post_title,
                'post_url'   => get_permalink((int)$p->ID),
                'broken'     => $post_broken,
            ];
        }
    }

    return xclipse_ok(['posts_checked' => count($posts), 'links_checked' => $checked, 'broken_count' => $broken, 'results' => $results]);
}

// ─── WORDPRESS HEALTH & SECURITY ─────────────────────────────────────────────

function xclipse_audit_wp_health(WP_REST_Request $r) {
    global $wpdb;

    // Plugins
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all_plugins    = get_plugins();
    $active_plugins = get_option('active_plugins', []);
    $plugin_info    = [];
    foreach ($all_plugins as $file => $data) {
        $active = in_array($file, $active_plugins);
        $plugin_info[] = [
            'name'    => $data['Name'],
            'version' => $data['Version'],
            'active'  => $active,
            'file'    => $file,
        ];
    }
    $inactive_plugins = array_filter($plugin_info, fn($p) => !$p['active']);

    // Memory
    $mem_limit   = ini_get('memory_limit');
    $mem_usage   = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

    // Upload dir permissions
    $upload_dir  = wp_upload_dir();
    $upload_writable = is_writable($upload_dir['basedir']);

    // Cron
    $crons       = _get_cron_array() ?: [];
    $cron_count  = 0; $overdue = [];
    foreach ($crons as $timestamp => $hooks) {
        $cron_count += count($hooks);
        if ($timestamp < time() - 3600) {
            foreach ($hooks as $hook => $data) $overdue[] = $hook;
        }
    }

    // DB size
    $db_size = $wpdb->get_var(
        "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) FROM information_schema.TABLES
         WHERE table_schema=DATABASE()"
    );

    // Options table bloat
    $options_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
    $autoload_size = $wpdb->get_var(
        "SELECT ROUND(SUM(LENGTH(option_value))/1024,1) FROM {$wpdb->options} WHERE autoload='yes'"
    );

    // Post revisions
    $revisions = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'");

    // Transients
    $transients = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%'"
    );

    $issues = [];
    if (version_compare(PHP_VERSION, '8.1', '<')) $issues[] = 'php_outdated(' . PHP_VERSION . ')';
    if (defined('WP_DEBUG') && WP_DEBUG) $issues[] = 'debug_mode_on';
    if (!$upload_writable) $issues[] = 'upload_not_writable';
    if ($revisions > 1000) $issues[] = "too_many_revisions({$revisions})";
    if ($transients > 500) $issues[] = "too_many_transients({$transients})";
    if (!empty($overdue)) $issues[] = 'overdue_crons(' . count($overdue) . ')';
    if ((float)$autoload_size > 1024) $issues[] = "large_autoload({$autoload_size}kb)";
    if (count($inactive_plugins) > 5) $issues[] = 'many_inactive_plugins(' . count($inactive_plugins) . ')';

    return xclipse_ok([
        'php'             => PHP_VERSION,
        'wp'              => get_bloginfo('version'),
        'memory_limit'    => $mem_limit,
        'memory_peak_mb'  => $mem_usage,
        'debug_mode'      => defined('WP_DEBUG') && WP_DEBUG,
        'upload_writable' => $upload_writable,
        'db_size_mb'      => (float)$db_size,
        'options_count'   => $options_count,
        'autoload_kb'     => (float)$autoload_size,
        'revisions'       => $revisions,
        'transients'      => $transients,
        'cron_jobs'       => $cron_count,
        'overdue_crons'   => $overdue,
        'plugins_total'   => count($all_plugins),
        'plugins_active'  => count($active_plugins),
        'plugins_inactive'=> array_values(array_map(fn($p) => $p['name'], $inactive_plugins)),
        'issues'          => $issues,
    ]);
}

function xclipse_audit_security(WP_REST_Request $r) {
    $home    = home_url();
    $issues  = []; $passed = [];

    // HTTPS
    if (str_starts_with($home, 'https://')) $passed[] = 'https_enabled';
    else $issues[] = 'no_https';

    // File editing
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) $passed[] = 'file_edit_disabled';
    else $issues[] = 'file_edit_enabled';

    // Debug display
    if (!defined('WP_DEBUG_DISPLAY') || !WP_DEBUG_DISPLAY) $passed[] = 'debug_display_off';
    else $issues[] = 'debug_display_on';

    // Default admin user
    $admin = get_user_by('login', 'admin');
    if (!$admin) $passed[] = 'no_default_admin';
    else $issues[] = 'default_admin_exists';

    // XML-RPC (check nhanh)
    $xmlrpc_resp = wp_remote_get($home . '/xmlrpc.php', ['timeout' => 5, 'sslverify' => false]);
    if (!is_wp_error($xmlrpc_resp)) {
        $code = wp_remote_retrieve_response_code($xmlrpc_resp);
        if ($code === 405 || str_contains(wp_remote_retrieve_body($xmlrpc_resp) ?? '', 'XML-RPC'))
            $issues[] = 'xmlrpc_accessible';
        else $passed[] = 'xmlrpc_blocked';
    }

    // readme.html
    $readme_resp = wp_remote_head($home . '/readme.html', ['timeout' => 5, 'sslverify' => false]);
    if (!is_wp_error($readme_resp) && wp_remote_retrieve_response_code($readme_resp) === 200)
        $issues[] = 'readme_accessible';
    else $passed[] = 'readme_hidden';

    // User enumeration
    $enum_resp = wp_remote_get($home . '/?author=1', ['timeout' => 5, 'sslverify' => false, 'redirection' => 0]);
    if (!is_wp_error($enum_resp)) {
        $loc = wp_remote_retrieve_header($enum_resp, 'location') ?? '';
        if (str_contains($loc, '/author/')) $issues[] = 'user_enumeration_exposed';
        else $passed[] = 'user_enumeration_blocked';
    }

    // Active users with admin role
    $admins = get_users(['role' => 'administrator', 'fields' => ['ID','user_login','user_email']]);
    $admin_list = array_map(fn($u) => ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email], $admins);

    // Check wp-config accessible from web
    $cfg_resp = wp_remote_head($home . '/wp-config.php', ['timeout' => 5, 'sslverify' => false]);
    if (!is_wp_error($cfg_resp) && wp_remote_retrieve_response_code($cfg_resp) === 200)
        $issues[] = 'wp_config_accessible';
    else $passed[] = 'wp_config_protected';

    return xclipse_ok([
        'score'       => round(count($passed) / max(count($passed) + count($issues), 1) * 100),
        'issues'      => $issues,
        'passed'      => $passed,
        'admins'      => $admin_list,
        'admin_count' => count($admins),
    ]);
}

function xclipse_audit_db_health(WP_REST_Request $r) {
    global $wpdb;

    // Kích thước bảng
    $tables = $wpdb->get_results(
        "SELECT table_name name,
                ROUND((data_length+index_length)/1024/1024,2) size_mb,
                table_rows rows_est
         FROM information_schema.TABLES
         WHERE table_schema=DATABASE() AND table_name LIKE '{$wpdb->prefix}%'
         ORDER BY (data_length+index_length) DESC"
    );

    // Revisions
    $revisions = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'");
    $rev_size  = $wpdb->get_var(
        "SELECT ROUND(SUM(LENGTH(post_content))/1024/1024,2) FROM {$wpdb->posts} WHERE post_type='revision'"
    );

    // Spam + trash comments
    $spam_comments  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='spam'");
    $trash_comments = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='trash'");

    // Expired transients
    $expired_transients = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
    );

    // Orphan postmeta
    $orphan_meta = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON pm.post_id=p.ID
         WHERE p.ID IS NULL"
    );

    // Auto-draft cũ hơn 7 ngày
    $old_autodraft = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status='auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    // Trash posts
    $trash_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='trash'");

    // Unused term relationships
    $orphan_terms = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
         WHERE tt.count=0 AND tt.taxonomy NOT IN ('nav_menu','link_category')"
    );

    $issues = [];
    if ($revisions > 500)          $issues[] = "too_many_revisions({$revisions})";
    if ($expired_transients > 100) $issues[] = "expired_transients({$expired_transients})";
    if ($orphan_meta > 1000)       $issues[] = "orphan_postmeta({$orphan_meta})";
    if ($spam_comments > 50)       $issues[] = "spam_comments({$spam_comments})";
    if ($old_autodraft > 20)       $issues[] = "old_autodrafts({$old_autodraft})";
    if ($trash_posts > 20)         $issues[] = "trash_posts({$trash_posts})";

    return xclipse_ok([
        'issues'             => $issues,
        'revisions'          => $revisions,
        'revisions_mb'       => (float)($rev_size ?? 0),
        'spam_comments'      => $spam_comments,
        'trash_comments'     => $trash_comments,
        'expired_transients' => $expired_transients,
        'orphan_postmeta'    => $orphan_meta,
        'old_autodrafts'     => $old_autodraft,
        'trash_posts'        => $trash_posts,
        'orphan_terms'       => $orphan_terms,
        'tables'             => array_map(fn($t) => ['name' => $t->name, 'size_mb' => (float)$t->size_mb, 'rows' => (int)$t->rows_est], $tables),
    ]);
}

function xclipse_audit_db_cleanup(WP_REST_Request $r) {
    global $wpdb;
    $b       = $r->get_json_params() ?? [];
    $actions = $b['actions'] ?? ['transients','spam','autodraft'];
    $results = [];

    if (in_array('revisions', $actions)) {
        $n = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type='revision'");
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE p.ID IS NULL");
        $results['revisions_deleted'] = $n;
    }
    if (in_array('transients', $actions)) {
        $n = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_%' AND (
                option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()
                OR option_name NOT LIKE '_transient_timeout_%'
                   AND NOT EXISTS (
                       SELECT 1 FROM (SELECT option_name FROM {$wpdb->options} WHERE option_name=CONCAT('_transient_timeout_',SUBSTRING(option_name,12))) t
                   )
             )"
        );
        // Simpler: delete expired timeout + orphan values
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
        );
        $results['transients_cleaned'] = $n;
    }
    if (in_array('spam', $actions)) {
        $n = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved='spam'");
        $results['spam_deleted'] = $n;
    }
    if (in_array('autodraft', $actions)) {
        $n = $wpdb->query(
            "DELETE FROM {$wpdb->posts}
             WHERE post_status='auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $results['autodrafts_deleted'] = $n;
    }
    if (in_array('trash_posts', $actions)) {
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='trash'");
        foreach ($ids as $id) wp_delete_post((int)$id, true);
        $results['trash_posts_deleted'] = count($ids);
    }
    if (in_array('orphan_meta', $actions)) {
        $n = $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE p.ID IS NULL"
        );
        $results['orphan_meta_deleted'] = $n;
    }

    return xclipse_ok(['ok' => true, 'actions' => $actions, 'results' => $results]);
}

function xclipse_audit_redirects_health(WP_REST_Request $r) {
    global $wpdb;
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rm', 'RankMath chưa cài', 501);
    $check_live = ($r['check_live'] ?? 'false') === 'true';

    $t  = $wpdb->prefix . 'rank_math_redirections';
    $ts = $wpdb->prefix . 'rank_math_redirection_sources';

    $redirects = $wpdb->get_results(
        "SELECT r.id, r.url_to, r.header_code, r.status, s.url_from
         FROM {$t} r
         JOIN {$ts} s ON s.redirection_id=r.id
         ORDER BY r.id DESC LIMIT 1000"
    );

    $url_to_map = []; // source → destination
    foreach ($redirects as $rd) {
        $url_to_map[trim($rd->url_from, '/')] = trim($rd->url_to, '/');
    }

    $issues = [];
    foreach ($redirects as $rd) {
        $from = trim($rd->url_from, '/');
        $to   = trim($rd->url_to, '/');

        // Loop
        if ($from === $to) {
            $issues[] = ['type' => 'loop', 'id' => (int)$rd->id, 'from' => $rd->url_from, 'to' => $rd->url_to];
            continue;
        }
        // Chain
        if (isset($url_to_map[$to])) {
            $issues[] = ['type' => 'chain', 'id' => (int)$rd->id, 'from' => $rd->url_from, 'to' => $rd->url_to, 'next' => '/' . $url_to_map[$to]];
        }
        // Inactive
        if ($rd->status !== 'active') {
            $issues[] = ['type' => 'inactive', 'id' => (int)$rd->id, 'from' => $rd->url_from, 'to' => $rd->url_to];
        }
    }

    // Ping live nếu yêu cầu (giới hạn 20)
    $live_broken = [];
    if ($check_live) {
        $home = home_url();
        $checked = 0;
        foreach ($redirects as $rd) {
            if ($checked >= 20) break;
            // Chỉ check destination là URL nội bộ
            $dest = $rd->url_to;
            if (!str_starts_with($dest, 'http')) $dest = $home . '/' . ltrim($dest, '/');
            $resp = wp_remote_head($dest, ['timeout' => 5, 'sslverify' => false]);
            $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
            if ($code === 404 || $code === 0) {
                $live_broken[] = ['id' => (int)$rd->id, 'from' => $rd->url_from, 'to' => $rd->url_to, 'dest_code' => $code];
            }
            $checked++;
        }
    }

    return xclipse_ok([
        'total_redirects' => count($redirects),
        'issues_count'    => count($issues),
        'issues'          => $issues,
        'live_broken'     => $live_broken,
        'checked_live'    => $check_live,
    ]);
}

function xclipse_audit_summary(WP_REST_Request $r) {
    // Fake request object cho các sub-calls
    $sub = new WP_REST_Request();
    $sub->set_param('per_page', 200);
    $sub->set_param('post_type', 'all');

    $seo     = xclipse_audit_seo_issues($sub);
    $content = (function() {
        $s = new WP_REST_Request(); $s->set_param('per_page', 200); $s->set_param('post_type', 'post');
        return xclipse_audit_content($s);
    })();
    $img     = (function() {
        $s = new WP_REST_Request(); $s->set_param('per_page', 200);
        return xclipse_audit_images($s);
    })();
    $db      = xclipse_audit_db_health(new WP_REST_Request());
    $wp      = xclipse_audit_wp_health(new WP_REST_Request());
    $sec     = xclipse_audit_security(new WP_REST_Request());
    $perf    = (function() {
        $s = new WP_REST_Request(); $s->set_param('per_page', 50); $s->set_param('post_type', 'post'); $s->set_param('days', 30);
        return xclipse_audit_post_performance($s);
    })();

    $extract = fn($resp) => $resp instanceof WP_REST_Response ? $resp->get_data()['data'] ?? [] : [];

    $seo_d  = $extract($seo);
    $cont_d = $extract($content);
    $img_d  = $extract($img);
    $db_d   = $extract($db);
    $wp_d   = $extract($wp);
    $sec_d  = $extract($sec);
    $perf_d = $extract($perf);

    return xclipse_ok([
        'generated_at' => current_time('mysql'),
        'scores' => [
            'security'     => (int)($sec_d['score'] ?? 0),
            'wp_issues'    => count($wp_d['issues'] ?? []),
            'seo_issues'   => (int)($seo_d['total_issues'] ?? 0),
            'content_issues'=> count($cont_d['items'] ?? []),
            'db_issues'    => count($db_d['issues'] ?? []),
            'img_no_alt'     => (int)($img_d['stats']['no_alt'] ?? 0),
        ],
        'seo' => [
            'scanned'   => $seo_d['total_scanned'] ?? 0,
            'issues'    => $seo_d['total_issues'] ?? 0,
            'top5'      => array_slice($seo_d['issues'] ?? [], 0, 5),
        ],
        'content' => [
            'thin'             => $cont_d['summary']['thin'] ?? 0,
            'no_heading'       => $cont_d['summary']['no_heading'] ?? 0,
            'no_internal_link' => $cont_d['summary']['no_internal_link'] ?? 0,
            'outdated'         => $cont_d['summary']['outdated'] ?? 0,
        ],
        'images'  => $img_d['stats'] ?? [],
        'db'      => ['issues' => $db_d['issues'] ?? [], 'revisions' => $db_d['revisions'] ?? 0, 'expired_transients' => $db_d['expired_transients'] ?? 0],
        'wp'      => ['issues' => $wp_d['issues'] ?? [], 'php' => $wp_d['php'] ?? null, 'wp' => $wp_d['wp'] ?? null],
        'security'=> ['issues' => $sec_d['issues'] ?? [], 'passed' => count($sec_d['passed'] ?? [])],
        'performance' => ['flag_summary' => $perf_d['flag_summary'] ?? []],
    ]);
}

// ============================================================
// FIX TOOLS v3.5.0 — Dựa trên báo cáo audit 2026-07-17
// ============================================================

// ── Fix Security ────────────────────────────────────────────
function xclipse_fix_security(WP_REST_Request $req) {
    $action  = sanitize_text_field($req->get_param('action') ?? '');
    $results = [];

    // Block XML-RPC via .htaccess
    if ($action === 'block_xmlrpc' || $action === 'all') {
        $htaccess = ABSPATH . '.htaccess';
        $block    = "\n# Block XML-RPC (XCLIPSE)\n<Files \"xmlrpc.php\">\n  Order Deny,Allow\n  Deny from all\n</Files>\n";
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            if (strpos($content, 'Block XML-RPC') === false) {
                file_put_contents($htaccess, $content . $block);
                $results['block_xmlrpc'] = 'done';
            } else {
                $results['block_xmlrpc'] = 'already_set';
            }
        } else {
            $results['block_xmlrpc'] = 'htaccess_not_found';
        }
    }

    // Delete readme.html + license.txt
    if ($action === 'delete_readme' || $action === 'all') {
        $files = [ABSPATH . 'readme.html', ABSPATH . 'license.txt', ABSPATH . 'README.md'];
        $deleted = [];
        foreach ($files as $f) {
            if (file_exists($f)) {
                unlink($f);
                $deleted[] = basename($f);
            }
        }
        $results['delete_readme'] = $deleted ?: 'files_not_found';
    }

    // Block user enumeration via .htaccess redirect
    if ($action === 'block_user_enum' || $action === 'all') {
        $htaccess = ABSPATH . '.htaccess';
        $block_enum = "\n# Block User Enumeration (XCLIPSE)\nRewriteCond %{QUERY_STRING} ^author=\\d\nRewriteRule .* - [F,L]\n";
        if (file_exists($htaccess)) {
            $content = file_get_contents($htaccess);
            if (strpos($content, 'Block User Enumeration') === false) {
                // Inject after RewriteEngine On
                $new = str_replace('RewriteEngine On', 'RewriteEngine On' . $block_enum, $content, $count);
                if ($count) {
                    file_put_contents($htaccess, $new);
                    $results['block_user_enum'] = 'done_htaccess';
                } else {
                    file_put_contents($htaccess, $content . $block_enum);
                    $results['block_user_enum'] = 'done_appended';
                }
            } else {
                $results['block_user_enum'] = 'already_set';
            }
        } else {
            $results['block_user_enum'] = 'htaccess_not_found';
        }
    }

    // List admin users (for review — không tự xóa)
    if ($action === 'list_admins' || $action === 'all') {
        $admins = get_users(['role' => 'administrator', 'fields' => ['ID', 'user_login', 'user_email', 'display_name']]);
        $results['admins'] = array_map(fn($u) => ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'name' => $u->display_name], $admins);
        $results['admin_count'] = count($admins);
    }

    return xclipse_ok(['action' => $action, 'results' => $results]);
}

// ── Fix Alt Text Bulk ────────────────────────────────────────
function xclipse_fix_alt_text(WP_REST_Request $req) {
    $ids     = $req->get_param('ids');          // array of attachment IDs, or null = all missing
    $pattern = sanitize_text_field($req->get_param('pattern') ?? '');
    $dry_run = (bool)($req->get_param('dry_run') ?? false);
    $limit   = min((int)($req->get_param('limit') ?? 50), 200);

    if (empty($ids)) {
        // Lấy tất cả attachment không có alt text
        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
                ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
            ],
        ]);
        $ids = wp_list_pluck($query->posts, 'ID');
    }

    $updated = [];
    $skipped = [];

    foreach ((array)$ids as $att_id) {
        $att_id = (int)$att_id;
        $post   = get_post($att_id);
        if (!$post || $post->post_type !== 'attachment') {
            $skipped[] = $att_id;
            continue;
        }

        // Xây dựng alt text
        if ($pattern) {
            $alt = $pattern;
        } else {
            // Auto: dùng title của attachment, fallback filename
            $title = $post->post_title;
            $alt   = $title ?: preg_replace('/[-_]/', ' ', pathinfo($post->post_name, PATHINFO_FILENAME));
        }
        $alt = sanitize_text_field($alt);

        if (!$dry_run) {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }
        $updated[] = ['id' => $att_id, 'title' => $post->post_title, 'alt' => $alt];
    }

    return xclipse_ok([
        'dry_run'      => $dry_run,
        'updated_count'=> count($updated),
        'skipped_count'=> count($skipped),
        'updated'      => $updated,
        'skipped'      => $skipped,
    ]);
}

// ── Fix Single Alt Text ──────────────────────────────────────
function xclipse_fix_alt_text_single(WP_REST_Request $req) {
    $att_id = (int)($req->get_param('id') ?? 0);
    $alt    = sanitize_text_field($req->get_param('alt') ?? '');

    if (!$att_id) return xclipse_err('Missing id');

    $post = get_post($att_id);
    if (!$post || $post->post_type !== 'attachment') return xclipse_err("Attachment {$att_id} not found");

    update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
    $verified = get_post_meta($att_id, '_wp_attachment_image_alt', true);

    return xclipse_ok(['id' => $att_id, 'alt' => $verified]);
}

// ── Fix SEO Bulk (focus keyword + meta desc pattern) ────────
function xclipse_fix_seo_bulk(WP_REST_Request $req) {
    $post_type   = sanitize_text_field($req->get_param('post_type') ?? 'product');
    $field       = sanitize_text_field($req->get_param('field') ?? '');   // focus_kw|seo_desc|seo_title
    $value       = sanitize_text_field($req->get_param('value') ?? '');   // static value, or use_title pattern
    $use_title   = (bool)($req->get_param('use_title') ?? false);         // derive from post title
    $only_empty  = (bool)($req->get_param('only_empty') ?? true);         // only update empty fields
    $limit       = min((int)($req->get_param('limit') ?? 50), 500);
    $dry_run     = (bool)($req->get_param('dry_run') ?? false);
    $ids         = $req->get_param('ids');

    $allowed_fields = ['focus_kw', 'seo_title', 'seo_desc', 'canonical'];
    if (!in_array($field, $allowed_fields, true)) {
        return xclipse_err('field must be one of: ' . implode(', ', $allowed_fields));
    }

    $meta_key_map = [
        'focus_kw'  => 'rank_math_focus_keyword',
        'seo_title' => 'rank_math_title',
        'seo_desc'  => 'rank_math_description',
        'canonical' => 'rank_math_canonical_url',
    ];
    $meta_key = $meta_key_map[$field];

    if (!empty($ids)) {
        $posts = array_map('get_post', (array)$ids);
        $posts = array_filter($posts);
    } else {
        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
        ];
        if ($only_empty) {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => $meta_key, 'compare' => 'NOT EXISTS'],
                ['key' => $meta_key, 'value' => '', 'compare' => '='],
            ];
        }
        $q     = new WP_Query($args);
        $posts = $q->posts;
    }

    $updated = [];
    foreach ($posts as $p) {
        if ($use_title) {
            // Focus keyword: lowercase title → take first 60 chars
            if ($field === 'focus_kw') {
                $final_val = mb_strtolower(trim($p->post_title));
                $final_val = mb_substr($final_val, 0, 60);
            } elseif ($field === 'seo_title') {
                $final_val = $p->post_title . ' - Xclipse';
            } elseif ($field === 'seo_desc') {
                // Strip tags from post_excerpt or first 155 chars of content
                $excerpt  = $p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 25, '...');
                $final_val = mb_substr(sanitize_text_field($excerpt), 0, 155);
            } else {
                $final_val = $value;
            }
        } else {
            $final_val = $value;
        }

        if (empty($final_val)) {
            continue;
        }

        if (!$dry_run) {
            delete_post_meta($p->ID, $meta_key);
            update_post_meta($p->ID, $meta_key, $final_val);
            wp_cache_delete("rank_math_meta_{$p->ID}", 'rank_math');
        }
        $updated[] = ['id' => $p->ID, 'title' => $p->post_title, 'field' => $field, 'value' => $final_val];
    }

    return xclipse_ok([
        'dry_run'       => $dry_run,
        'post_type'     => $post_type,
        'field'         => $field,
        'updated_count' => count($updated),
        'updated'       => $updated,
    ]);
}

// ── Fix Internal Links (append link block to content) ───────
function xclipse_fix_internal_links(WP_REST_Request $req) {
    $post_id    = (int)($req->get_param('post_id') ?? 0);
    $links      = $req->get_param('links') ?? [];   // [['url'=>'...','text'=>'...'], ...]
    $heading    = sanitize_text_field($req->get_param('heading') ?? 'Xem thêm');
    $dry_run    = (bool)($req->get_param('dry_run') ?? false);

    if (!$post_id) return xclipse_err('Missing post_id');
    if (empty($links)) return xclipse_err('Missing links array');

    $post = get_post($post_id);
    if (!$post) return xclipse_err("Post {$post_id} not found");

    // Xây dựng HTML block
    $html = "\n\n<div class=\"xclipse-related-links\"><h3>" . esc_html($heading) . "</h3><ul>";
    foreach ((array)$links as $lnk) {
        $url  = esc_url($lnk['url'] ?? '');
        $text = esc_html($lnk['text'] ?? $lnk['url'] ?? '');
        if ($url) $html .= "<li><a href=\"{$url}\" rel=\"noopener\">{$text}</a></li>";
    }
    $html .= "</ul></div>";

    $new_content = $post->post_content . $html;

    if (!$dry_run) {
        wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
        // Purge LiteSpeed cache for this URL
        if (function_exists('litespeed_purge_post')) {
            litespeed_purge_post($post_id);
        }
    }

    return xclipse_ok([
        'dry_run'     => $dry_run,
        'post_id'     => $post_id,
        'title'       => $post->post_title,
        'links_added' => count($links),
        'html_preview'=> $html,
    ]);
}

// ── Fix Internal Links Bulk ──────────────────────────────────
function xclipse_fix_internal_links_bulk(WP_REST_Request $req) {
    $post_type  = sanitize_text_field($req->get_param('post_type') ?? 'post');
    $limit      = min((int)($req->get_param('limit') ?? 20), 100);
    $dry_run    = (bool)($req->get_param('dry_run') ?? false);
    $links      = $req->get_param('links') ?? [];   // links to append to ALL matched posts
    $heading    = sanitize_text_field($req->get_param('heading') ?? 'Xem thêm');
    $only_no_links = (bool)($req->get_param('only_no_links') ?? true);

    if (empty($links)) return xclipse_err('Missing links array');

    // Lấy posts không có internal links trong content
    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
    ];
    $q = new WP_Query($args);

    $html_block = "\n\n<div class=\"xclipse-related-links\"><h3>" . esc_html($heading) . "</h3><ul>";
    foreach ((array)$links as $lnk) {
        $url  = esc_url($lnk['url'] ?? '');
        $text = esc_html($lnk['text'] ?? $lnk['url'] ?? '');
        if ($url) $html_block .= "<li><a href=\"{$url}\" rel=\"noopener\">{$text}</a></li>";
    }
    $html_block .= "</ul></div>";

    $updated = [];
    $skipped = [];
    foreach ($q->posts as $p) {
        // Nếu only_no_links: skip post đã có <a href
        if ($only_no_links && preg_match('/<a\s+[^>]*href/i', $p->post_content)) {
            $skipped[] = ['id' => $p->ID, 'reason' => 'already_has_links'];
            continue;
        }
        // Skip nếu đã có xclipse-related-links
        if (strpos($p->post_content, 'xclipse-related-links') !== false) {
            $skipped[] = ['id' => $p->ID, 'reason' => 'already_appended'];
            continue;
        }

        if (!$dry_run) {
            wp_update_post(['ID' => $p->ID, 'post_content' => $p->post_content . $html_block]);
            if (function_exists('litespeed_purge_post')) litespeed_purge_post($p->ID);
        }
        $updated[] = ['id' => $p->ID, 'title' => $p->post_title];
    }

    return xclipse_ok([
        'dry_run'       => $dry_run,
        'post_type'     => $post_type,
        'updated_count' => count($updated),
        'skipped_count' => count($skipped),
        'updated'       => $updated,
        'skipped'       => $skipped,
    ]);
}

// ── Fix LiteSpeed Optimization ──────────────────────────────
function xclipse_fix_lsc_optimization(WP_REST_Request $req) {
    $preset = sanitize_text_field($req->get_param('preset') ?? 'recommended');
    // Các setting tối ưu theo báo cáo
    $presets = [
        'recommended' => [
            // Image Optimization
            'img-optm-auto'          => '1',
            'img-optm-webp'          => '1',
            'img-optm-lossless'      => '0',
            'img-optm-lazy'          => '1',
            // Cache
            'cache-browser'          => '1',
            // CSS/JS Optimization
            'optm-css_min'           => '1',
            'optm-js_min'            => '1',
            'optm-css_comb'          => '1',
            'optm-js_comb'           => '0',   // comb JS có thể break, tắt mặc định
            'optm-ccss_gen'          => '1',   // Critical CSS
            'optm-css_exc_jq'        => '1',
            // Page Speed
            'optm-dns_prefetch'      => site_url(),
        ],
        'image_only' => [
            'img-optm-auto'  => '1',
            'img-optm-webp'  => '1',
            'img-optm-lazy'  => '1',
        ],
        'performance' => [
            'optm-css_min'  => '1',
            'optm-js_min'   => '1',
            'optm-css_comb' => '1',
            'optm-ccss_gen' => '1',
            'cache-browser' => '1',
        ],
    ];

    if (!isset($presets[$preset])) {
        return xclipse_err('Unknown preset. Available: ' . implode(', ', array_keys($presets)));
    }

    $settings = $presets[$preset];
    $applied  = [];
    $failed   = [];

    foreach ($settings as $key => $val) {
        if (defined('LSCWP_V') && class_exists('\LiteSpeed\Conf')) {
            try {
                do_action('litespeed_conf_update_option', $key, $val);
                $applied[$key] = $val;
            } catch (\Throwable $e) {
                $failed[$key] = $e->getMessage();
            }
        } else {
            // Fallback: update directly in DB option array
            $opt = get_option('litespeed-settings', []);
            $opt[$key] = $val;
            update_option('litespeed-settings', $opt);
            $applied[$key] = $val;
        }
    }

    return xclipse_ok([
        'preset'  => $preset,
        'applied' => $applied,
        'failed'  => $failed,
    ]);
}

// ── Fix: Set Featured Image Bulk ─────────────────────────────
function xclipse_fix_featured_image(WP_REST_Request $req) {
    $post_type = sanitize_text_field($req->get_param('post_type') ?? 'post');
    $limit     = min((int)($req->get_param('limit') ?? 50), 200);
    $dry_run   = (bool)($req->get_param('dry_run') ?? false);
    $ids       = $req->get_param('ids');

    // Lấy posts không có featured image
    if (!empty($ids)) {
        $posts = array_map('get_post', (array)$ids);
        $posts = array_filter($posts);
    } else {
        $q = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS'],
                ['key' => '_thumbnail_id', 'value' => '', 'compare' => '='],
            ],
        ]);
        $posts = $q->posts;
    }

    $updated = [];
    foreach ($posts as $p) {
        // Tìm ảnh đầu tiên trong content
        preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $p->post_content, $img_match);
        $img_url = $img_match[1] ?? '';

        // Hoặc tìm attachment của post
        $att = get_posts([
            'post_type'      => 'attachment',
            'post_parent'    => $p->ID,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        $att_id = $att[0]->ID ?? 0;

        if (!$att_id && $img_url) {
            // Tìm attachment by URL
            $att_id = attachment_url_to_postid($img_url);
        }

        if ($att_id) {
            if (!$dry_run) {
                set_post_thumbnail($p->ID, $att_id);
            }
            $updated[] = ['post_id' => $p->ID, 'title' => $p->post_title, 'attachment_id' => $att_id];
        } else {
            $updated[] = ['post_id' => $p->ID, 'title' => $p->post_title, 'attachment_id' => null, 'status' => 'no_image_found'];
        }
    }

    return xclipse_ok([
        'dry_run'       => $dry_run,
        'post_type'     => $post_type,
        'processed'     => count($updated),
        'results'       => $updated,
    ]);
}

// ── Fix: Update Post Modified Date ───────────────────────────
function xclipse_fix_refresh_date(WP_REST_Request $req) {
    $post_id     = (int)($req->get_param('post_id') ?? 0);
    $ids         = $req->get_param('ids');
    $post_type   = sanitize_text_field($req->get_param('post_type') ?? '');
    $older_than  = (int)($req->get_param('older_than_days') ?? 365);
    $dry_run     = (bool)($req->get_param('dry_run') ?? false);

    $now_gmt = current_time('mysql', 1);
    $now_loc = current_time('mysql');

    $to_update = [];
    if ($post_id) {
        $to_update = [get_post($post_id)];
    } elseif (!empty($ids)) {
        $to_update = array_map('get_post', (array)$ids);
    } elseif ($post_type) {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$older_than} days"));
        $q = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'date_query'     => [['column' => 'post_modified', 'before' => $cutoff]],
        ]);
        $to_update = $q->posts;
    }

    $updated = [];
    foreach (array_filter($to_update) as $p) {
        if (!$dry_run) {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['post_modified' => $now_loc, 'post_modified_gmt' => $now_gmt],
                ['ID' => $p->ID],
                ['%s', '%s'],
                ['%d']
            );
            clean_post_cache($p->ID);
        }
        $updated[] = ['id' => $p->ID, 'title' => $p->post_title, 'old_modified' => $p->post_modified];
    }

    return xclipse_ok([
        'dry_run'       => $dry_run,
        'updated_count' => count($updated),
        'new_date'      => $now_loc,
        'updated'       => $updated,
    ]);
}

// ── Fix: Create Redirect ──────────────────────────────────────
// (Alias to existing rankmath_redirect_create but with bulk support)
function xclipse_fix_redirects_bulk(WP_REST_Request $req) {
    $redirects = $req->get_param('redirects') ?? [];
    // [['from'=>'/old-url','to'=>'/new-url','type'=>301], ...]

    if (empty($redirects)) return xclipse_err('Missing redirects array');

    if (!defined('RANK_MATH_VERSION')) {
        return xclipse_err('RankMath not active');
    }

    $results = [];
    foreach ((array)$redirects as $redir) {
        $from = sanitize_text_field($redir['from'] ?? '');
        $to   = sanitize_text_field($redir['to'] ?? '');
        $type = (int)($redir['type'] ?? 301);

        if (!$from || !$to) {
            $results[] = ['from' => $from, 'status' => 'skip_empty'];
            continue;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return xclipse_err('RankMath redirections table not found');
        }

        // Check duplicate
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE sources LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($from) . '%'
        ));

        if ($exists) {
            $results[] = ['from' => $from, 'status' => 'duplicate', 'existing_id' => $exists];
            continue;
        }

        $sources = maybe_serialize([['pattern' => $from, 'comparison' => 'exact']]);
        $id = $wpdb->insert($table, [
            'sources'     => $sources,
            'url_to'      => $to,
            'header_code' => $type,
            'hits'        => 0,
            'status'      => 'active',
            'created'     => current_time('mysql'),
            'updated'     => current_time('mysql'),
        ]);
        $results[] = ['from' => $from, 'to' => $to, 'type' => $type, 'status' => $id ? 'created' : 'error', 'id' => $wpdb->insert_id];
    }

    return xclipse_ok(['count' => count($results), 'results' => $results]);
}