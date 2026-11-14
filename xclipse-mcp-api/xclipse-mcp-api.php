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
require_once __DIR__ . '/includes/woocommerce.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/taxonomy.php';
require_once __DIR__ . '/includes/comments.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/menus.php';
require_once __DIR__ . '/includes/snippets.php';
require_once __DIR__ . '/includes/rankmath.php';
require_once __DIR__ . '/includes/burst.php';
require_once __DIR__ . '/includes/lscache.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/widgets.php';
require_once __DIR__ . '/includes/ux-builder.php';
require_once __DIR__ . '/includes/ux-blocks.php';
require_once __DIR__ . '/includes/flatsome.php';
require_once __DIR__ . '/includes/debug.php';
require_once __DIR__ . '/includes/pixta.php';
require_once __DIR__ . '/includes/cptui.php';
require_once __DIR__ . '/includes/cf7.php';
require_once __DIR__ . '/includes/content-ops.php';
require_once __DIR__ . '/includes/contact-requests.php';
require_once __DIR__ . '/includes/woocommerce-extended.php';
require_once __DIR__ . '/includes/advanced.php';
require_once __DIR__ . '/includes/elementor.php'; // v4.6.15
require_once __DIR__ . '/includes/diag.php';     // v4.7.1
require_once __DIR__ . '/includes/batch.php';    // v4.8.0
require_once __DIR__ . '/includes/acf.php';        // v4.9.0
require_once __DIR__ . '/includes/wpmailsmtp.php'; // v4.9.0

// ─── Cache headers for MCP API responses ─────────────────────────────────────
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if ($response instanceof WP_HTTP_Response && str_starts_with($request->get_route(), '/xclipse-mcp/v1'))
        $response->header('Cache-Control', 'no-store, private');
    return $response;
}, 10, 3);

// ─── Register routes ─────────────────────────────────────────────────────────
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
    $r('/pages/bulk',            'POST',   'xclipse_pages_bulk_update');
    // Products
    $r('/products',              'GET',    'xclipse_products_list');
    $r('/products',              'POST',   'xclipse_product_create');
    $r('/products/(?P<id>\d+)', 'GET',    'xclipse_product_get');
    $r('/products/(?P<id>\d+)', 'PUT',    'xclipse_product_update');
    // Orders
    $r('/orders',                'GET',    'xclipse_orders_list');
    $r('/orders',                'POST',   'xclipse_order_create');
    $r('/orders/(?P<id>\d+)',   'GET',    'xclipse_order_get');
    $r('/orders/(?P<id>\d+)',   'PUT',    'xclipse_order_update');
    $r('/orders/(?P<id>\d+)',   'DELETE', 'xclipse_order_delete');
    // Customers
    $r('/customers',             'GET',    'xclipse_customers_list');
    $r('/customers/(?P<id>\d+)','GET',    'xclipse_customer_get');
    // Coupons
    $r('/coupons',               'GET',    'xclipse_coupons_list');
    $r('/coupons',               'POST',   'xclipse_coupon_create');
    $r('/coupons/(?P<id>\d+)',  'PUT',    'xclipse_coupon_update');
    $r('/coupons/(?P<id>\d+)',  'DELETE', 'xclipse_coupon_delete');
    // Product categories
    $r('/product-categories',    'GET',    'xclipse_product_cats');
    $r('/product-categories',    'POST',   'xclipse_product_cat_create');
    // Refunds
    $r('/orders/(?P<id>\d+)/refund', 'POST', 'xclipse_order_refund');
    // Stats
    $r('/stats',                 'GET',    'xclipse_stats');
    // Media
    $r('/media',                 'GET',    'xclipse_media_list');
    $r('/media',                 'POST',   'xclipse_media_upload');
    $r('/media/(?P<id>\d+)',    'GET',    'xclipse_media_get');
    $r('/media/(?P<id>\d+)',    'PUT',    'xclipse_media_update');
    $r('/media/(?P<id>\d+)',    'DELETE', 'xclipse_media_delete');
    // RankMath
    $r('/rankmath',                       'GET',  'xclipse_rm_overview');
    $r('/rankmath/settings',              'GET',  'xclipse_rm_settings_get');
    $r('/rankmath/settings',              'PUT',  'xclipse_rm_settings_set');
    $r('/rankmath/dump',                  'GET',  'xclipse_rm_dump');
    $r('/rankmath/bulk',                  'POST', 'xclipse_rm_bulk');
    $r('/rankmath/redirects',             'GET',  'xclipse_rm_redirects_list');
    $r('/rankmath/redirects',             'POST', 'xclipse_rm_redirect_create');
    $r('/rankmath/redirects/(?P<id>\d+)', 'DELETE', 'xclipse_rm_redirect_delete');
    // Audit Tools (v3.4.0)
    $r('/audit/summary',            'GET',  'xclipse_audit_summary');
    $r('/audit/seo-issues',         'GET',  'xclipse_audit_seo_issues');
    $r('/audit/duplicate-meta',     'GET',  'xclipse_audit_duplicate_meta');
    $r('/audit/orphan-pages',       'GET',  'xclipse_audit_orphan_pages');
    $r('/audit/internal-links',     'GET',  'xclipse_audit_internal_links');
    $r('/audit/images',             'GET',  'xclipse_audit_images');
    $r('/audit/images-in-content',  'GET',  'xclipse_audit_images_in_content');
    $r('/audit/content',            'GET',  'xclipse_audit_content');
    $r('/audit/broken-links',       'GET',  'xclipse_audit_broken_links');
    $r('/audit/wp-health',          'GET',  'xclipse_audit_wp_health');
    $r('/audit/security',           'GET',  'xclipse_audit_security');
    $r('/audit/post-performance',   'GET',  'xclipse_audit_post_performance');
    $r('/audit/db-health',          'GET',  'xclipse_audit_db_health');
    $r('/audit/db-cleanup',         'POST', 'xclipse_audit_db_cleanup');
    $r('/audit/redirects-health',   'GET',  'xclipse_audit_redirects_health');
    // Fix Tools v3.5.0
    $r('/fix/security',             'POST', 'xclipse_fix_security');
    $r('/fix/user-role',            'POST', 'xclipse_fix_user_role');
    $r('/fix/alt-text',             'POST', 'xclipse_fix_alt_text');
    $r('/fix/alt-text/(?P<id>\d+)', 'PUT',  'xclipse_fix_alt_text_single');
    $r('/fix/seo-bulk',             'POST', 'xclipse_fix_seo_bulk');
    $r('/fix/internal-links',       'POST', 'xclipse_fix_internal_links');
    $r('/fix/internal-links-bulk',  'POST', 'xclipse_fix_internal_links_bulk');
    $r('/fix/lsc-optimization',     'POST', 'xclipse_fix_lsc_optimization');
    $r('/fix/featured-image',       'POST', 'xclipse_fix_featured_image');
    $r('/fix/refresh-date',         'POST', 'xclipse_fix_refresh_date');
    $r('/fix/redirects-bulk',       'POST', 'xclipse_fix_redirects_bulk');
    $r('/fix/product-content',      'POST', 'xclipse_fix_product_content');
    // Taxonomy SEO (v3.3.2)
    $r('/rankmath/tax/bulk',                                     'POST', 'xclipse_rm_tax_bulk');
    $r('/rankmath/tax/(?P<taxonomy>[a-z0-9_-]+)',                'GET',  'xclipse_rm_tax_list');
    $r('/rankmath/tax/(?P<taxonomy>[a-z0-9_-]+)/(?P<term_id>\d+)', 'GET', 'xclipse_rm_tax_get');
    $r('/rankmath/tax/(?P<taxonomy>[a-z0-9_-]+)/(?P<term_id>\d+)', 'PUT', 'xclipse_rm_tax_set');
    $r('/rankmath/(?P<id>\d+)',           'GET',  'xclipse_rm_get');
    $r('/rankmath/(?P<id>\d+)',           'PUT',  'xclipse_rm_set');
    // Burst
    $r('/burst',                 'GET',    'xclipse_burst');
    $r('/burst/top-pages',       'GET',    'xclipse_burst_top');
    // Site Kit
    $r('/sitekit',               'GET',    'xclipse_sitekit');
    // Themes & Plugins
    $r('/themes',                'GET',    'xclipse_themes');
    $r('/plugins',               'GET',    'xclipse_plugins');
    // Settings
    $r('/settings',              'GET',    'xclipse_settings_get');
    $r('/settings',              'POST',   'xclipse_settings_set');
    // Taxonomy
    $r('/categories',            'GET',    'xclipse_cats');
    $r('/tags',                  'GET',    'xclipse_tags');
    // Comments
    $r('/comments',              'GET',    'xclipse_comments_list');
    $r('/comments/(?P<id>\d+)', 'GET',    'xclipse_comment_get');
    $r('/comments/(?P<id>\d+)', 'PUT',    'xclipse_comment_update');
    $r('/comments/(?P<id>\d+)', 'DELETE', 'xclipse_comment_delete');
    // Users
    $r('/users',                 'GET',    'xclipse_users_list');
    $r('/users/(?P<id>\d+)',    'GET',    'xclipse_user_get');
    $r('/users',                 'POST',   'xclipse_user_create');
    $r('/users/(?P<id>\d+)',    'PUT',    'xclipse_user_update');
    // Bulk operations (posts, products, rankmath)
    $r('/bulk/posts',            'POST',   'xclipse_bulk_posts');
    $r('/bulk/products',         'POST',   'xclipse_bulk_products');
    // Menus
    $r('/menus',                                        'GET',    'xclipse_menus');
    $r('/menus',                                        'POST',   'xclipse_menu_create');
    $r('/menus/(?P<id>\d+)',                            'GET',    'xclipse_menu_get');
    $r('/menus/(?P<id>\d+)',                            'PUT',    'xclipse_menu_update');
    $r('/menus/(?P<id>\d+)',                            'DELETE', 'xclipse_menu_delete');
    $r('/menus/(?P<id>\d+)/items',                     'POST',   'xclipse_menu_item_add');
    $r('/menus/(?P<id>\d+)/items/(?P<item_id>\d+)',    'PUT',    'xclipse_menu_item_update');
    $r('/menus/(?P<id>\d+)/items/(?P<item_id>\d+)',    'DELETE', 'xclipse_menu_item_delete');
    $r('/menus/(?P<id>\d+)/reorder',                   'POST',   'xclipse_menu_reorder');
    $r('/menus/locations',                              'GET',    'xclipse_menu_locations');
    $r('/menus/locations',                              'POST',   'xclipse_menu_assign_location');
    // Debug (tạm thời)
    $r('/debug/burst', 'GET', 'xclipse_debug_burst');
    $r('/debug/fatal', 'GET', 'xclipse_debug_fatal');
    // WPCode Snippets
    $r('/snippets',                  'GET',    'xclipse_snippets_list');
    $r('/snippets',                  'POST',   'xclipse_snippet_create');
    $r('/snippets/(?P<id>\d+)',     'GET',    'xclipse_snippet_get');
    $r('/snippets/(?P<id>\d+)',     'PUT',    'xclipse_snippet_update');
    $r('/snippets/(?P<id>\d+)',     'DELETE', 'xclipse_snippet_delete');
    $r('/snippets/(?P<id>\d+)/toggle', 'POST', 'xclipse_snippet_toggle');
    // SERP / SEO Tools
    $r('/serp/rank',         'GET', 'xclipse_rank_check');
    $r('/serp/overview',     'GET', 'xclipse_serp_overview');
    $r('/serp/rank-bulk',    'GET', 'xclipse_rank_bulk');
    $r('/site-speed',        'GET', 'xclipse_site_speed');
    $r('/url-inspect',       'GET', 'xclipse_url_inspect');
    // Flatsome UX Builder
    $r('/ux/(?P<id>\d+)',                        'GET',    'xclipse_ux_get');
    $r('/ux/(?P<id>\d+)',                        'PUT',    'xclipse_ux_update');
    $r('/ux/(?P<id>\d+)/blocks',                 'GET',    'xclipse_ux_blocks');
    $r('/ux/(?P<id>\d+)/blocks/(?P<idx>\d+)',    'PUT',    'xclipse_ux_block_patch');
    $r('/ux/(?P<id>\d+)/blocks',                 'POST',   'xclipse_ux_block_add');
    $r('/ux/(?P<id>\d+)/blocks/(?P<idx>\d+)',    'DELETE', 'xclipse_ux_block_delete');
    $r('/ux/(?P<id>\d+)/blocks/reorder',         'POST',   'xclipse_ux_reorder');
    $r('/ux/(?P<id>\d+)/clone/(?P<idx>\d+)',     'POST',   'xclipse_ux_block_clone');
    $r('/ux/search',                             'GET',    'xclipse_ux_search');
    // LiteSpeed Cache
    $r('/lscache/status',          'GET',  'xclipse_lsc_status');
    $r('/lscache/purge-all',       'POST', 'xclipse_lsc_purge_all');
    $r('/lscache/purge-url',       'POST', 'xclipse_lsc_purge_url');
    $r('/lscache/purge-tag',       'POST', 'xclipse_lsc_purge_tag');
    $r('/lscache/object-cache',    'GET',  'xclipse_lsc_object_cache_status');
    $r('/lscache/object-cache',    'POST', 'xclipse_lsc_object_cache_action');
    $r('/lscache/cdn',             'GET',  'xclipse_lsc_cdn_status');
    $r('/lscache/cdn/purge',       'POST', 'xclipse_lsc_cdn_purge');
    $r('/lscache/critical-css',    'GET',  'xclipse_lsc_ccss_status');
    $r('/lscache/critical-css',    'POST', 'xclipse_lsc_ccss_action');
    $r('/lscache/image-optm',      'GET',  'xclipse_lsc_img_optm_status');
    $r('/lscache/image-optm',      'POST', 'xclipse_lsc_img_optm_action');
    $r('/lscache/crawl',           'GET',  'xclipse_lsc_crawl_status');
    $r('/lscache/crawl',           'POST', 'xclipse_lsc_crawl_action');
    $r('/lscache/settings',        'GET',  'xclipse_lsc_settings_get');
    $r('/lscache/settings',        'PUT',  'xclipse_lsc_settings_set');
    $r('/lscache/heartbeat',       'GET',  'xclipse_lsc_heartbeat');
    // Rich Results / Structured Data
    $r('/rich-results',            'GET',  'xclipse_rich_results');
    $r('/schema-validate',         'POST', 'xclipse_schema_validate');
    // Flatsome Theme Settings (v3.5.5)
    $r('/flatsome',                'GET',  'xclipse_flatsome_get');
    $r('/flatsome',                'PUT',  'xclipse_flatsome_update');
    $r('/flatsome/custom-css',     'GET',  'xclipse_flatsome_css_get');
    $r('/flatsome/custom-css',     'PUT',  'xclipse_flatsome_css_set');
    $r('/flatsome/footer',         'GET',  'xclipse_flatsome_footer_get');
    $r('/flatsome/footer',         'PUT',  'xclipse_flatsome_footer_set');
    // Flatsome Header (v3.5.7)
    $r('/flatsome/header',         'GET',  'xclipse_flatsome_header_get');
    $r('/flatsome/header',         'PUT',  'xclipse_flatsome_header_set');
    $r('/flatsome/header/logo',    'PUT',  'xclipse_flatsome_header_logo_set');
    $r('/flatsome/header/topbar',  'GET',  'xclipse_flatsome_header_topbar_get');
    $r('/flatsome/header/topbar',  'PUT',  'xclipse_flatsome_header_topbar_set');
    // UX Blocks CPT (v3.5.8)
    $r('/ux-blocks',               'GET',  'xclipse_ux_blocks_list');
    $r('/ux-blocks',               'POST', 'xclipse_ux_block_create');
    $r('/ux-blocks/(?P<id>\d+)',   'GET',  'xclipse_ux_block_get');
    $r('/ux-blocks/(?P<id>\d+)',   'PUT',  'xclipse_ux_block_update_post');
    $r('/ux-blocks/(?P<id>\d+)',   'DELETE', 'xclipse_ux_block_delete_post');
    // Widget Areas (v3.5.6)
    $r('/widgets',                                      'GET',    'xclipse_widgets_list');
    $r('/widgets/(?P<sidebar>[a-z0-9_-]+)',             'GET',    'xclipse_sidebar_get');
    $r('/widgets/(?P<sidebar>[a-z0-9_-]+)',             'POST',   'xclipse_widget_add');
    $r('/widgets/(?P<sidebar>[a-z0-9_-]+)/(?P<widget_id>[a-z0-9_-]+)', 'PUT',    'xclipse_widget_update');
    $r('/widgets/(?P<sidebar>[a-z0-9_-]+)/(?P<widget_id>[a-z0-9_-]+)', 'DELETE', 'xclipse_widget_delete');
    $r('/widgets/(?P<sidebar>[a-z0-9_-]+)/reorder',    'POST',   'xclipse_widget_reorder');
    // ── CPT UI + Generic CPT Tools (v3.9.0) ──────────────────────────────────
    $r('/cptui/types',                                                   'GET',    'xclipse_cptui_types_list');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)',                             'GET',    'xclipse_cptui_type_get');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)/posts',                      'GET',    'xclipse_cptui_posts_list');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)/posts',                      'POST',   'xclipse_cptui_post_create');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)/posts/(?P<id>\d+)',          'GET',    'xclipse_cptui_post_get');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)/posts/(?P<id>\d+)',          'PUT',    'xclipse_cptui_post_update');
    $r('/cptui/types/(?P<slug>[a-z0-9_-]+)/posts/(?P<id>\d+)',          'DELETE', 'xclipse_cptui_post_delete');
    $r('/cptui/taxonomies',                                              'GET',    'xclipse_cptui_taxonomies_list');
    $r('/cptui/taxonomies/(?P<slug>[a-z0-9_-]+)',                        'GET',    'xclipse_cptui_taxonomy_get');
    $r('/cptui/taxonomies/(?P<slug>[a-z0-9_-]+)/terms',                 'POST',   'xclipse_cptui_term_create');
    $r('/cptui/taxonomies/(?P<slug>[a-z0-9_-]+)/terms/(?P<term_id>\d+)','PUT',    'xclipse_cptui_term_update');
    $r('/cptui/taxonomies/(?P<slug>[a-z0-9_-]+)/terms/(?P<term_id>\d+)','DELETE', 'xclipse_cptui_term_delete');
    // ── Pixta Review System (v3.8.0) ─────────────────────────────────────
    $r('/pixta/schema',                    'GET',    'xclipse_pixta_schema');
    $r('/pixta/categories',                'GET',    'xclipse_pixta_categories');
    // Brokers
    $r('/pixta/brokers',                   'GET',    'xclipse_pixta_brokers_list');
    $r('/pixta/brokers',                   'POST',   'xclipse_pixta_broker_create');
    $r('/pixta/brokers/(?P<id>\d+)',       'GET',    'xclipse_pixta_broker_get');
    $r('/pixta/brokers/(?P<id>\d+)',       'PUT',    'xclipse_pixta_broker_update');
    $r('/pixta/brokers/(?P<id>\d+)',       'DELETE', 'xclipse_pixta_broker_delete');
    // Scam / Blacklist
    $r('/pixta/scam',                      'GET',    'xclipse_pixta_scam_list');
    $r('/pixta/scam',                      'POST',   'xclipse_pixta_scam_create');
    $r('/pixta/scam/(?P<id>\d+)',          'GET',    'xclipse_pixta_scam_get');
    $r('/pixta/scam/(?P<id>\d+)',          'PUT',    'xclipse_pixta_scam_update');
    $r('/pixta/scam/(?P<id>\d+)',          'DELETE', 'xclipse_pixta_scam_delete');
    // Books
    $r('/pixta/books',                     'GET',    'xclipse_pixta_books_list');
    $r('/pixta/books',                     'POST',   'xclipse_pixta_book_create');
    $r('/pixta/books/(?P<id>\d+)',         'GET',    'xclipse_pixta_book_get');
    $r('/pixta/books/(?P<id>\d+)',         'PUT',    'xclipse_pixta_book_update');
    $r('/pixta/books/(?P<id>\d+)',         'DELETE', 'xclipse_pixta_book_delete');
    // Generic ACF read/write cho bất kỳ post ID
    $r('/pixta/acf/(?P<id>\d+)',           'GET',    'xclipse_pixta_acf_get_endpoint');
    $r('/pixta/acf/(?P<id>\d+)',           'PUT',    'xclipse_pixta_acf_set_endpoint');
    // ── Content Operations (v4.1.0) ──────────────────────────────────────────
    $r('/content/slug/(?P<id>\d+)',                                         'PUT',    'xclipse_content_slug_update');
    $r('/content/slug/bulk',                                                'POST',   'xclipse_content_slug_bulk');
    $r('/content/flush-permalinks',                                         'POST',   'xclipse_content_flush_permalinks');
    $r('/content/clone/(?P<id>\d+)',                                        'POST',   'xclipse_content_clone');
    $r('/content/restore/(?P<id>\d+)',                                      'POST',   'xclipse_content_restore');
    $r('/content/status/bulk',                                              'POST',   'xclipse_content_status_bulk');
    $r('/content/date/(?P<id>\d+)',                                         'PUT',    'xclipse_content_date_update');
    $r('/content/author/bulk',                                              'POST',   'xclipse_content_author_bulk');
    $r('/content/parent/bulk',                                              'POST',   'xclipse_content_parent_bulk');
    $r('/content/meta/(?P<id>\d+)',                                         'GET',    'xclipse_meta_get');
    $r('/content/meta/(?P<id>\d+)',                                         'PUT',    'xclipse_meta_set');
    // Term CRUD (category, tag, product_cat, bất kỳ taxonomy)
    $r('/content/terms/(?P<taxonomy>[a-z0-9_-]+)',                          'GET',    'xclipse_term_list');
    $r('/content/terms/(?P<taxonomy>[a-z0-9_-]+)',                          'POST',   'xclipse_term_create');
    $r('/content/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<term_id>\d+)',        'GET',    'xclipse_term_get');
    $r('/content/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<term_id>\d+)',        'PUT',    'xclipse_term_update');
    $r('/content/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<term_id>\d+)',        'DELETE', 'xclipse_term_delete');
    // ── Contact Form 7 (v4.0.0) ──────────────────────────────────────────────
    $r('/cf7/forms',                                           'GET',    'xclipse_cf7_forms_list');
    $r('/cf7/forms',                                           'POST',   'xclipse_cf7_form_create');
    $r('/cf7/forms/(?P<id>\d+)',                               'GET',    'xclipse_cf7_form_get');
    $r('/cf7/forms/(?P<id>\d+)',                               'PUT',    'xclipse_cf7_form_update');
    $r('/cf7/forms/(?P<id>\d+)',                               'DELETE', 'xclipse_cf7_form_delete');
    $r('/cf7/forms/(?P<id>\d+)/duplicate',                    'POST',   'xclipse_cf7_form_duplicate');
    $r('/cf7/forms/(?P<id>\d+)/fields',                       'GET',    'xclipse_cf7_fields_list');
    $r('/cf7/forms/(?P<id>\d+)/mail',                         'GET',    'xclipse_cf7_mail_get');
    $r('/cf7/forms/(?P<id>\d+)/mail',                         'PUT',    'xclipse_cf7_mail_update');
    $r('/cf7/submissions',                                     'GET',    'xclipse_cf7_submissions_list');
    $r('/cf7/submissions/(?P<id>\d+)',                         'GET',    'xclipse_cf7_submission_get');
    $r('/cf7/submissions/(?P<id>\d+)/spam',                   'PUT',    'xclipse_cf7_submission_spam');
    $r('/cf7/submissions/(?P<id>\d+)',                         'DELETE', 'xclipse_cf7_submission_delete');
    // ── Contact Requests — Snippet 17 (v4.6.0) ───────────────────────────────
    $r('/contact-requests',                      'GET',    'xclipse_lh_list');
    $r('/contact-requests',                      'POST',   'xclipse_lh_create');
    $r('/contact-requests/(?P<id>\d+)',          'GET',    'xclipse_lh_get');
    $r('/contact-requests/(?P<id>\d+)',          'PUT',    'xclipse_lh_update');
    $r('/contact-requests/(?P<id>\d+)',          'DELETE', 'xclipse_lh_delete');
    $r('/contact-requests/bulk-status',          'POST',   'xclipse_lh_bulk_status');

    // ── WooCommerce Extended (v4.6.5) ─────────────────────────────────────────
    // Payment Gateways
    $r('/wc/gateways',                                                    'GET',  'xclipse_payment_gateways_list');
    $r('/wc/gateways/(?P<id>[a-z0-9_-]+)',                               'GET',  'xclipse_payment_gateway_get');
    $r('/wc/gateways/(?P<id>[a-z0-9_-]+)',                               'PUT',  'xclipse_payment_gateway_update');
    // Shipping Zones
    $r('/wc/shipping/zones',                                              'GET',  'xclipse_shipping_zones_list');
    $r('/wc/shipping/zones',                                              'POST', 'xclipse_shipping_zone_create');
    $r('/wc/shipping/zones/(?P<id>\d+)',                                  'GET',  'xclipse_shipping_zone_get');
    $r('/wc/shipping/zones/(?P<id>\d+)',                                  'PUT',  'xclipse_shipping_zone_update');
    $r('/wc/shipping/zones/(?P<id>\d+)',                                  'DELETE', 'xclipse_shipping_zone_delete');
    $r('/wc/shipping/zones/(?P<zone_id>\d+)/methods/(?P<instance_id>\d+)', 'PUT', 'xclipse_shipping_method_update');
    // Tax
    $r('/wc/tax/classes',                                                 'GET',  'xclipse_tax_classes_list');
    $r('/wc/tax/rates',                                                   'GET',  'xclipse_tax_rates_list');
    $r('/wc/tax/rates',                                                   'POST', 'xclipse_tax_rate_create');
    $r('/wc/tax/rates/(?P<id>\d+)',                                       'PUT',  'xclipse_tax_rate_update');
    $r('/wc/tax/rates/(?P<id>\d+)',                                       'DELETE', 'xclipse_tax_rate_delete');
    // Product Variations
    $r('/products/(?P<id>\d+)/variations',                               'GET',  'xclipse_product_variations_list');
    $r('/products/(?P<id>\d+)/variations',                               'POST', 'xclipse_product_variation_create');
    $r('/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)',         'GET',  'xclipse_product_variation_get');
    $r('/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)',         'PUT',  'xclipse_product_variation_update');
    $r('/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)',         'DELETE', 'xclipse_product_variation_delete');
    // Product Attributes
    $r('/wc/attributes',                                                  'GET',  'xclipse_product_attributes_list');
    $r('/wc/attributes',                                                  'POST', 'xclipse_product_attribute_create');
    $r('/wc/attributes/(?P<id>\d+)',                                      'PUT',  'xclipse_product_attribute_update');
    $r('/wc/attributes/(?P<id>\d+)',                                      'DELETE', 'xclipse_product_attribute_delete');
    $r('/wc/attributes/(?P<attribute>[a-z0-9_-]+)/terms',                'GET',  'xclipse_product_attribute_terms_list');
    $r('/wc/attributes/(?P<attribute>[a-z0-9_-]+)/terms',                'POST', 'xclipse_product_attribute_term_create');
    $r('/wc/attributes/(?P<attribute>[a-z0-9_-]+)/terms/(?P<term_id>\d+)', 'PUT', 'xclipse_product_attribute_term_update');
    // Product Reviews
    $r('/wc/reviews',                                                     'GET',  'xclipse_product_reviews_list');
    $r('/wc/reviews/(?P<id>\d+)',                                         'GET',  'xclipse_product_review_get');
    $r('/wc/reviews/(?P<id>\d+)',                                         'PUT',  'xclipse_product_review_update');
    $r('/wc/reviews/(?P<id>\d+)',                                         'DELETE', 'xclipse_product_review_delete');
    // Product Tags
    $r('/wc/tags',                                                        'GET',  'xclipse_product_tags_list');
    $r('/wc/tags',                                                        'POST', 'xclipse_product_tag_create');
    $r('/wc/tags/(?P<id>\d+)',                                            'PUT',  'xclipse_product_tag_update');
    // Product Bulk Actions
    $r('/products/stock/bulk',                                            'POST', 'xclipse_product_stock_update');
    $r('/products/(?P<id>\d+)/duplicate',                                'POST', 'xclipse_product_duplicate');
    $r('/products/bulk/delete',                                           'POST', 'xclipse_bulk_products_delete');
    // Order Notes & Items
    $r('/orders/(?P<id>\d+)/notes',                                      'GET',  'xclipse_order_notes_list');
    $r('/orders/(?P<id>\d+)/notes',                                      'POST', 'xclipse_order_note_create');
    $r('/orders/(?P<id>\d+)/notes/(?P<note_id>\d+)',                     'DELETE', 'xclipse_order_note_delete');
    $r('/orders/(?P<id>\d+)/items',                                      'GET',  'xclipse_order_items_list');
    $r('/orders/status/bulk',                                             'POST', 'xclipse_order_status_bulk');
    // Customer Extended
    $r('/customers',                                                       'POST', 'xclipse_customer_create');
    $r('/customers/(?P<id>\d+)',                                          'PUT',  'xclipse_customer_update');
    $r('/customers/(?P<id>\d+)',                                          'DELETE', 'xclipse_customer_delete');
    $r('/customers/(?P<id>\d+)/orders',                                   'GET',  'xclipse_customer_orders_list');
    // WC Reports
    $r('/wc/reports/sales',                                               'GET',  'xclipse_wc_report_sales');
    $r('/wc/reports/top-sellers',                                         'GET',  'xclipse_wc_report_top_sellers');
    $r('/wc/reports/stock',                                               'GET',  'xclipse_wc_report_stock');
    $r('/wc/reports/coupons',                                             'GET',  'xclipse_wc_report_coupons');
    // WC Settings
    $r('/wc/settings/(?P<group>[a-z0-9_-]+)',                             'GET',  'xclipse_wc_settings_get');
    $r('/wc/settings',                                                    'POST', 'xclipse_wc_settings_update');
    // WC Email Templates
    $r('/wc/emails',                                                      'GET',  'xclipse_wc_email_list');
    $r('/wc/emails/(?P<id>[a-zA-Z0-9_]+)',                               'GET',  'xclipse_wc_email_get');
    $r('/wc/emails/(?P<id>[a-zA-Z0-9_]+)',                               'PUT',  'xclipse_wc_email_update');
    // Webhooks
    $r('/wc/webhooks',                                                    'GET',  'xclipse_webhooks_list');
    $r('/wc/webhooks',                                                    'POST', 'xclipse_webhook_create');
    $r('/wc/webhooks/(?P<id>\d+)',                                        'PUT',  'xclipse_webhook_update');
    $r('/wc/webhooks/(?P<id>\d+)',                                        'DELETE', 'xclipse_webhook_delete');

    // ── Advanced Tools (v4.6.6) ───────────────────────────────────────────────
    // Users extended
    $r('/users/(?P<id>\d+)',                                   'DELETE', 'xclipse_user_delete');
    $r('/users/bulk/delete',                                   'POST',   'xclipse_users_bulk_delete');
    // Comments create
    $r('/comments',                                            'POST',   'xclipse_comment_create');
    // Revisions
    $r('/posts/(?P<id>\d+)/revisions',                        'GET',    'xclipse_post_revisions_list');
    $r('/posts/(?P<id>\d+)/revisions/(?P<revision_id>\d+)/restore', 'POST', 'xclipse_post_revision_restore');
    // WP Options
    $r('/options',                                             'GET',    'xclipse_options_get');
    $r('/options',                                             'POST',   'xclipse_options_set');
    $r('/options/delete',                                      'POST',   'xclipse_options_delete');
    // Transients
    $r('/transients',                                          'GET',    'xclipse_transients_list');
    $r('/transients',                                          'DELETE', 'xclipse_transients_delete');
    // Cron
    $r('/cron',                                                'GET',    'xclipse_cron_list');
    $r('/cron/run',                                            'POST',   'xclipse_cron_run');
    $r('/cron/delete',                                         'POST',   'xclipse_cron_delete');
    // Plugin management
    $r('/plugins/activate',                                    'POST',   'xclipse_plugin_activate');
    $r('/plugins/deactivate',                                  'POST',   'xclipse_plugin_deactivate');
    $r('/plugins/delete',                                      'POST',   'xclipse_plugin_delete');
    $r('/plugins/install',                                     'POST',   'xclipse_plugin_install');
    // Theme management
    $r('/themes/activate',                                     'POST',   'xclipse_theme_activate');
    $r('/themes/install',                                      'POST',   'xclipse_theme_install');
    // DB Tools
    $r('/db/optimize',                                         'POST',   'xclipse_db_optimize');
    $r('/db/autoload-fix',                                     'POST',   'xclipse_autoload_fix');
    // Error Log
    $r('/error-log',                                           'GET',    'xclipse_error_log_read');
    $r('/error-log/clear',                                     'POST',   'xclipse_error_log_clear');
    // .htaccess
    $r('/htaccess',                                            'GET',    'xclipse_htaccess_get');
    $r('/htaccess',                                            'PUT',    'xclipse_htaccess_set');
    // Media advanced
    $r('/media/bulk/delete',                                   'POST',   'xclipse_media_bulk_delete');
    $r('/media/unused',                                        'GET',    'xclipse_media_search_unused');
    $r('/media/regenerate',                                    'POST',   'xclipse_media_regenerate');
    $r('/media/(?P<id>\d+)/set-featured',                     'POST',   'xclipse_media_set_featured');
    // Content advanced
    $r('/posts/bulk/delete',                                   'POST',   'xclipse_posts_bulk_delete');
    $r('/content/find-replace',                                'POST',   'xclipse_content_find_replace');
    $r('/content/word-count-audit',                            'GET',    'xclipse_content_word_count_audit');
    // Sitemap
    $r('/sitemap',                                             'GET',    'xclipse_sitemap_get');
    $r('/sitemap/ping',                                        'POST',   'xclipse_sitemap_ping');
    // RankMath advanced
    $r('/rankmath/redirects/(?P<id>\d+)',                      'PUT',    'xclipse_rm_redirect_update');
    $r('/rankmath/analytics',                                  'GET',    'xclipse_rm_analytics');
    // Audit advanced
    $r('/audit/404-log',                                       'GET',    'xclipse_audit_404_log');
    $r('/audit/schema-coverage',                               'GET',    'xclipse_audit_schema_coverage');
    // WC gaps
    $r('/products/(?P<id>\d+)',                                'DELETE', 'xclipse_product_delete');
    $r('/product-categories/(?P<id>\d+)',                      'PUT',    'xclipse_product_cat_update');
    $r('/product-categories/(?P<id>\d+)',                      'DELETE', 'xclipse_product_cat_delete');
    $r('/coupons/(?P<id>\d+)',                                 'GET',    'xclipse_coupon_get');
    $r('/coupons/bulk',                                        'POST',   'xclipse_wc_coupon_bulk_create');
    $r('/wc/reports/orders-total',                             'GET',    'xclipse_wc_report_orders_total');
    $r('/wc/reports/customers-total',                          'GET',    'xclipse_wc_report_customers_total');
    $r('/wc/shipping/zones/(?P<id>\d+)/locations',             'PUT',    'xclipse_shipping_zone_locations_update');
    $r('/wc/shipping/methods',                                 'GET',    'xclipse_shipping_methods_list');
    $r('/wc/orders/export',                                    'GET',    'xclipse_wc_orders_export');
    $r('/wc/products/import',                                  'POST',   'xclipse_wc_product_import');
    $r('/customers/(?P<id>\d+)/downloads',                     'GET',    'xclipse_customer_downloads_list');

    // ── Elementor (v4.6.15) ───────────────────────────────────────────────────
    $r('/elementor/(?P<id>\d+)/detect',                              'GET',    'xclipse_el_detect');
    $r('/elementor/(?P<id>\d+)/data',                                'GET',    'xclipse_el_page_get');
    $r('/elementor/(?P<id>\d+)/data',                                'PUT',    'xclipse_el_page_set');
    $r('/elementor/(?P<id>\d+)/page-settings',                       'GET',    'xclipse_el_page_settings_get');
    $r('/elementor/(?P<id>\d+)/page-settings',                       'PUT',    'xclipse_el_page_settings_set');
    $r('/elementor/(?P<id>\d+)/elements/(?P<el_id>[a-f0-9]{8})',     'GET',    'xclipse_el_element_get');
    $r('/elementor/(?P<id>\d+)/elements',                            'POST',   'xclipse_el_element_add');
    $r('/elementor/(?P<id>\d+)/elements/(?P<el_id>[a-f0-9]{8})',     'PUT',    'xclipse_el_element_update');
    $r('/elementor/(?P<id>\d+)/elements/(?P<el_id>[a-f0-9]{8})',     'DELETE', 'xclipse_el_element_delete');
    $r('/elementor/(?P<id>\d+)/elements/move',                       'POST',   'xclipse_el_element_move');
    $r('/elementor/(?P<id>\d+)/elements/(?P<el_id>[a-f0-9]{8})/duplicate', 'POST', 'xclipse_el_element_duplicate');
    $r('/elementor/templates',                                        'GET',    'xclipse_el_template_list');
    $r('/elementor/templates',                                        'POST',   'xclipse_el_template_create');
    $r('/elementor/templates/(?P<id>\d+)',                            'GET',    'xclipse_el_template_get');
    $r('/elementor/widgets',                                          'GET',    'xclipse_el_widgets_list');
    $r('/elementor/flush-css',                                        'POST',   'xclipse_el_flush_css');

    // ── Diagnostic Tools (v4.7.1) ─────────────────────────────────────────────
    // Tier 1: Ajax/REST probe
    $r('/diag/ajax-probe',                'POST', 'xclipse_diag_ajax_probe');
    $r('/diag/rest-probe',                'POST', 'xclipse_diag_rest_probe');
    // Tier 1: PHP config
    $r('/diag/php-info',                  'GET',  'xclipse_diag_php_info');
    // Tier 1: Error log search (with since/pattern)
    $r('/diag/error-log-search',          'GET',  'xclipse_diag_error_log_search');
    // Tier 1: Cache (multi-plugin)
    $r('/diag/cache-status',              'GET',  'xclipse_diag_cache_status');
    $r('/diag/cache-purge',               'POST', 'xclipse_diag_cache_purge');
    // Tier 2: File read/edit (PHP lint gate + backup)
    $r('/diag/file-read',                 'GET',  'xclipse_diag_file_read');
    $r('/diag/file-edit',                 'POST', 'xclipse_diag_file_edit');
    // Tier 2: Troubleshoot mode (session-isolated plugin toggle)
    $r('/diag/troubleshoot',              'POST', 'xclipse_diag_troubleshoot');
    // Tier 3: DB query (SELECT only) + describe
    $r('/diag/db-query',                  'GET',  'xclipse_diag_db_query');
    $r('/diag/db-describe',               'GET',  'xclipse_diag_db_describe');

    // ── v4.8.0: Batch, Snapshot, Changelog ───────────────────────────────────
    $r('/batch',              'POST', 'xclipse_batch');
    $r('/snapshot',           'GET',  'xclipse_site_snapshot');
    $r('/changelog',          'GET',  'xclipse_changelog_get');
    $r('/changelog/clear',    'POST', 'xclipse_changelog_clear');
    // ── v4.9.0: ACF, media base64, WP Mail SMTP ──
    $r('/media/upload-base64', 'POST', 'xclipse_media_upload_base64');
    $r('/acf/groups', 'GET', 'xclipse_acf_groups');
    $r('/acf/groups/(?P<group_key>group_[a-z0-9]+)/fields', 'GET', 'xclipse_acf_group_fields');
    $r('/acf/(?P<id>\d+)', 'GET', 'xclipse_acf_get');
    $r('/acf/(?P<id>\d+)', 'PUT', 'xclipse_acf_set');
    $r('/acf/(?P<id>\d+)/fields', 'GET', 'xclipse_acf_fields');
    $r('/wpms/status', 'GET', 'xclipse_wpms_status');
    $r('/wpms/mailers', 'GET', 'xclipse_wpms_mailers');
    $r('/wpms/settings', 'GET', 'xclipse_wpms_settings_get');
    $r('/wpms/settings', 'PUT', 'xclipse_wpms_settings_set');
    $r('/wpms/test-email', 'POST', 'xclipse_wpms_test_email');
    $r('/wpms/debug-events', 'GET', 'xclipse_wpms_debug_events');
    $r('/wpms/debug-events', 'DELETE', 'xclipse_wpms_debug_events_delete');
    $r('/wpms/debug-events/(?P<id>\d+)', 'GET', 'xclipse_wpms_debug_event_get');
    $r('/wpms/send-errors', 'GET', 'xclipse_wpms_send_errors');
    $r('/wpms/send-errors', 'DELETE', 'xclipse_wpms_send_errors_clear');
});