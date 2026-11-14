<?php
/**
 * Diagnostic & File tools — v4.7.0
 * Tier 1: ajax_probe, rest_probe, php_info, error_log_search, cache_purge, cache_status
 * Tier 2: file_read, file_edit (with php -l gate + backup), healthcheck_troubleshoot
 * Tier 3: db_query (SELECT only), db_describe_table
 */
defined('ABSPATH') || exit;

// ─── Tier 1: Ajax / REST probe ───────────────────────────────────────────────

/**
 * POST /diag/ajax-probe
 * Gọi thẳng admin-ajax.php với action + params, trả về raw body + HTTP status.
 * Dùng để debug "spinner quay mãi" — đọc được JSON hay HTML lỗi chen vào.
 */
function xclipse_diag_ajax_probe(WP_REST_Request $r) {
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_text_field($b['action'] ?? '');
    $params = (array)($b['params'] ?? []);
    $method = strtoupper(sanitize_text_field($b['method'] ?? 'POST'));

    if (!$action) return xclipse_err('missing', 'action là bắt buộc', 400);

    $url  = admin_url('admin-ajax.php');
    $body = array_merge(['action' => $action], $params);

    $args = [
        'timeout'   => (int)($b['timeout'] ?? 15),
        'sslverify' => false,
        'cookies'   => [],
        // Gửi kèm nonce WP giả để bypass một số nonce-check cơ bản
        'headers'   => ['X-WP-Nonce' => wp_create_nonce('wp_rest')],
    ];

    if ($method === 'GET') {
        $url  = add_query_arg($body, $url);
        $resp = wp_remote_get($url, $args);
    } else {
        $args['body'] = $body;
        $resp         = wp_remote_post($url, $args);
    }

    if (is_wp_error($resp)) {
        return xclipse_err('request_failed', $resp->get_error_message(), 502);
    }

    $raw     = wp_remote_retrieve_body($resp);
    $code    = (int)wp_remote_retrieve_response_code($resp);
    $headers = wp_remote_retrieve_headers($resp)->getAll();
    $ct      = $headers['content-type'] ?? '';

    // Phát hiện HTML lỗi chen vào trước JSON
    $json_ok    = false;
    $json_data  = null;
    $json_pos   = strpos($raw, '{');
    $array_pos  = strpos($raw, '[');
    $first_json = false;
    if ($json_pos !== false) $first_json = $json_pos;
    if ($array_pos !== false && ($first_json === false || $array_pos < $first_json)) $first_json = $array_pos;

    $prefix_html = '';
    $clean_body  = $raw;
    if ($first_json !== false && $first_json > 0) {
        $prefix_html = substr($raw, 0, $first_json);
        $clean_body  = substr($raw, $first_json);
    }

    if ($first_json !== false) {
        $decoded = json_decode($clean_body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json_ok   = true;
            $json_data = $decoded;
        }
    }

    return xclipse_ok([
        'action'       => $action,
        'method'       => $method,
        'http_status'  => $code,
        'content_type' => $ct,
        'json_valid'   => $json_ok,
        'json_data'    => $json_data,
        'prefix_html'  => $prefix_html ? trim($prefix_html) : null,  // HTML lỗi nếu có
        'raw_length'   => strlen($raw),
        'raw_preview'  => mb_substr($raw, 0, 500),
    ]);
}

/**
 * POST /diag/rest-probe
 * Gọi một REST endpoint nội bộ với quyền admin, trả về body + status.
 */
function xclipse_diag_rest_probe(WP_REST_Request $r) {
    $b      = $r->get_json_params() ?? [];
    $route  = sanitize_text_field($b['route'] ?? '');
    $method = strtoupper(sanitize_text_field($b['method'] ?? 'GET'));
    $body   = (array)($b['body'] ?? []);

    if (!$route) return xclipse_err('missing', 'route là bắt buộc (vd: /wp/v2/posts)', 400);

    // Gọi nội bộ qua WP REST Dispatch
    $req = new WP_REST_Request($method, $route);
    if (!empty($body)) {
        $req->set_body(wp_json_encode($body));
        $req->set_header('Content-Type', 'application/json');
    }
    // Gắn user admin để bypass permission
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => ['ID']]);
    if (!empty($admin)) {
        wp_set_current_user($admin[0]->ID);
    }

    $dispatch = rest_do_request($req);
    $data     = rest_get_server()->response_to_data($dispatch, false);

    return xclipse_ok([
        'route'      => $route,
        'method'     => $method,
        'http_status'=> $dispatch->get_status(),
        'data'       => $data,
    ]);
}

// ─── Tier 1: PHP Info ────────────────────────────────────────────────────────

/**
 * GET /diag/php-info
 * Trả về PHP config quan trọng: display_errors, error_reporting, limits, opcache.
 */
function xclipse_diag_php_info() {
    $opcache = [];
    if (function_exists('opcache_get_status')) {
        $s = @opcache_get_status(false);
        if ($s) {
            $opcache = [
                'enabled'       => $s['opcache_enabled'] ?? false,
                'memory_used'   => round(($s['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 1) . 'MB',
                'memory_free'   => round(($s['memory_usage']['free_memory'] ?? 0) / 1024 / 1024, 1) . 'MB',
                'hit_rate'      => round(($s['opcache_statistics']['opcache_hit_rate'] ?? 0), 2) . '%',
                'cached_scripts'=> $s['opcache_statistics']['num_cached_scripts'] ?? 0,
            ];
        }
    }

    return xclipse_ok([
        'php_version'       => PHP_VERSION,
        'display_errors'    => ini_get('display_errors'),
        'error_reporting'   => ini_get('error_reporting'),
        'log_errors'        => ini_get('log_errors'),
        'error_log'         => ini_get('error_log') ?: 'not set',
        'max_execution_time'=> ini_get('max_execution_time'),
        'memory_limit'      => ini_get('memory_limit'),
        'post_max_size'     => ini_get('post_max_size'),
        'upload_max_filesize'=> ini_get('upload_max_filesize'),
        'max_input_vars'    => ini_get('max_input_vars'),
        'disable_functions' => ini_get('disable_functions'),
        'wp_debug'          => defined('WP_DEBUG') ? WP_DEBUG : false,
        'wp_debug_log'      => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false,
        'wp_debug_display'  => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : false,
        'opcache'           => $opcache ?: null,
        'abspath'           => ABSPATH,
        'wp_content_dir'    => WP_CONTENT_DIR,
    ]);
}

// ─── Tier 1: Error log search ────────────────────────────────────────────────

/**
 * GET /diag/error-log-search
 * Tìm trong error log theo pattern và/hoặc since timestamp.
 * Params: pattern (regex/text), since (Y-m-d H:i:s hoặc -Nh/-Nd), lines (max kết quả)
 */
function xclipse_diag_error_log_search(WP_REST_Request $r) {
    $pattern = sanitize_text_field($r['pattern'] ?? '');
    $since   = sanitize_text_field($r['since']   ?? '');
    $limit   = min((int)($r['lines'] ?? 100), 1000);
    $log_path = ini_get('error_log');

    if (!$log_path) {
        // Thử các vị trí phổ biến
        $candidates = [
            WP_CONTENT_DIR . '/debug.log',
            ABSPATH . 'error_log',
            ABSPATH . '../error_log',
            '/var/log/apache2/error.log',
            '/var/log/nginx/error.log',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c) && is_readable($c)) { $log_path = $c; break; }
        }
    }

    if (!$log_path || !file_exists($log_path)) {
        return xclipse_err('no_log', 'Không tìm thấy error log. error_log=' . (ini_get('error_log') ?: 'unset'), 404);
    }

    // Parse since
    $since_ts = 0;
    if ($since) {
        if (preg_match('/^-(\d+)([hd])$/i', $since, $m)) {
            $n = (int)$m[1];
            $since_ts = time() - ($m[2] === 'h' ? $n * 3600 : $n * 86400);
        } else {
            $since_ts = strtotime($since) ?: 0;
        }
    }

    // Đọc tail của file — tránh đọc toàn bộ file lớn
    $file_size = filesize($log_path);
    $read_size = min($file_size, 2 * 1024 * 1024); // max 2MB
    $fh = fopen($log_path, 'rb');
    if (!$fh) return xclipse_err('read_error', 'Không đọc được error log', 500);

    fseek($fh, -$read_size, SEEK_END);
    $content = fread($fh, $read_size);
    fclose($fh);

    $lines   = explode("\n", $content);
    $matches = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;

        // Filter theo since
        if ($since_ts > 0) {
            // PHP error log format: [dd-Mon-YYYY HH:MM:SS UTC]
            if (preg_match('/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/i', $line, $tm)) {
                $line_ts = strtotime($tm[1]);
                if ($line_ts && $line_ts < $since_ts) continue;
            }
        }

        // Filter theo pattern
        if ($pattern) {
            // Thử regex trước, fallback plain text
            $is_regex = @preg_match("/{$pattern}/i", '') !== false;
            if ($is_regex) {
                if (!preg_match("/{$pattern}/i", $line)) continue;
            } else {
                if (stripos($line, $pattern) === false) continue;
            }
        }

        $matches[] = $line;
    }

    // Lấy tail nếu không có pattern/since
    if (!$pattern && !$since_ts) {
        $matches = array_slice($lines, -$limit);
        $matches = array_filter(array_map('trim', $matches));
        $matches = array_values($matches);
    } else {
        $matches = array_slice($matches, -$limit);
    }

    return xclipse_ok([
        'log_path'   => $log_path,
        'file_size'  => $file_size,
        'read_bytes' => $read_size,
        'pattern'    => $pattern ?: null,
        'since'      => $since ?: null,
        'since_ts'   => $since_ts ?: null,
        'count'      => count($matches),
        'lines'      => array_values($matches),
    ]);
}

// ─── Tier 1: Cache status & purge (multi-plugin) ─────────────────────────────

/**
 * GET /diag/cache-status
 * Phát hiện cache plugin nào đang chạy và trạng thái của nó.
 */
function xclipse_diag_cache_status() {
    $plugins = [];

    // LiteSpeed Cache
    if (defined('LSCWP_V')) {
        $conf = get_option('litespeed.conf', []);
        $plugins['litespeed'] = [
            'active'  => true,
            'version' => LSCWP_V,
            'enabled' => (bool)($conf['cache-enabled'] ?? $conf['cache'] ?? false),
        ];
    }

    // FlyingPress
    $fp_option = get_option('flying_press_settings', get_option('flying-press', null));
    if (defined('FLYING_PRESS_VERSION') || class_exists('FlyingPress') || class_exists('FlyingPress\Purge')
        || function_exists('flying_press_purge_cache') || $fp_option !== null) {
        $fp_ver = defined('FLYING_PRESS_VERSION') ? FLYING_PRESS_VERSION : 'unknown';
        if ($fp_ver === 'unknown' && $fp_option !== null) {
            $fp_ver = get_option('flying_press_version', 'unknown');
        }
        $purge_method = 'action:flying_press_purge_all';
        if (function_exists('flying_press_purge_cache')) $purge_method = 'function';
        elseif (class_exists('FlyingPress\Purge')) $purge_method = 'class';
        $plugins['flyingpress'] = [
            'active'       => true,
            'version'      => $fp_ver,
            'purge_method' => $purge_method,
        ];
    }

    // WP Rocket
    if (defined('WP_ROCKET_VERSION') || function_exists('rocket_clean_domain')) {
        $plugins['wp_rocket'] = [
            'active'  => true,
            'version' => defined('WP_ROCKET_VERSION') ? WP_ROCKET_VERSION : 'unknown',
        ];
    }

    // W3 Total Cache
    if (defined('W3TC_VERSION') || function_exists('w3tc_pgcache_flush')) {
        $plugins['w3tc'] = [
            'active'  => true,
            'version' => defined('W3TC_VERSION') ? W3TC_VERSION : 'unknown',
        ];
    }

    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        $plugins['wp_super_cache'] = ['active' => true];
    }

    // Cloudflare (nếu có plugin)
    if (defined('CLOUDFLARE_PLUGIN_DIR') || class_exists('CF\WordPress\Hooks')) {
        $plugins['cloudflare'] = ['active' => true];
    }

    // Object cache (wp-content/object-cache.php)
    $object_cache = [
        'drop_in'  => file_exists(WP_CONTENT_DIR . '/object-cache.php'),
        'memcached'=> class_exists('Memcached'),
        'redis'    => class_exists('Redis') || class_exists('Predis\Client'),
    ];

    return xclipse_ok([
        'cache_plugins' => $plugins,
        'active_count'  => count($plugins),
        'object_cache'  => $object_cache,
        'wp_cache_const'=> defined('WP_CACHE') ? WP_CACHE : false,
    ]);
}

/**
 * POST /diag/cache-purge
 * Purge tất cả cache từ mọi plugin đang active.
 * Params: plugin (optional — litespeed|flyingpress|wp_rocket|w3tc|all), url (optional)
 */
function xclipse_diag_cache_purge(WP_REST_Request $r) {
    $b      = $r->get_json_params() ?? [];
    $plugin = sanitize_key($b['plugin'] ?? 'all');
    $url    = esc_url_raw($b['url'] ?? '');
    $purged = [];
    $errors = [];

    $purge_all = ($plugin === 'all');

    // LiteSpeed Cache
    if ($purge_all || $plugin === 'litespeed') {
        if (defined('LSCWP_V')) {
            if ($url) {
                do_action('litespeed_purge_url', $url);
            } elseif (class_exists('\LiteSpeed\Purge')) {
                \LiteSpeed\Purge::purge_all();
            } else {
                do_action('litespeed_purge_all');
            }
            wp_cache_flush();
            $purged[] = 'litespeed';
        } elseif ($plugin === 'litespeed') {
            $errors[] = 'LiteSpeed Cache không active';
        }
    }

    // FlyingPress
    if ($purge_all || $plugin === 'flyingpress') {
        $fp_purged = false;

        // FlyingPress — detect via class (most reliable) or option keys
        $fp_detected = class_exists('FlyingPress\Cache')
            || class_exists('FlyingPress\Purge')
            || defined('FLYING_PRESS_VERSION')
            || function_exists('flying_press_purge_cache')
            || get_option('flying_press_settings') !== false
            || get_option('flying-press') !== false
            || get_option('flying_press') !== false;

        if ($fp_detected) {
            if ($url) {
                do_action('flying_press_purge_url', $url);
            } else {
                do_action('flying_press_purge_all');
            }
            $fp_purged = true;
        }

        // FlyingPress — class-based fallback (v4.x)
        if (!$fp_purged && class_exists('FlyingPress\Purge')
            && method_exists('FlyingPress\Purge', 'purge_all')) {
            try {
                $url ? \FlyingPress\Purge::purge_url($url) : \FlyingPress\Purge::purge_all();
                $fp_purged = true;
            } catch (\Throwable $e) {}
        }

        wp_cache_flush();

        if ($fp_purged) {
            $purged[] = 'flyingpress';
        } elseif ($plugin === 'flyingpress') {
            $errors[] = 'FlyingPress không active';
        }
    }

    // WP Rocket
    if ($purge_all || $plugin === 'wp_rocket') {
        if (function_exists('rocket_clean_domain')) {
            if ($url) {
                rocket_clean_post(url_to_postid($url));
            } else {
                rocket_clean_domain();
            }
            $purged[] = 'wp_rocket';
        } elseif ($plugin === 'wp_rocket') {
            $errors[] = 'WP Rocket không active';
        }
    }

    // W3 Total Cache
    if ($purge_all || $plugin === 'w3tc') {
        if (function_exists('w3tc_pgcache_flush')) {
            w3tc_pgcache_flush();
            $purged[] = 'w3tc';
        } elseif ($plugin === 'w3tc') {
            $errors[] = 'W3 Total Cache không active';
        }
    }

    // WP Super Cache
    if ($purge_all || $plugin === 'wp_super_cache') {
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $purged[] = 'wp_super_cache';
        }
    }

    // Luôn flush WP object cache
    wp_cache_flush();

    if (empty($purged) && empty($errors)) {
        $errors[] = 'Không tìm thấy cache plugin nào active';
    }

    return xclipse_ok([
        'ok'     => empty($errors),
        'purged' => $purged,
        'errors' => $errors ?: null,
        'url'    => $url ?: null,
        'time'   => current_time('mysql'),
    ]);
}

// ─── Tier 2: File read / edit (PHP-gated) ────────────────────────────────────

// Chỉ cho phép đọc/sửa trong các thư mục an toàn
function xclipse_diag_file_allowed(string $path): bool {
    $allowed_roots = [
        WP_CONTENT_DIR . '/themes/',
        WP_CONTENT_DIR . '/plugins/',
        WP_CONTENT_DIR . '/mu-plugins/',
        WP_CONTENT_DIR . '/uploads/',
        WP_CONTENT_DIR . '/debug.log',
        ABSPATH . 'error_log',
    ];
    $real = realpath($path);
    if (!$real) {
        // File chưa tồn tại — kiểm tra thư mục cha
        $real = realpath(dirname($path));
        if (!$real) return false;
        $real .= '/' . basename($path);
    }
    foreach ($allowed_roots as $root) {
        $real_root = realpath($root);
        if ($real_root && str_starts_with($real, $real_root)) return true;
        // Kiểm tra prefix trực tiếp cho debug.log / error_log
        if ($real === realpath($root)) return true;
    }
    return false;
}

/**
 * GET /diag/file-read
 * Đọc một file trong themes/plugins/mu-plugins.
 * Params: path (required), from (line, 1-indexed), to (line)
 */
function xclipse_diag_file_read(WP_REST_Request $r) {
    $path = sanitize_text_field($r['path'] ?? '');
    $from = max(1, (int)($r['from'] ?? 1));
    $to   = (int)($r['to'] ?? 0);

    if (!$path) return xclipse_err('missing', 'path là bắt buộc', 400);

    // Normalize path
    if (!str_starts_with($path, '/') && !preg_match('/^[A-Z]:\\\\/i', $path)) {
        $path = WP_CONTENT_DIR . '/' . ltrim($path, '/');
    }

    if (!xclipse_diag_file_allowed($path)) {
        return xclipse_err('forbidden', 'Path nằm ngoài vùng cho phép (themes/plugins/mu-plugins)', 403);
    }

    if (!file_exists($path)) return xclipse_err('not_found', "File không tồn tại: {$path}", 404);
    if (!is_readable($path)) return xclipse_err('unreadable', "Không đọc được file: {$path}", 403);

    $lines     = file($path, FILE_IGNORE_NEW_LINES);
    $total     = count($lines);
    $from      = max(1, $from);
    $to        = $to > 0 ? min($to, $total) : min($from + 199, $total); // max 200 dòng mặc định
    $slice     = array_slice($lines, $from - 1, $to - $from + 1);

    return xclipse_ok([
        'path'        => $path,
        'total_lines' => $total,
        'from'        => $from,
        'to'          => $to,
        'content'     => implode("\n", $slice),
        'lines'       => $slice,
    ]);
}

/**
 * POST /diag/file-edit
 * Thay thế chính xác find→replace trong file. Có PHP lint gate và auto-backup.
 * Params: path, find (string), replace (string), all (bool, default false)
 */
function xclipse_diag_file_edit(WP_REST_Request $r) {
    $b       = $r->get_json_params() ?? [];
    $path    = sanitize_text_field($b['path'] ?? '');
    $find    = $b['find'] ?? '';
    $replace = $b['replace'] ?? '';
    $all     = !empty($b['all']);

    if (!$path || $find === '') return xclipse_err('missing', 'path và find là bắt buộc', 400);

    if (!str_starts_with($path, '/') && !preg_match('/^[A-Z]:\\\\/i', $path)) {
        $path = WP_CONTENT_DIR . '/' . ltrim($path, '/');
    }

    if (!xclipse_diag_file_allowed($path)) {
        return xclipse_err('forbidden', 'Path nằm ngoài vùng cho phép (themes/plugins/mu-plugins)', 403);
    }

    if (!file_exists($path)) return xclipse_err('not_found', "File không tồn tại: {$path}", 404);

    $original = file_get_contents($path);
    if ($original === false) return xclipse_err('read_error', 'Không đọc được file', 500);

    // Đếm matches
    $count = substr_count($original, $find);
    if ($count === 0) return xclipse_err('not_found', "Không tìm thấy chuỗi find trong file (case-sensitive)", 404);
    if ($count > 1 && !$all) {
        return xclipse_err('ambiguous', "Tìm thấy {$count} lần xuất hiện. Thêm all:true để thay tất cả, hoặc dùng find chính xác hơn.", 409);
    }

    if ($all) {
        $new_content = str_replace($find, $replace, $original, $replaced);
    } else {
        $pos = strpos($original, $find);
        $new_content = substr_replace($original, $replace, $pos, strlen($find));
        $replaced = 1;
    }

    // PHP lint gate — chỉ kiểm tra file .php
    if (str_ends_with($path, '.php')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xclipse_edit_');
        file_put_contents($tmp, $new_content);
        $lint_out = shell_exec("php -l " . escapeshellarg($tmp) . " 2>&1");
        unlink($tmp);
        if ($lint_out && !str_contains($lint_out, 'No syntax errors')) {
            return xclipse_err('php_syntax_error', "PHP syntax error sau khi sửa: " . trim($lint_out), 422);
        }
    }

    // Auto-backup (1 backup/file)
    $backup_dir  = WP_CONTENT_DIR . '/xclipse-backups';
    if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
    $backup_path = $backup_dir . '/' . md5($path) . '_' . date('Ymd_His') . '.bak';
    file_put_contents($backup_path, $original);

    // Ghi file
    $written = file_put_contents($path, $new_content);
    if ($written === false) return xclipse_err('write_error', 'Ghi file thất bại', 500);

    return xclipse_ok([
        'ok'          => true,
        'path'        => $path,
        'replaced'    => $replaced,
        'backup_path' => $backup_path,
        'bytes'       => $written,
    ]);
}

// ─── Tier 2: Healthcheck / Troubleshoot mode ─────────────────────────────────

/**
 * POST /diag/troubleshoot
 * Tắt/bật plugin chỉ trong session hiện tại (không ảnh hưởng user khác).
 * Dùng Health Check & Troubleshooting plugin nếu có, fallback thủ công.
 * action: start | stop | status | disable_plugin | enable_plugin
 */
function xclipse_diag_troubleshoot(WP_REST_Request $r) {
    $b      = $r->get_json_params() ?? [];
    $action = sanitize_key($b['action'] ?? 'status');
    $plugin = sanitize_text_field($b['plugin'] ?? '');

    // Kiểm tra Health Check plugin
    $hc_active  = class_exists('Health_Check_Troubleshooting_Mode');
    $hc_active2 = defined('HEALTH_CHECK_PLUGIN_DIRECTORY');

    if ($action === 'status') {
        $session    = get_option('health-check-allowed-plugins', []);
        $disabled   = get_option('health-check-disable-plugins', false);
        return xclipse_ok([
            'health_check_plugin' => $hc_active || $hc_active2,
            'troubleshoot_mode'   => $disabled !== false,
            'disabled_in_session' => $session,
            'note'                => 'Dùng Health Check & Troubleshooting plugin (wordpress.org/plugins/health-check) để bisect plugin conflict an toàn.',
        ]);
    }

    if (!$hc_active && !$hc_active2) {
        // Fallback: tắt plugin thực sự (destructive — cảnh báo)
        if ($action === 'disable_plugin' && $plugin) {
            if (!function_exists('deactivate_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $active = get_option('active_plugins', []);
            if (!in_array($plugin, $active)) {
                return xclipse_err('not_active', "Plugin {$plugin} không active", 404);
            }
            deactivate_plugins($plugin);
            return xclipse_ok([
                'ok'      => true,
                'action'  => 'deactivated',
                'plugin'  => $plugin,
                'warning' => 'Health Check plugin không có — plugin bị tắt THẬT trên site live. Dùng enable_plugin để bật lại.',
            ]);
        }

        if ($action === 'enable_plugin' && $plugin) {
            if (!function_exists('activate_plugin')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $result = activate_plugin($plugin);
            if (is_wp_error($result)) return $result;
            return xclipse_ok(['ok' => true, 'action' => 'activated', 'plugin' => $plugin]);
        }

        return xclipse_err('no_hc_plugin', 'Cài Health Check & Troubleshooting plugin để dùng troubleshoot mode an toàn. Hiện dùng disable_plugin/enable_plugin tắt plugin thật.', 501);
    }

    // Với Health Check plugin active
    if ($action === 'disable_plugin' && $plugin) {
        $allowed = get_option('health-check-allowed-plugins', []);
        $allowed = array_diff($allowed, [$plugin]);
        update_option('health-check-allowed-plugins', array_values($allowed));
        return xclipse_ok(['ok' => true, 'action' => 'session_disabled', 'plugin' => $plugin, 'allowed_plugins' => $allowed]);
    }

    if ($action === 'enable_plugin' && $plugin) {
        $allowed   = get_option('health-check-allowed-plugins', []);
        $allowed[] = $plugin;
        $allowed   = array_unique($allowed);
        update_option('health-check-allowed-plugins', array_values($allowed));
        return xclipse_ok(['ok' => true, 'action' => 'session_enabled', 'plugin' => $plugin, 'allowed_plugins' => $allowed]);
    }

    return xclipse_err('bad_action', 'action phải là: status | disable_plugin | enable_plugin', 400);
}

// ─── Tier 3: DB query (SELECT only) ──────────────────────────────────────────

/**
 * GET /diag/db-query
 * Chạy SELECT query — chỉ SELECT, chặn mọi thứ khác.
 * Params: sql (required), limit (max 500)
 */
function xclipse_diag_db_query(WP_REST_Request $r) {
    global $wpdb;
    $sql   = trim(sanitize_text_field($r['sql'] ?? ''));
    $limit = min((int)($r['limit'] ?? 100), 500);

    if (!$sql) return xclipse_err('missing', 'sql là bắt buộc', 400);

    // Chỉ cho phép SELECT
    if (!preg_match('/^SELECT\s/i', $sql)) {
        return xclipse_err('forbidden', 'Chỉ cho phép SELECT query', 403);
    }

    // Chặn subquery phá hoại
    $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE'];
    foreach ($blocked as $kw) {
        if (preg_match("/\b{$kw}\b/i", $sql)) {
            return xclipse_err('forbidden', "Từ khóa {$kw} bị chặn", 403);
        }
    }

    // Inject LIMIT nếu chưa có
    if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
        $sql .= " LIMIT {$limit}";
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    if ($wpdb->last_error) {
        return xclipse_err('query_error', $wpdb->last_error, 500);
    }

    return xclipse_ok([
        'sql'         => $sql,
        'count'       => count($rows),
        'rows'        => $rows,
        'last_error'  => $wpdb->last_error ?: null,
    ]);
}

/**
 * GET /diag/db-describe
 * Mô tả cấu trúc bảng: cột, kiểu, key. Thêm CHECK lỗi: bảng này có column đó không?
 * Params: table (required, không cần prefix)
 */
function xclipse_diag_db_describe(WP_REST_Request $r) {
    global $wpdb;
    $table = sanitize_text_field($r['table'] ?? '');
    if (!$table) return xclipse_err('missing', 'table là bắt buộc', 400);

    // Tự động thêm prefix nếu chưa có
    if (!str_starts_with($table, $wpdb->prefix)) {
        $table = $wpdb->prefix . $table;
    }

    // Kiểm tra bảng tồn tại
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) {
        return xclipse_err('not_found', "Bảng {$table} không tồn tại", 404);
    }

    $columns = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
    $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    $row_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

    // Phát hiện column thường bị thiếu gây lỗi
    $col_names = array_column($columns, 'Field');

    return xclipse_ok([
        'table'     => $table,
        'row_count' => $row_count,
        'columns'   => $columns,
        'indexes'   => $indexes,
        'col_names' => $col_names,
    ]);
}
