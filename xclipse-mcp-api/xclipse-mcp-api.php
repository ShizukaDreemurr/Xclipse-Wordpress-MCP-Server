<?php
/**
 * Plugin Name: Xclipse - MCP API
 * Version:     5.0.0
 * Description: REST API đầy đủ cho Claude / Gemini MCP — tối ưu token, compact response.
 * Author:      Khôi Nguyên
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

define('XCLIPSE_MCP_VERSION', '5.0.0');

// ─── Activation ──────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    if (!get_option('xclipse_mcp_api_key'))
        update_option('xclipse_mcp_api_key', bin2hex(random_bytes(32)));
});

// ─── Admin page ──────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_options_page('MCP API', 'MCP API Key', 'manage_options', 'xclipse-mcp', function () {
        $key = get_option('xclipse_mcp_api_key', '');
        $url = home_url('/wp-json/xclipse-mcp/v1/');
        if (isset($_POST['xclipse_action']) && check_admin_referer('xclipse_regen')) {
            update_option('xclipse_mcp_api_key', bin2hex(random_bytes(32)));
            echo '<script>location.reload();</script>';
        }
        $ver = XCLIPSE_MCP_VERSION;
        echo "<div class='wrap'><h1>MCP API v{$ver}</h1>
        <table class='form-table'>
          <tr><th>Base URL</th><td><code>{$url}</code></td></tr>
          <tr><th>API Key</th><td><code style='background:#f0f0f0;padding:4px 8px'>{$key}</code>
            <button class='button' onclick=\"navigator.clipboard.writeText('{$key}');this.textContent='✅'\">Copy</button>
          </td></tr>
        </table>
        <form method='post'>" . wp_nonce_field('xclipse_regen', '_wpnonce', true, false) . "
          <input type='hidden' name='xclipse_action' value='regen'>
          <button class='button button-secondary'>🔄 Tạo lại Key</button>
        </form></div>";
    });
});

// ─── PHP 7.4 Polyfills ───────────────────────────────────────────────────────
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// ─── Auth ────────────────────────────────────────────────────────────────────
function xclipse_auth(WP_REST_Request $r) { // bool|WP_Error (không khai báo kiểu để tương thích PHP 7.4)
    $expected = (string) get_option('xclipse_mcp_api_key', '');
    $k        = (string) $r->get_header('X-MCP-Key');
    if ($k === '' || $expected === '')
        return new WP_Error('unauthorized', 'Invalid key', ['status' => 401]);
    return hash_equals($expected, $k)
        ? true
        : new WP_Error('unauthorized', 'Invalid key', ['status' => 401]);
}
/** Lightweight health check: no DB queries beyond auth. */
function xclipse_ping(WP_REST_Request $r): array {
    return ['ok' => true, 'api_v' => XCLIPSE_MCP_VERSION, 'time' => time()];
}


// ─── Helpers ─────────────────────────────────────────────────────────────────
function xclipse_author_info(int $user_id): ?array {
    if (!$user_id) return null;
    $u = get_userdata($user_id);
    if (!$u) return null;
    return [
        'id'           => $u->ID,
        'name'         => $u->display_name,
        'login'        => $u->user_login,
        'email'        => $u->user_email,
        'role'         => implode(',', $u->roles),
        'registered'   => substr($u->user_registered, 0, 10),
    ];
}
function xclipse_compact(array $d, int $max_str = 0): array {
    $out = [];
    foreach ($d as $k => $v) {
        if ($v === null || $v === '' || $v === [] || $v === false) continue;
        if ($max_str && is_string($v) && mb_strlen($v) > $max_str)
            $v = mb_substr($v, 0, $max_str) . '…';
        $out[$k] = $v;
    }
    return $out;
}
/** Parse comma-separated fields string → array, or empty array (= no filter) */
function xclipse_parse_fields(string $raw): array {
    if (!$raw) return [];
    return array_filter(array_map('trim', explode(',', $raw)));
}
/** Filter array to only specified keys. Empty fields = return all. */
function xclipse_filter_fields(array $item, array $fields): array {
    if (empty($fields)) return $item;
    return array_intersect_key($item, array_flip($fields));
}

function xclipse_ok(array $data): WP_REST_Response {
    return rest_ensure_response($data);
}
function xclipse_err(string $code, string $msg, int $status = 404): WP_Error {
    return new WP_Error($code, $msg, ['status' => $status]);
}
/** Lấy nhiều theme_mod cùng lúc từ get_theme_mods() — 1 DB read thay vì N lần */
function xclipse_get_mods_by_keys(array $keys): array {
    $all  = get_theme_mods();
    $mods = [];
    foreach ($keys as $k) {
        if (isset($all[$k]) && $all[$k] !== '' && $all[$k] !== false) {
            $mods[$k] = $all[$k];
        }
    }
    return $mods;
}


// ─── Batch helpers ───────────────────────────────────────────────────────────
/** Apply nhiều set_theme_mod từ body request — trả về mảng đã update */
function xclipse_apply_theme_mods(array $body): array {
    $updated = [];
    foreach ($body as $key => $val) {
        $key = sanitize_key($key);
        if (!$key) continue;
        set_theme_mod($key, $val);
        $updated[$key] = $val;
    }
    return $updated;
}
/** Format nhiều widget IDs — batch get_option theo type, tránh N+1 */
function xclipse_format_widgets_batch(array $widget_ids): array {
    // Nhóm IDs theo type để chỉ get_option 1 lần/type
    $by_type = [];
    foreach ($widget_ids as $widget_id) {
        [$type] = xclipse_parse_widget_id($widget_id);
        $by_type[$type][] = $widget_id;
    }
    // Load options theo batch
    $options = [];
    foreach (array_keys($by_type) as $type) {
        $options[$type] = get_option("widget_{$type}", []);
    }
    // Build kết quả giữ đúng thứ tự gốc
    $result = [];
    foreach ($widget_ids as $widget_id) {
        [$type, $instance_id] = xclipse_parse_widget_id($widget_id);
        $result[] = [
            'widget_id'   => $widget_id,
            'type'        => $type,
            'instance_id' => $instance_id,
            'settings'    => $options[$type][$instance_id] ?? null,
        ];
    }
    return $result;
}

// ─── SEO meta (RankMath ưu tiên, fallback Yoast) ─────────────────────────────
// v4.6.9: đọc thẳng $wpdb để hoàn toàn bypass WP object cache
function xclipse_seo(int $id): array {
    global $wpdb;
    if (defined('RANK_MATH_VERSION')) {
        $keys = ['rank_math_title','rank_math_description','rank_math_focus_keyword',
                 'rank_math_seo_score','rank_math_robots','rank_math_canonical_url'];
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$placeholders}) ORDER BY umeta_id DESC",
            array_merge([$id], $keys)
        ), ARRAY_A);
        $meta = [];
        // Iterate DESC — first occurrence of each key is the newest row
        foreach ($rows as $row) {
            if (!isset($meta[$row['meta_key']])) $meta[$row['meta_key']] = $row['meta_value'];
        }
        return array_filter([
            'p'         => 'rm',
            't'         => $meta['rank_math_title']         ?? null,
            'd'         => $meta['rank_math_description']   ?? null,
            'k'         => $meta['rank_math_focus_keyword'] ?? null,
            's'         => $meta['rank_math_seo_score']     ?? null,
            'r'         => $meta['rank_math_robots']        ?? null,
            'canonical' => $meta['rank_math_canonical_url'] ?? null,
        ], fn($v) => $v !== '' && $v !== null && $v !== false);
    }
    return array_filter([
        'p' => 'yoast',
        't' => get_post_meta($id, '_yoast_wpseo_title',    true),
        'd' => get_post_meta($id, '_yoast_wpseo_metadesc', true),
        'k' => get_post_meta($id, '_yoast_wpseo_focuskw',  true),
    ], fn($v) => $v !== '');
}

// v4.6.12: ghi thẳng $wpdb — không unhook, không trigger WP hooks gì cả
function xclipse_save_seo(int $id, array $b): void {
    global $wpdb;
    if (defined('RANK_MATH_VERSION')) {
        $map = [
            'seo_title' => 'rank_math_title',
            'seo_desc'  => 'rank_math_description',
            'focus_kw'  => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
        ];
        foreach ($map as $param => $meta_key) {
            if (!isset($b[$param])) continue;
            $val = ($param === 'canonical')
                ? esc_url_raw($b[$param])
                : sanitize_text_field($b[$param]);
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $meta_key]);
            if ($val !== '') {
                $wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $val,
                ]);
            }
        }
        wp_cache_delete($id, 'post_meta');
    } else {
        if (isset($b['seo_title'])) update_post_meta($id, '_yoast_wpseo_title',    sanitize_text_field($b['seo_title']));
        if (isset($b['seo_desc']))  update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_text_field($b['seo_desc']));
        if (isset($b['focus_kw']))  update_post_meta($id, '_yoast_wpseo_focuskw',  sanitize_text_field($b['focus_kw']));
    }
}

// ─── Load modules ───────────────────────────────────────────────────────────
// v4.6.2: capture fatal trong include files
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        update_option('xclipse_last_fatal', [
            'time'    => date('Y-m-d H:i:s'),
            'type'    => $e['type'],
            'message' => $e['message'],
            'file'    => $e['file'],
            'line'    => $e['line'],
        ]);
    }
});

require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/pages.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/taxonomy.php';
require_once __DIR__ . '/includes/comments.php';

// ─── Cache headers for MCP API responses ─────────────────────────────────────
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if ($response instanceof WP_HTTP_Response && str_starts_with($request->get_route(), '/xclipse-mcp/v1'))
        $response->header('Cache-Control', 'no-store, private');
    return $response;
}, 10, 3);

// ─── Register Core Lite routes ───────────────────────────────────────────────
add_action('rest_api_init', function () {
    $ns = 'xclipse-mcp/v1';
    $A  = 'xclipse_auth';
    $r  = fn($route, $method, $cb) => register_rest_route($ns, $route, ['methods' => $method, 'callback' => $cb, 'permission_callback' => $A]);

    // Info
    $r('/info',                  'GET',    'xclipse_info');
    $r('/ping',                  'GET',    'xclipse_ping');
    // Posts
    $r('/posts',                 'GET',    'xclipse_posts_list');
    $r('/posts',                 'POST',   'xclipse_post_create');
    $r('/posts/(?P<id>\d+)',     'GET',    'xclipse_post_get');
    $r('/posts/(?P<id>\d+)',     'PUT',    'xclipse_post_update');
    $r('/posts/(?P<id>\d+)',     'DELETE', 'xclipse_post_delete');
    // Pages
    $r('/pages',                 'GET',    'xclipse_pages_list');
    $r('/pages',                 'POST',   'xclipse_page_create');
    $r('/pages/(?P<id>\d+)',     'GET',    'xclipse_page_get');
    $r('/pages/(?P<id>\d+)',     'PUT',    'xclipse_page_update');
    $r('/pages/(?P<id>\d+)',     'DELETE', 'xclipse_page_delete');
    // Media
    $r('/media',                 'GET',    'xclipse_media_list');
    $r('/media',                 'POST',   'xclipse_media_upload');
    $r('/media/(?P<id>\d+)',     'GET',    'xclipse_media_get');
    // Taxonomy
    $r('/categories',            'GET',    'xclipse_cats');
    $r('/tags',                  'GET',    'xclipse_tags');
    // Comments
    $r('/comments',              'GET',    'xclipse_comments_list');
    $r('/comments/(?P<id>\d+)',  'PUT',    'xclipse_comment_update');
});
