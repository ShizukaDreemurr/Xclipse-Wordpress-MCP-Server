<?php
/** Pages CRUD + bulk operations */
defined('ABSPATH') || exit;

// PAGES
function xclipse_pages_list(WP_REST_Request $r) {
    $status = sanitize_text_field($r['status'] ?? 'publish');
    $args   = [
        'number'      => min((int)($r['per_page'] ?? 30), 100),
        'post_status' => $status === 'any' ? ['publish','draft','private'] : $status,
        'sort_column' => $r['orderby'] ?? 'post_date',
        'sort_order'  => strtoupper($r['order'] ?? 'DESC'),
    ];
    if (!empty($r['slug']))      $args['post_name__in'] = [sanitize_title($r['slug'])];
    if (!empty($r['search']))    $args['search']        = sanitize_text_field($r['search']);
    if (!empty($r['author_id'])) $args['authors']       = (int)$r['author_id'];
    $pages  = get_pages($args);
    $fields = xclipse_parse_fields($r['fields'] ?? '');
    return xclipse_ok(array_map(fn($p) => xclipse_filter_fields(xclipse_compact([
        'id'          => $p->ID,
        'title'       => $p->post_title,
        'slug'        => $p->post_name,
        'st'          => $p->post_status,
        'url'         => get_permalink($p->ID),
        'parent'      => $p->post_parent ?: null,
        'created_at'  => $p->post_date,
        'modified_at' => $p->post_modified,
        'author'      => xclipse_author_info((int)$p->post_author),
    ]), $fields), $pages ?: []));
}

function xclipse_page_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p || $p->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    // Lấy page template
    $template = get_page_template_slug($p->ID);
    // Lấy featured image
    $thumb = get_the_post_thumbnail_url($p->ID, 'medium') ?: null;
    return xclipse_ok(xclipse_compact([
        'id'          => $p->ID,
        'title'       => $p->post_title,
        'slug'        => $p->post_name,
        'status'      => $p->post_status,
        'created_at'  => $p->post_date,
        'modified_at' => $p->post_modified,
        'url'         => get_permalink($p->ID),
        'parent'      => $p->post_parent ?: null,
        'order'       => (int)$p->menu_order,
        'template'    => $template ?: 'default',
        'excerpt'     => $p->post_excerpt,
        'content'     => $p->post_content,
        'thumb'       => $thumb,
        'author'      => xclipse_author_info((int)$p->post_author),
        'seo'         => xclipse_seo($p->ID),
    ]));
}

function xclipse_page_create(WP_REST_Request $r) {
    $b  = $r->get_json_params();
    $d  = [
        'post_type'    => 'page',
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
        'post_parent'  => (int)($b['parent'] ?? 0),
        'menu_order'   => (int)($b['order'] ?? 0),
        'post_author'  => (int)($b['author_id'] ?? get_current_user_id()),
    ];
    $id = wp_insert_post($d, true);
    if (is_wp_error($id)) return $id;
    // Template
    if (!empty($b['template'])) update_post_meta($id, '_wp_page_template', sanitize_text_field($b['template']));
    // Featured image
    if (!empty($b['thumbnail_id'])) set_post_thumbnail($id, (int)$b['thumbnail_id']);
    // SEO
    xclipse_save_seo($id, $b);
    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_page_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p || $p->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    $b  = $r->get_json_params();
    $d  = ['ID' => $id];
    if (isset($b['title']))     $d['post_title']   = sanitize_text_field($b['title']);
    if (isset($b['content']))   $d['post_content'] = wp_kses_post($b['content']);
    if (isset($b['excerpt']))   $d['post_excerpt'] = sanitize_text_field($b['excerpt']);
    if (isset($b['status']))    $d['post_status']  = sanitize_text_field($b['status']);
    if (isset($b['slug']))      $d['post_name']    = sanitize_title($b['slug']);
    if (isset($b['parent']))    $d['post_parent']  = (int)$b['parent'];
    if (isset($b['order']))     $d['menu_order']   = (int)$b['order'];
    if (isset($b['author_id'])) $d['post_author']  = (int)$b['author_id'];
    $res = wp_update_post($d, true);
    if (is_wp_error($res)) return $res;
    if (isset($b['template']))     update_post_meta($id, '_wp_page_template', sanitize_text_field($b['template']));
    if (isset($b['thumbnail_id'])) {
        $b['thumbnail_id'] ? set_post_thumbnail($id, (int)$b['thumbnail_id']) : delete_post_thumbnail($id);
    }
    xclipse_save_seo($id, $b);
    return xclipse_ok([
        'ok'  => true,
        'id'  => $id,
        'url' => get_permalink($id),
    ]);
}

function xclipse_page_delete(WP_REST_Request $r) {
    $id    = (int)$r['id'];
    $force = (bool)($r->get_param('force') ?? false);
    $p     = get_post($id);
    if (!$p || $p->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    $force ? wp_delete_post($id, true) : wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => $force ? 'deleted' : 'trashed']);
}

function xclipse_pages_bulk_update(WP_REST_Request $r) {
    $b        = $r->get_json_params();
    $ids      = array_map('intval', (array)($b['ids'] ?? []));
    $field    = sanitize_text_field($b['field'] ?? '');
    $value    = $b['value'] ?? null;
    $dry_run  = !empty($b['dry_run']);

    $allowed = ['status', 'author_id', 'parent', 'template'];
    if (!$field || !in_array($field, $allowed, true)) {
        return xclipse_err('invalid_field', 'field phải là: ' . implode(', ', $allowed));
    }
    if (empty($ids)) return xclipse_err('ids_required', 'Thiếu ids');

    $results = [];
    foreach ($ids as $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'page') {
            $results[] = ['id' => $id, 'status' => 'not_found'];
            continue;
        }
        if (!$dry_run) {
            if ($field === 'template') {
                update_post_meta($id, '_wp_page_template', sanitize_text_field((string)$value));
            } else {
                $map = ['status' => 'post_status', 'author_id' => 'post_author', 'parent' => 'post_parent'];
                wp_update_post(['ID' => $id, $map[$field] => sanitize_text_field((string)$value)]);
            }
        }
        $results[] = ['id' => $id, 'title' => $p->post_title, 'status' => $dry_run ? 'would_update' : 'updated'];
    }
    if (!$dry_run) xclipse_changelog_add('pages_bulk_update', "Set {$field}={$value} on " . count($ids) . ' pages', $ids);
    return xclipse_ok(['dry_run' => $dry_run, 'count' => count($results), 'results' => $results]);
}

// MENUS
// BULK OPERATIONS

/**
 * Bulk update nhiều posts cùng lúc.
 * Body: { "items": [{"id":123,"title":"...","content":"...","status":"...","meta":{}},…] }
 * Mỗi item cần có "id". Chỉ update các field được cung cấp.
 */
function xclipse_bulk_posts(WP_REST_Request $r) {
    $b        = $r->get_json_params();
    $items    = $b['items'] ?? [];
    $dry_run  = !empty($b['dry_run']);
    if (empty($items) || !is_array($items))
        return xclipse_err('empty', 'items phải là array [{id, title?, content?, status?, meta?},…]');
    $ok = []; $fail = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if (!$id || !get_post($id)) { $fail[] = ['id' => $id, 'error' => 'not_found']; continue; }
        if ($dry_run) { $ok[] = $id; continue; }
        $d = ['ID' => $id];
        if (isset($item['title']))      $d['post_title']    = sanitize_text_field($item['title']);
        if (isset($item['content']))    $d['post_content']  = wp_kses_post($item['content']);
        if (isset($item['excerpt']))    $d['post_excerpt']  = sanitize_textarea_field($item['excerpt']);
        if (isset($item['status']))     $d['post_status']   = sanitize_key($item['status']);
        if (isset($item['slug']))       $d['post_name']     = sanitize_title($item['slug']);
        if (isset($item['date']))       $d['post_date']     = sanitize_text_field($item['date']);
        if (isset($item['categories'])) $d['post_category'] = array_map('intval', (array)$item['categories']);
        if (isset($item['tags']))       $d['tags_input']    = is_array($item['tags']) ? implode(',', $item['tags']) : $item['tags'];
        $res = wp_update_post($d, true);
        if (is_wp_error($res)) { $fail[] = ['id' => $id, 'error' => $res->get_error_message()]; continue; }
        if (isset($item['meta']) && is_array($item['meta'])) {
            foreach ($item['meta'] as $k => $v)
                update_post_meta($id, sanitize_key($k), is_array($v) ? $v : sanitize_text_field((string)$v));
        }
        if (isset($item['seo_title'])) update_post_meta($id, 'rank_math_title',           sanitize_text_field($item['seo_title']));
        if (isset($item['seo_desc']))  update_post_meta($id, 'rank_math_description',      sanitize_textarea_field($item['seo_desc']));
        if (isset($item['focus_kw']))  update_post_meta($id, 'rank_math_focus_keyword',    sanitize_text_field($item['focus_kw']));
        $ok[] = $id;
    }
    if (!$dry_run && $ok) xclipse_changelog_add('bulk_posts_update', 'Updated ' . count($ok) . ' posts', $ok);
    return xclipse_ok(['dry_run' => $dry_run, 'ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}