<?php
/**
 * WordPress Advanced Tools — v4.6.6
 * Covers: user_delete, comment_create, revisions, options, transients, cron,
 *         plugin/theme management, db_optimize, error_log, htaccess,
 *         media advanced, content find/replace, word-count audit,
 *         sitemap, rankmath redirect update, rankmath analytics,
 *         WC gaps (product_delete, cat CRUD, coupon_get, reports, shipping location),
 *         WC bulk coupon, orders export, product import, customer downloads
 */
defined('ABSPATH') || exit;

// USER EXTENDED

function xclipse_user_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    if ($id === get_current_user_id()) return xclipse_err('self_delete', 'Không thể xóa chính mình', 400);
    $reassign = (int)($r->get_param('reassign') ?? 0);
    if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
    $ok = wp_delete_user($id, $reassign ?: null);
    return $ok ? xclipse_ok(['ok' => true, 'deleted' => $id]) : xclipse_err('delete_failed', 'Không xóa được user');
}

function xclipse_users_bulk_delete(WP_REST_Request $r) {
    $b        = $r->get_json_params();
    $ids      = array_map('intval', $b['ids'] ?? []);
    $reassign = (int)($b['reassign'] ?? 0);
    if (!$ids) return xclipse_err('missing_ids', 'Cần ids[]', 400);
    if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
    $deleted = 0;
    $self    = get_current_user_id();
    foreach ($ids as $id) {
        if ($id === $self) continue;
        if (wp_delete_user($id, $reassign ?: null)) $deleted++;
    }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted]);
}

// COMMENT CREATE

function xclipse_comment_create(WP_REST_Request $r) {
    $b = $r->get_json_params();
    if (empty($b['content']) || empty($b['post_id'])) return xclipse_err('missing_fields', 'content và post_id là bắt buộc', 400);
    $id = wp_insert_comment([
        'comment_post_ID'      => (int)$b['post_id'],
        'comment_content'      => wp_kses_post($b['content']),
        'comment_author'       => sanitize_text_field($b['author'] ?? 'Admin'),
        'comment_author_email' => sanitize_email($b['author_email'] ?? get_option('admin_email')),
        'comment_approved'     => $b['approved'] ?? 1,
        'comment_type'         => sanitize_text_field($b['type'] ?? ''),
        'user_id'              => (int)($b['user_id'] ?? 0),
        'comment_parent'       => (int)($b['parent'] ?? 0),
    ]);
    return $id ? xclipse_ok(['ok' => true, 'id' => $id]) : xclipse_err('create_failed', 'Không tạo được comment');
}

// REVISIONS

function xclipse_post_revisions_list(WP_REST_Request $r) {
    $revisions = wp_get_post_revisions((int)$r['id'], ['order' => 'DESC']);
    $list = array_values(array_map(fn($rev) => [
        'id'       => $rev->ID,
        'date'     => substr($rev->post_modified, 0, 16),
        'author'   => get_the_author_meta('display_name', $rev->post_author),
        'title'    => $rev->post_title,
        'excerpt'  => mb_substr(wp_strip_all_tags($rev->post_content), 0, 150),
    ], $revisions));
    return xclipse_ok(['total' => count($list), 'revisions' => $list]);
}

function xclipse_post_revision_restore(WP_REST_Request $r) {
    $post_id     = (int)$r['id'];
    $revision_id = (int)$r['revision_id'];
    $revision    = get_post($revision_id);
    if (!$revision || $revision->post_parent != $post_id) return xclipse_err('not_found', 'Revision không tồn tại hoặc không thuộc post này');
    $restored = wp_restore_post_revision($revision_id);
    return $restored ? xclipse_ok(['ok' => true, 'restored_revision' => $revision_id, 'post_id' => $post_id]) : xclipse_err('restore_failed', 'Khôi phục thất bại');
}

// WP OPTIONS (mở rộng — không whitelist cứng)

// Danh sách options bị block (nguy hiểm)
const XCLIPSE_BLOCKED_OPTS = [
    'xclipse_mcp_api_key', 'auth_key', 'secure_auth_key', 'logged_in_key',
    'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    'admin_password', 'default_password_nag',
];

function xclipse_options_get(WP_REST_Request $r) {
    $keys = array_filter(array_map('sanitize_key', explode(',', $r->get_param('keys') ?? '')));
    if (!$keys) return xclipse_err('missing_keys', 'Cần ?keys=key1,key2,...', 400);
    $blocked = array_filter($keys, fn($k) => in_array($k, XCLIPSE_BLOCKED_OPTS, true));
    if ($blocked) return xclipse_err('blocked', 'Key bị chặn: ' . implode(',', $blocked), 403);
    $out = [];
    foreach ($keys as $k) $out[$k] = get_option($k);
    return xclipse_ok(['options' => $out]);
}

function xclipse_options_set(WP_REST_Request $r) {
    $b  = $r->get_json_params();
    $ok = []; $skip = [];
    foreach (($b['options'] ?? []) as $k => $v) {
        $k = sanitize_key($k);
        if (in_array($k, XCLIPSE_BLOCKED_OPTS, true)) { $skip[] = $k; continue; }
        update_option($k, $v);
        $ok[$k] = get_option($k);
    }
    return xclipse_ok(['updated' => $ok, 'skipped' => $skip]);
}

function xclipse_options_delete(WP_REST_Request $r) {
    $b    = $r->get_json_params();
    $keys = array_filter(array_map('sanitize_key', $b['keys'] ?? []));
    $done = []; $skip = [];
    foreach ($keys as $k) {
        if (in_array($k, XCLIPSE_BLOCKED_OPTS, true)) { $skip[] = $k; continue; }
        delete_option($k);
        $done[] = $k;
    }
    return xclipse_ok(['deleted' => $done, 'skipped' => $skip]);
}

// TRANSIENTS

function xclipse_transients_list(WP_REST_Request $r) {
    global $wpdb;
    $limit  = min((int)($r->get_param('limit') ?? 50), 200);
    $search = sanitize_text_field($r->get_param('search') ?? '');
    $sql    = "SELECT option_name, option_value, LENGTH(option_value) as size
               FROM {$wpdb->options}
               WHERE option_name LIKE '_transient_%'
               AND option_name NOT LIKE '_transient_timeout_%'";
    if ($search) $sql .= $wpdb->prepare(" AND option_name LIKE %s", '%' . $wpdb->esc_like($search) . '%');
    $sql .= " ORDER BY size DESC LIMIT {$limit}";
    $rows = $wpdb->get_results($sql, ARRAY_A);
    // Gắn expiry
    $list = array_map(function($row) use ($wpdb) {
        $key     = str_replace('_transient_', '', $row['option_name']);
        $timeout = get_option("_transient_timeout_{$key}");
        return [
            'key'     => $key,
            'size'    => (int)$row['size'],
            'expires' => $timeout ? date('Y-m-d H:i:s', $timeout) : 'no_expiry',
            'expired' => $timeout && $timeout < time(),
        ];
    }, $rows);
    return xclipse_ok(['total' => count($list), 'transients' => $list]);
}

function xclipse_transients_delete(WP_REST_Request $r) {
    $b    = $r->get_json_params();
    $keys = $b['keys'] ?? [];
    $mode = $b['mode'] ?? 'specific'; // specific | expired | all
    global $wpdb;
    if ($mode === 'expired') {
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < " . time());
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'
                      AND (option_name LIKE '_transient_timeout_%' AND option_value < " . time() . "
                           OR option_name NOT LIKE '_transient_timeout_%'
                           AND REPLACE(option_name,'_transient_','') IN
                               (SELECT REPLACE(option_name,'_transient_timeout_','') FROM {$wpdb->options}
                                WHERE option_name LIKE '_transient_timeout_%' AND option_value < " . time() . "))");
        return xclipse_ok(['ok' => true, 'mode' => 'expired', 'deleted_approx' => $count]);
    }
    if ($mode === 'all') {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        return xclipse_ok(['ok' => true, 'mode' => 'all']);
    }
    $deleted = 0;
    foreach ($keys as $key) { if (delete_transient($key)) $deleted++; }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted]);
}

// WP CRON

function xclipse_cron_list() {
    $crons = _get_cron_array();
    $list  = [];
    foreach ($crons as $timestamp => $hooks) {
        foreach ($hooks as $hook => $events) {
            foreach ($events as $key => $event) {
                $list[] = [
                    'hook'      => $hook,
                    'next_run'  => date('Y-m-d H:i:s', $timestamp),
                    'overdue'   => $timestamp < time(),
                    'interval'  => $event['schedule'] ?: 'once',
                    'seconds'   => $event['interval'] ?? 0,
                    'args'      => $event['args'],
                ];
            }
        }
    }
    usort($list, fn($a,$b) => strcmp($a['next_run'], $b['next_run']));
    return xclipse_ok(['total' => count($list), 'crons' => $list]);
}

function xclipse_cron_run(WP_REST_Request $r) {
    $hook = sanitize_key($r->get_param('hook') ?? '');
    if (!$hook) return xclipse_err('missing_hook', 'Cần hook name', 400);
    do_action($hook);
    return xclipse_ok(['ok' => true, 'executed' => $hook, 'time' => date('Y-m-d H:i:s')]);
}

function xclipse_cron_delete(WP_REST_Request $r) {
    $hook = sanitize_key($r->get_param('hook') ?? '');
    if (!$hook) return xclipse_err('missing_hook', 'Cần hook name', 400);
    wp_clear_scheduled_hook($hook);
    return xclipse_ok(['ok' => true, 'cleared' => $hook]);
}

// PLUGIN MANAGEMENT

function xclipse_plugin_activate(WP_REST_Request $r) {
    if (!function_exists('activate_plugin')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $file   = sanitize_text_field($r->get_param('plugin') ?? '');
    $result = activate_plugin($file);
    if (is_wp_error($result)) return xclipse_err('activate_failed', $result->get_error_message());
    return xclipse_ok(['ok' => true, 'activated' => $file]);
}

function xclipse_plugin_deactivate(WP_REST_Request $r) {
    if (!function_exists('deactivate_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $file = sanitize_text_field($r->get_param('plugin') ?? '');
    deactivate_plugins($file);
    return xclipse_ok(['ok' => true, 'deactivated' => $file]);
}

function xclipse_plugin_delete(WP_REST_Request $r) {
    if (!function_exists('delete_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!function_exists('deactivate_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $file = sanitize_text_field($r->get_param('plugin') ?? '');
    deactivate_plugins($file);
    $result = delete_plugins([$file]);
    if (is_wp_error($result)) return xclipse_err('delete_failed', $result->get_error_message());
    return xclipse_ok(['ok' => true, 'deleted' => $file]);
}

function xclipse_plugin_install(WP_REST_Request $r) {
    $b = $r->get_json_params();
    $slug = sanitize_text_field($b['slug'] ?? '');
    if (!$slug) return xclipse_err('missing_slug', 'Cần slug plugin WordPress.org', 400);
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    if (is_wp_error($api)) return xclipse_err('not_found', "Plugin '{$slug}' không tìm thấy trên WordPress.org");
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result   = $upgrader->install($api->download_link);
    if (is_wp_error($result)) return xclipse_err('install_failed', $result->get_error_message());
    return xclipse_ok(['ok' => true, 'installed' => $slug, 'version' => $api->version ?? '']);
}

// THEME MANAGEMENT

function xclipse_theme_activate(WP_REST_Request $r) {
    $stylesheet = sanitize_text_field($r->get_param('stylesheet') ?? '');
    if (!$stylesheet) return xclipse_err('missing_stylesheet', 'Cần stylesheet (theme folder name)', 400);
    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) return xclipse_err('not_found', "Theme '{$stylesheet}' không tồn tại");
    switch_theme($stylesheet);
    return xclipse_ok(['ok' => true, 'activated' => $stylesheet, 'name' => $theme->get('Name')]);
}

function xclipse_theme_install(WP_REST_Request $r) {
    $b    = $r->get_json_params();
    $slug = sanitize_text_field($b['slug'] ?? '');
    if (!$slug) return xclipse_err('missing_slug', 'Cần slug theme WordPress.org', 400);
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/theme-install.php';
    $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    if (is_wp_error($api)) return xclipse_err('not_found', "Theme '{$slug}' không tìm thấy");
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Theme_Upgrader($skin);
    $result   = $upgrader->install($api->download_link);
    if (is_wp_error($result)) return xclipse_err('install_failed', $result->get_error_message());
    return xclipse_ok(['ok' => true, 'installed' => $slug, 'version' => $api->version ?? '']);
}

// DATABASE TOOLS

function xclipse_db_optimize() {
    global $wpdb;
    $tables = $wpdb->get_col("SHOW TABLES");
    $results = [];
    foreach ($tables as $table) {
        $res = $wpdb->get_row("OPTIMIZE TABLE `{$table}`", ARRAY_A);
        $results[] = ['table' => $table, 'status' => $res['Msg_text'] ?? 'ok'];
    }
    return xclipse_ok(['ok' => true, 'tables' => count($results), 'results' => $results]);
}

function xclipse_autoload_fix() {
    global $wpdb;
    // Tìm autoloaded options lớn nhất
    $heavy = $wpdb->get_results("
        SELECT option_name, LENGTH(option_value) as size, autoload
        FROM {$wpdb->options}
        WHERE autoload = 'yes'
        AND LENGTH(option_value) > 10000
        ORDER BY size DESC
        LIMIT 30
    ", ARRAY_A);

    // Tính tổng autoload size
    $total = (int)$wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'");
    $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload='yes'");

    // Danh sách safe to disable autoload (transients, sessions, etc.)
    $safe_patterns = ['_transient_', '_site_transient_', 'wp_session_', 'woocommerce_session_'];
    $disabled = 0;
    foreach ($safe_patterns as $pattern) {
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload='yes'",
            '%' . $wpdb->esc_like($pattern) . '%'
        ));
        foreach ($rows as $name) {
            $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $name]);
            $disabled++;
        }
    }
    return xclipse_ok([
        'autoload_total_kb' => round($total / 1024, 1),
        'autoload_count'    => $count,
        'disabled_transient_autoloads' => $disabled,
        'heavy_options'     => $heavy,
        'tip'               => 'Xem xét tắt autoload cho các option lớn không cần thiết',
    ]);
}

// ERROR LOG

function xclipse_error_log_read(WP_REST_Request $r) {
    $lines = (int)($r->get_param('lines') ?? 100);
    $log   = WP_CONTENT_DIR . '/debug.log';
    if (!file_exists($log)) {
        // Thử tìm log trong các vị trí phổ biến
        $alt = ABSPATH . 'error_log';
        $log = file_exists($alt) ? $alt : null;
    }
    if (!$log) return xclipse_err('not_found', 'Không tìm thấy debug.log. Bật WP_DEBUG_LOG=true trong wp-config.php');
    $size = filesize($log);
    // Đọc N dòng cuối hiệu quả
    $all_lines = array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$lines);
    return xclipse_ok([
        'path'    => str_replace(ABSPATH, '', $log),
        'size_kb' => round($size / 1024, 1),
        'lines'   => count($all_lines),
        'log'     => $all_lines,
    ]);
}

function xclipse_error_log_clear() {
    $log = WP_CONTENT_DIR . '/debug.log';
    if (!file_exists($log)) return xclipse_ok(['ok' => true, 'msg' => 'Không có log file']);
    file_put_contents($log, '');
    return xclipse_ok(['ok' => true, 'cleared' => str_replace(ABSPATH, '', $log)]);
}

// HTACCESS

function xclipse_htaccess_get() {
    $path = ABSPATH . '.htaccess';
    if (!file_exists($path)) return xclipse_err('not_found', '.htaccess không tồn tại (có thể dùng Nginx)');
    return xclipse_ok(['content' => file_get_contents($path), 'path' => '.htaccess', 'size' => filesize($path)]);
}

function xclipse_htaccess_set(WP_REST_Request $r) {
    $b       = $r->get_json_params();
    $content = $b['content'] ?? '';
    $path    = ABSPATH . '.htaccess';
    // Backup trước khi ghi
    if (file_exists($path)) copy($path, $path . '.bak.' . date('Ymd-His'));
    $ok = file_put_contents($path, $content);
    return $ok !== false ? xclipse_ok(['ok' => true, 'bytes_written' => $ok]) : xclipse_err('write_failed', 'Không ghi được .htaccess — kiểm tra quyền file');
}

// MEDIA ADVANCED

function xclipse_media_bulk_delete(WP_REST_Request $r) {
    $b     = $r->get_json_params();
    $ids   = array_map('intval', $b['ids'] ?? []);
    $force = filter_var($b['force'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if (!$ids) return xclipse_err('missing_ids', 'Cần ids[]', 400);
    $deleted = 0;
    foreach ($ids as $id) {
        if (wp_delete_attachment($id, $force)) $deleted++;
    }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted]);
}

function xclipse_media_search_unused(WP_REST_Request $r) {
    global $wpdb;
    $limit = min((int)($r->get_param('limit') ?? 50), 200);
    // Lấy tất cả attachment IDs
    $attachments = $wpdb->get_results("
        SELECT ID, post_title, guid, post_date
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment' AND post_status = 'inherit'
        ORDER BY post_date ASC
        LIMIT {$limit}
    ", ARRAY_A);

    $unused = [];
    foreach ($attachments as $att) {
        $id  = $att['ID'];
        $url = wp_get_attachment_url($id);
        if (!$url) continue;
        // Kiểm tra thumbnail usage
        $thumb_of = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%d", $id
        ));
        // Kiểm tra content usage (không hoàn toàn chính xác nhưng hợp lý)
        $file    = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $in_content = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_content LIKE %s",
            '%' . $wpdb->esc_like($file) . '%'
        ));
        if (!$thumb_of && !$in_content) {
            $unused[] = [
                'id'    => $id,
                'title' => $att['post_title'],
                'url'   => $url,
                'date'  => substr($att['post_date'], 0, 10),
                'size'  => filesize(get_attached_file($id)) ?: 0,
            ];
        }
    }
    $total_size = array_sum(array_column($unused, 'size'));
    return xclipse_ok([
        'total_unused' => count($unused),
        'total_size_mb'=> round($total_size / 1024 / 1024, 2),
        'items'        => $unused,
    ]);
}

function xclipse_media_regenerate(WP_REST_Request $r) {
    $b   = $r->get_json_params();
    $ids = array_map('intval', $b['ids'] ?? []);
    $limit = min((int)($b['limit'] ?? 20), 100);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    if (!$ids) {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' LIMIT {$limit}"
        ));
    }
    $done = 0; $errors = [];
    foreach ($ids as $id) {
        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) { $errors[] = $id; continue; }
        $meta = wp_generate_attachment_metadata($id, $file);
        if (!is_wp_error($meta)) { wp_update_attachment_metadata($id, $meta); $done++; }
        else $errors[] = $id;
    }
    return xclipse_ok(['ok' => true, 'regenerated' => $done, 'errors' => $errors]);
}

function xclipse_media_set_featured(WP_REST_Request $r) {
    $b          = $r->get_json_params();
    $post_id    = (int)($b['post_id'] ?? $r->get_param('post_id') ?? 0);
    $attach_id  = (int)($b['attach_id'] ?? $r->get_param('attach_id') ?? 0);
    if (!$post_id || !$attach_id) return xclipse_err('missing_params', 'post_id và attach_id là bắt buộc', 400);
    $ok = set_post_thumbnail($post_id, $attach_id);
    return $ok ? xclipse_ok(['ok' => true, 'post_id' => $post_id, 'featured_image' => $attach_id]) : xclipse_err('failed', 'Không set được featured image');
}

// CONTENT ADVANCED

function xclipse_posts_bulk_delete(WP_REST_Request $r) {
    $b     = $r->get_json_params();
    $ids   = array_map('intval', $b['ids'] ?? []);
    $force = filter_var($b['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$ids) return xclipse_err('missing_ids', 'Cần ids[]', 400);
    $deleted = 0;
    foreach ($ids as $id) {
        if (wp_delete_post($id, $force)) $deleted++;
    }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted, 'force' => $force]);
}

function xclipse_content_find_replace(WP_REST_Request $r) {
    $b        = $r->get_json_params();
    $find     = $b['find'] ?? '';
    $replace  = $b['replace'] ?? '';
    $types    = $b['post_types'] ?? ['post', 'page'];
    $dry_run  = filter_var($b['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $limit    = min((int)($b['limit'] ?? 100), 500);
    if (!$find) return xclipse_err('missing_find', 'Cần find text', 400);

    global $wpdb;
    $type_list = implode("','", array_map('sanitize_key', $types));
    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_content FROM {$wpdb->posts}
         WHERE post_type IN ('{$type_list}') AND post_status='publish'
         AND post_content LIKE '%" . $wpdb->esc_like($find) . "%'
         LIMIT {$limit}",
        ARRAY_A
    );
    $updated = 0;
    $preview = [];
    foreach ($posts as $p) {
        $new_content = str_replace($find, $replace, $p['post_content']);
        $new_title   = str_replace($find, $replace, $p['post_title']);
        if (!$dry_run) {
            $wpdb->update($wpdb->posts,
                ['post_content' => $new_content, 'post_title' => $new_title],
                ['ID' => $p['ID']]
            );
            clean_post_cache($p['ID']);
        }
        $preview[] = ['id' => $p['ID'], 'title' => $p['post_title']];
        $updated++;
    }
    return xclipse_ok([
        'dry_run'  => $dry_run,
        'find'     => $find,
        'replace'  => $replace,
        'affected' => $updated,
        'posts'    => $preview,
    ]);
}

function xclipse_content_word_count_audit(WP_REST_Request $r) {
    $min_words = (int)($r->get_param('min_words') ?? 300);
    $post_type = sanitize_key($r->get_param('post_type') ?? 'post');
    $limit     = min((int)($r->get_param('limit') ?? 100), 500);

    $args  = ['post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => $limit, 'no_found_rows' => true];
    $query = new WP_Query($args);
    $short = [];
    foreach ($query->posts as $p) {
        $words = str_word_count(wp_strip_all_tags($p->post_content));
        if ($words < $min_words) $short[] = [
            'id'        => $p->ID,
            'title'     => $p->post_title,
            'url'       => get_permalink($p->ID),
            'words'     => $words,
            'gap'       => $min_words - $words,
            'modified'  => substr($p->post_modified, 0, 10),
        ];
    }
    usort($short, fn($a,$b) => $a['words'] <=> $b['words']);
    return xclipse_ok([
        'min_words'  => $min_words,
        'total_short'=> count($short),
        'items'      => $short,
    ]);
}

// SITEMAP

function xclipse_sitemap_get() {
    // RankMath sitemap
    $url = home_url('/sitemap_index.xml');
    $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
    if (is_wp_error($response)) return xclipse_err('fetch_failed', $response->get_error_message());
    $xml   = wp_remote_retrieve_body($response);
    $code  = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        // Thử Yoast
        $url      = home_url('/sitemap.xml');
        $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
        $xml      = wp_remote_retrieve_body($response);
        $code     = wp_remote_retrieve_response_code($response);
    }
    // Parse sitemaps
    $sitemaps = [];
    if ($xml) {
        preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $matches);
        $sitemaps = $matches[1] ?? [];
    }
    return xclipse_ok(['url' => $url, 'http_status' => $code, 'sitemaps' => $sitemaps, 'count' => count($sitemaps)]);
}

function xclipse_sitemap_ping(WP_REST_Request $r) {
    $sitemap_url = urlencode(home_url('/sitemap_index.xml'));
    $engines     = [
        'google' => "https://www.google.com/ping?sitemap={$sitemap_url}",
        'bing'   => "https://www.bing.com/ping?sitemap={$sitemap_url}",
    ];
    $results = [];
    foreach ($engines as $name => $url) {
        $res = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false]);
        $results[$name] = [
            'url'    => $url,
            'status' => is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res),
        ];
    }
    return xclipse_ok(['ok' => true, 'results' => $results, 'sitemap' => urldecode($sitemap_url)]);
}

// RANKMATH REDIRECT UPDATE

function xclipse_rm_redirect_update(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rankmath', 'RankMath chưa cài', 501);
    global $wpdb;
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $table = $wpdb->prefix . 'rank_math_redirections';
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!$row) return xclipse_err('not_found', 'Redirect không tồn tại');
    $data = [];
    if (isset($b['url_to']))   $data['url_to']   = esc_url_raw($b['url_to']);
    if (isset($b['header']))   $data['header']    = (int)$b['header'];
    if (isset($b['status']))   $data['status']    = sanitize_text_field($b['status']);
    if (!$data) return xclipse_err('no_data', 'Không có dữ liệu để update', 400);
    $wpdb->update($table, $data, ['id' => $id]);
    return xclipse_ok(['ok' => true, 'id' => $id, 'updated' => $data]);
}

// RANKMATH ANALYTICS

function xclipse_rm_analytics(WP_REST_Request $r) {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rankmath', 'RankMath chưa cài', 501);
    global $wpdb;
    $days = (int)($r->get_param('days') ?? 30);
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $table_seo = $wpdb->prefix . 'rank_math_analytics_gsc';
    // Kiểm tra bảng tồn tại
    if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_seo}'")) {
        return xclipse_err('no_analytics', 'Bảng analytics chưa có — Kết nối Google Search Console trong RankMath Analytics');
    }
    $summary = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(impressions) as impressions,
            SUM(clicks)      as clicks,
            AVG(ctr)         as avg_ctr,
            AVG(position)    as avg_position
        FROM {$table_seo}
        WHERE created >= %s
    ", $from), ARRAY_A);

    $top_pages = $wpdb->get_results($wpdb->prepare("
        SELECT page as url, SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as avg_pos
        FROM {$table_seo}
        WHERE created >= %s
        GROUP BY page ORDER BY clicks DESC LIMIT 10
    ", $from), ARRAY_A);

    return xclipse_ok([
        'period_days' => $days,
        'from'        => $from,
        'summary'     => [
            'impressions'  => (int)($summary['impressions'] ?? 0),
            'clicks'       => (int)($summary['clicks'] ?? 0),
            'avg_ctr'      => round((float)($summary['avg_ctr'] ?? 0), 2),
            'avg_position' => round((float)($summary['avg_position'] ?? 0), 1),
        ],
        'top_pages' => $top_pages,
    ]);
}

// AUDIT ADVANCED

function xclipse_audit_404_log() {
    if (!defined('RANK_MATH_VERSION')) return xclipse_err('no_rankmath', 'Cần RankMath', 501);
    global $wpdb;
    $table = $wpdb->prefix . 'rank_math_404_logs';
    if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) return xclipse_err('no_table', 'Bật RM 404 Monitor trước');
    $rows = $wpdb->get_results("SELECT url, COUNT(*) as hits, MAX(accessed) as last_seen FROM {$table} GROUP BY url ORDER BY hits DESC LIMIT 50", ARRAY_A);
    return xclipse_ok(['total' => count($rows), 'logs' => $rows]);
}

function xclipse_audit_schema_coverage() {
    global $wpdb;
    $total   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','product','page') AND post_status='publish'");
    $with_rm = (int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='rank_math_schema_type'");
    $with_ld = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_content LIKE '%application/ld+json%'");
    $without = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_type
        FROM {$wpdb->posts} p
        WHERE p.post_type IN ('post','product') AND p.post_status='publish'
        AND p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='rank_math_schema_type')
        LIMIT 30
    ", ARRAY_A);
    return xclipse_ok([
        'total_posts'      => $total,
        'with_rm_schema'   => $with_rm,
        'with_json_ld'     => $with_ld,
        'coverage_pct'     => $total ? round($with_rm / $total * 100, 1) : 0,
        'missing_sample'   => $without,
    ]);
}

// WOOCOMMERCE GAPS

function xclipse_product_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Sản phẩm không tồn tại');
    $force = filter_var($r->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
    $p->delete($force);
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id'], 'force' => $force]);
}

function xclipse_product_cat_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b   = $r->get_json_params();
    $res = wp_update_term((int)$r['id'], 'product_cat', [
        'name'        => sanitize_text_field($b['name'] ?? ''),
        'slug'        => sanitize_title($b['slug'] ?? ''),
        'description' => sanitize_textarea_field($b['description'] ?? ''),
        'parent'      => (int)($b['parent'] ?? 0),
    ]);
    if (is_wp_error($res)) return xclipse_err('update_failed', $res->get_error_message());
    if (isset($b['image_id'])) update_term_meta($res['term_id'], 'thumbnail_id', (int)$b['image_id']);
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

function xclipse_product_cat_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $res = wp_delete_term((int)$r['id'], 'product_cat');
    if (is_wp_error($res)) return xclipse_err('delete_failed', $res->get_error_message());
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}

function xclipse_coupon_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $coupon = new WC_Coupon((int)$r['id']);
    if (!$coupon->get_id()) return xclipse_err('not_found', 'Coupon không tồn tại');
    return xclipse_ok([
        'id'                       => $coupon->get_id(),
        'code'                     => $coupon->get_code(),
        'type'                     => $coupon->get_discount_type(),
        'amount'                   => $coupon->get_amount(),
        'description'              => $coupon->get_description(),
        'expiry'                   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
        'usage_count'              => $coupon->get_usage_count(),
        'usage_limit'              => $coupon->get_usage_limit(),
        'min_amount'               => $coupon->get_minimum_amount(),
        'max_amount'               => $coupon->get_maximum_amount(),
        'free_shipping'            => $coupon->get_free_shipping(),
        'individual_use'           => $coupon->get_individual_use(),
        'exclude_sale_items'       => $coupon->get_exclude_sale_items(),
        'product_ids'              => $coupon->get_product_ids(),
        'excluded_product_ids'     => $coupon->get_excluded_product_ids(),
        'product_categories'       => $coupon->get_product_categories(),
    ]);
}

function xclipse_wc_coupon_bulk_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b       = $r->get_json_params();
    $coupons = $b['coupons'] ?? [];
    if (!$coupons) return xclipse_err('missing_coupons', 'Cần truyền coupons[]', 400);
    $created = [];
    foreach ($coupons as $data) {
        $coupon = new WC_Coupon();
        $coupon->set_code(sanitize_text_field($data['code'] ?? wp_generate_password(8, false)));
        $coupon->set_discount_type($data['type'] ?? 'percent');
        $coupon->set_amount((float)($data['amount'] ?? 10));
        if (!empty($data['expiry']))    $coupon->set_date_expires(strtotime($data['expiry']));
        if (!empty($data['limit']))     $coupon->set_usage_limit((int)$data['limit']);
        if (!empty($data['description'])) $coupon->set_description(sanitize_text_field($data['description']));
        $id = $coupon->save();
        $created[] = ['id' => $id, 'code' => $coupon->get_code()];
    }
    return xclipse_ok(['ok' => true, 'created' => count($created), 'coupons' => $created]);
}

function xclipse_wc_report_orders_total() {
    if (($e = xclipse_woo()) !== true) return $e;
    $statuses = ['pending','processing','on-hold','completed','cancelled','refunded','failed','trash'];
    $result   = [];
    foreach ($statuses as $status) {
        $result[$status] = (int)wc_orders_count($status);
    }
    $result['total'] = array_sum($result);
    return xclipse_ok(['orders' => $result]);
}

function xclipse_wc_report_customers_total() {
    if (($e = xclipse_woo()) !== true) return $e;
    global $wpdb;
    $total    = (int)$wpdb->get_var("SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} um ON u.ID=um.user_id WHERE um.meta_key='{$wpdb->prefix}capabilities' AND um.meta_value LIKE '%customer%'");
    $new_30d  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s", date('Y-m-d', strtotime('-30 days'))));
    $with_orders = (int)$wpdb->get_var("SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}wc_orders WHERE type='shop_order' AND status NOT IN('trash','auto-draft')") ?: (int)$wpdb->get_var("SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta} WHERE meta_key='_customer_user' AND meta_value > 0");
    return xclipse_ok([
        'total_customers'       => $total,
        'new_last_30_days'      => $new_30d,
        'customers_with_orders' => $with_orders,
    ]);
}

function xclipse_shipping_zone_locations_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $zone      = new WC_Shipping_Zone((int)$r['id']);
    if (!$zone->get_id() && (int)$r['id'] !== 0) return xclipse_err('not_found', 'Zone không tồn tại');
    $b         = $r->get_json_params();
    $locations = $b['locations'] ?? []; // [{type: "country", code: "VN"}, ...]
    $zone->clear_locations();
    foreach ($locations as $loc) {
        $zone->add_location(sanitize_text_field($loc['code'] ?? ''), sanitize_text_field($loc['type'] ?? 'country'));
    }
    $zone->save();
    return xclipse_ok(['ok' => true, 'zone_id' => $zone->get_id(), 'locations' => $zone->get_zone_locations()]);
}

function xclipse_shipping_methods_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $methods = WC()->shipping()->get_shipping_methods();
    $list    = [];
    foreach ($methods as $id => $method) {
        $list[] = [
            'id'          => $id,
            'title'       => $method->get_method_title(),
            'description' => $method->get_method_description(),
        ];
    }
    return xclipse_ok(['total' => count($list), 'methods' => $list]);
}

function xclipse_wc_orders_export(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $status = sanitize_text_field($r->get_param('status') ?? 'completed');
    $days   = (int)($r->get_param('days') ?? 30);
    $from   = date('Y-m-d', strtotime("-{$days} days"));
    $orders = wc_get_orders([
        'status'     => $status,
        'limit'      => min((int)($r->get_param('limit') ?? 200), 500),
        'date_after' => $from,
        'return'     => 'objects',
    ]);
    $rows = [];
    foreach ($orders as $order) {
        $rows[] = [
            'id'         => $order->get_id(),
            'status'     => $order->get_status(),
            'date'       => ($d = $order->get_date_created()) ? substr($d->date('Y-m-d H:i:s'), 0, 16) : '',
            'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'total'      => $order->get_total(),
            'currency'   => $order->get_currency(),
            'items'      => $order->get_item_count(),
            'payment'    => $order->get_payment_method_title(),
        ];
    }
    return xclipse_ok([
        'period_days' => $days,
        'status'      => $status,
        'total'       => count($rows),
        'orders'      => $rows,
    ]);
}

function xclipse_wc_product_import(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b        = $r->get_json_params();
    $products = $b['products'] ?? [];
    if (!$products) return xclipse_err('missing_products', 'Cần truyền products[]', 400);
    $created = []; $errors = [];
    foreach ($products as $data) {
        try {
            $type = $data['type'] ?? 'simple';
            $p    = $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
            $p->set_name(sanitize_text_field($data['name'] ?? ''));
            if (!empty($data['sku']))          $p->set_sku(sanitize_text_field($data['sku']));
            if (!empty($data['regular_price'])) $p->set_regular_price($data['regular_price']);
            if (!empty($data['sale_price']))   $p->set_sale_price($data['sale_price']);
            if (!empty($data['description']))  $p->set_description(wp_kses_post($data['description']));
            if (!empty($data['short_description'])) $p->set_short_description(wp_kses_post($data['short_description']));
            if (!empty($data['status']))       $p->set_status($data['status']);
            if (isset($data['manage_stock']))  $p->set_manage_stock($data['manage_stock']);
            if (isset($data['stock_quantity'])) $p->set_stock_quantity((int)$data['stock_quantity']);
            if (!empty($data['stock_status'])) $p->set_stock_status($data['stock_status']);
            if (!empty($data['weight']))       $p->set_weight($data['weight']);
            $id = $p->save();
            // Categories
            if (!empty($data['categories'])) {
                wp_set_object_terms($id, array_map('intval', $data['categories']), 'product_cat');
            }
            $created[] = ['id' => $id, 'name' => $data['name'] ?? ''];
        } catch (\Exception $ex) {
            $errors[] = ['name' => $data['name'] ?? '?', 'error' => $ex->getMessage()];
        }
    }
    return xclipse_ok(['created' => count($created), 'errors' => count($errors), 'products' => $created, 'failed' => $errors]);
}

function xclipse_customer_downloads_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $customer_id = (int)$r['id'];
    $customer    = new WC_Customer($customer_id);
    if (!$customer->get_id()) return xclipse_err('not_found', 'Khách hàng không tồn tại');
    $downloads = wc_get_customer_available_downloads($customer_id);
    return xclipse_ok(['total' => count($downloads), 'downloads' => array_map(fn($d) => [
        'download_id'  => $d['download_id'],
        'product_id'   => $d['product_id'],
        'product_name' => $d['product_name'],
        'file_name'    => $d['file']['name'] ?? '',
        'download_url' => $d['download_url'],
        'order_id'     => $d['order_id'],
        'downloads_remaining' => $d['downloads_remaining'],
        'access_expires' => $d['access_expires'] ? $d['access_expires']->date('Y-m-d') : 'unlimited',
    ], $downloads)]);
}
