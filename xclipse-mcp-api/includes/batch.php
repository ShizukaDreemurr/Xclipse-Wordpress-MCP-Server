<?php
/**
 * Batch, site_snapshot, changelog — v4.8.0
 * - POST /batch             : chạy nhiều API call trong 1 request
 * - GET  /snapshot          : stats + top_posts + cache + errors + plugins
 * - GET  /changelog         : đọc audit log các write operations
 * - POST /changelog/clear   : xóa log
 */
defined('ABSPATH') || exit;

// ─── BATCH ───────────────────────────────────────────────────────────────────

function xclipse_batch(WP_REST_Request $r) {
    $ops = $r->get_json_params();
    if (!is_array($ops) || empty($ops))
        return xclipse_err('missing_ops', 'Body phải là array các {method,path,body}', 400);
    if (count($ops) > 20)
        return xclipse_err('too_many', 'Tối đa 20 operations mỗi batch', 400);

    $server  = rest_get_server();
    $results = [];

    foreach ($ops as $i => $op) {
        $method = strtoupper($op['method'] ?? 'GET');
        $path   = trim($op['path'] ?? '');
        $body   = $op['body']   ?? null;
        $params = $op['params'] ?? [];

        if (!$path) {
            $results[] = ['index' => $i, 'path' => $path, 'status' => 400,
                          'data'  => ['error' => 'missing path']];
            continue;
        }

        $req = new WP_REST_Request($method, '/xclipse-mcp/v1' . $path);
        if (!empty($params) && is_array($params)) {
            foreach ($params as $k => $v) $req->set_param($k, $v);
        }
        if ($body !== null) {
            $req->set_body(wp_json_encode($body));
            $req->set_header('Content-Type', 'application/json');
        }

        $resp      = rest_do_request($req);
        $results[] = [
            'index'  => $i,
            'path'   => $path,
            'status' => $resp->get_status(),
            'data'   => $server->response_to_data($resp, false),
        ];
    }

    return xclipse_ok(['count' => count($results), 'results' => $results]);
}

// ─── SITE SNAPSHOT ───────────────────────────────────────────────────────────

function xclipse_site_snapshot() {
    global $wpdb;

    $snap = [];

    // Basic site info
    $snap['site'] = [
        'name'   => get_bloginfo('name'),
        'url'    => home_url(),
        'wp'     => get_bloginfo('version'),
        'php'    => PHP_VERSION,
        'lang'   => get_locale(),
        'api_v'  => defined('XCLIPSE_MCP_VERSION') ? XCLIPSE_MCP_VERSION : null,
    ];

    // Content counts
    $snap['content'] = array_filter([
        'posts'    => (int)wp_count_posts('post')->publish,
        'pages'    => (int)wp_count_posts('page')->publish,
        'products' => class_exists('WooCommerce') ? (int)wp_count_posts('product')->publish : null,
        'comments' => (int)wp_count_comments()->approved,
    ]);

    // Top 5 posts by modified date (most recently updated)
    $top = $wpdb->get_results(
        "SELECT ID, post_title, post_modified, comment_count
         FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type='post'
         ORDER BY post_modified DESC LIMIT 5",
        ARRAY_A
    );
    $snap['recent_posts'] = array_map(fn($p) => [
        'id'       => (int)$p['ID'],
        'title'    => $p['post_title'],
        'modified' => substr($p['post_modified'], 0, 10),
    ], $top ?: []);

    // PHP error count last 24 h
    $snap['errors_24h'] = xclipse_count_recent_errors(86400);

    // Cache plugins active
    $cache = [];
    if (defined('LSCWP_V'))                                                     $cache['litespeed']   = LSCWP_V;
    if (defined('FLYING_PRESS_VERSION'))                                        $cache['flyingpress'] = FLYING_PRESS_VERSION;
    elseif (class_exists('FlyingPress\Cache') || class_exists('FlyingPress\Purge')) $cache['flyingpress'] = true;
    if (defined('WP_ROCKET_VERSION'))                                           $cache['wp_rocket']   = WP_ROCKET_VERSION;
    if (defined('W3TC_VERSION'))                                                $cache['w3tc']        = W3TC_VERSION;
    if (defined('WPSUPERCACHE_VERSION'))                                        $cache['wp_super']    = WPSUPERCACHE_VERSION;
    $snap['cache'] = $cache ?: null;

    // Active plugins (name + version, active only)
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $active  = get_option('active_plugins', []);
    $all     = get_plugins();
    $plugins = [];
    foreach ($active as $f) {
        if (isset($all[$f])) $plugins[] = ['name' => $all[$f]['Name'], 'ver' => $all[$f]['Version']];
    }
    $snap['plugins'] = $plugins;

    // Last changelog entry
    $cl = get_option('xclipse_changelog', []);
    $snap['last_write'] = !empty($cl) ? end($cl) : null;

    return xclipse_ok($snap);
}

function xclipse_count_recent_errors(int $seconds): int {
    $log = ini_get('error_log') ?: (defined('ABSPATH') ? ABSPATH . 'error_log' : '');
    if (!$log || !@file_exists($log)) return -1;
    $cutoff = time() - $seconds;
    $handle = @fopen($log, 'r');
    if (!$handle) return -1;
    $count = 0;
    while (($line = fgets($handle)) !== false) {
        if (preg_match('/^\[(\d{2}-\w+-\d{4} \d{2}:\d{2}:\d{2} \w+)\]/', $line, $m)) {
            $ts = @strtotime($m[1]);
            if ($ts && $ts >= $cutoff) $count++;
        }
    }
    fclose($handle);
    return $count;
}

// ─── CHANGELOG ───────────────────────────────────────────────────────────────

define('XCLIPSE_CHANGELOG_MAX', 500);

/**
 * Ghi entry vào audit log. Gọi từ bất kỳ write operation nào.
 * @param string $action  Vd: 'post_update', 'post_delete', 'bulk_status'
 * @param string $summary Mô tả ngắn gọn, ví dụ: "Updated post #42"
 * @param mixed  $affected ID hoặc array IDs bị ảnh hưởng
 */
function xclipse_changelog_add(string $action, string $summary, $affected = null): void {
    $log   = get_option('xclipse_changelog', []);
    $entry = array_filter([
        'ts'       => current_time('Y-m-d H:i:s'),
        'action'   => $action,
        'summary'  => $summary,
        'affected' => $affected,
    ], fn($v) => $v !== null && $v !== '' && $v !== []);
    $log[] = $entry;
    if (count($log) > XCLIPSE_CHANGELOG_MAX)
        $log = array_slice($log, -XCLIPSE_CHANGELOG_MAX);
    update_option('xclipse_changelog', $log, false);
}

function xclipse_changelog_get(WP_REST_Request $r) {
    $log    = get_option('xclipse_changelog', []);
    $limit  = min((int)($r['limit']  ?? 50), 200);
    $offset = (int)($r['offset'] ?? 0);
    $action = sanitize_text_field($r['action'] ?? '');

    // Filter by action type
    if ($action) $log = array_values(array_filter($log, fn($e) => ($e['action'] ?? '') === $action));

    // Newest first
    $log   = array_reverse($log);
    $total = count($log);
    $log   = array_slice($log, $offset, $limit);

    return xclipse_ok(['total' => $total, 'count' => count($log), 'entries' => $log]);
}

function xclipse_changelog_clear() {
    update_option('xclipse_changelog', [], false);
    return xclipse_ok(['ok' => true]);
}
