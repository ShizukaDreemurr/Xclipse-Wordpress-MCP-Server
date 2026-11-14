<?php
/** UX Blocks CPT (post_type=blocks) */
defined('ABSPATH') || exit;

// UX BLOCKS CPT — v3.5.8
// Quản lý Flatsome UX Blocks (post_type = ux_block)
// Dùng để tạo/sửa/xóa reusable blocks, sau đó nhúng bằng [ux_block id="123"]

/** Helper: format một UX Block post */
function xclipse_fmt_ux_block(WP_Post $p): array {
    return [
        'id'        => $p->ID,
        'title'     => $p->post_title,
        'slug'      => $p->post_name,
        'status'    => $p->post_status,
        'shortcode' => '[block id="' . $p->ID . '"]',
        'content'   => $p->post_content,
        'modified'  => $p->post_modified,
        'created'   => $p->post_date,
        'edit_url'  => admin_url('post.php?post=' . $p->ID . '&action=edit'),
    ];
}

function xclipse_ux_blocks_list(WP_REST_Request $r) {
    $args = [
        'post_type'      => 'blocks',
        'post_status'    => $r->get_param('status') ?: 'any',
        'posts_per_page' => (int)($r->get_param('per_page') ?: 50),
        'paged'          => (int)($r->get_param('page') ?: 1),
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];
    if ($s = $r->get_param('search')) $args['s'] = sanitize_text_field($s);

    $query  = new WP_Query($args);
    $blocks = array_map('xclipse_fmt_ux_block', $query->posts);

    return xclipse_ok([
        'blocks' => $blocks,
        'total'  => $query->found_posts,
        'pages'  => $query->max_num_pages,
    ]);
}

function xclipse_ux_block_create(WP_REST_Request $r) {
    $body    = $r->get_json_params();
    $title   = sanitize_text_field($body['title'] ?? '');
    $content = $body['content'] ?? '';
    $status  = in_array($body['status'] ?? '', ['publish', 'draft']) ? $body['status'] : 'publish';

    if (!$title) return xclipse_err('missing_title', 'Thiếu "title"', 400);

    $id = wp_insert_post([
        'post_type'    => 'blocks',
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $status,
    ], true);

    if (is_wp_error($id)) return xclipse_err('create_failed', $id->get_error_message(), 500);

    return xclipse_ok(xclipse_fmt_ux_block(get_post($id)));
}

function xclipse_ux_block_get(WP_REST_Request $r) {
    $post = get_post((int)$r['id']);
    if (!$post || $post->post_type !== 'blocks') {
        return xclipse_err('not_found', 'UX Block không tồn tại', 404);
    }
    return xclipse_ok(xclipse_fmt_ux_block($post));
}

function xclipse_ux_block_update_post(WP_REST_Request $r) {
    $id   = (int)$r['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'blocks') {
        return xclipse_err('not_found', 'UX Block không tồn tại', 404);
    }

    $body    = $r->get_json_params();
    $data    = ['ID' => $id];

    if (isset($body['title']))   $data['post_title']   = sanitize_text_field($body['title']);
    if (isset($body['content'])) $data['post_content'] = $body['content'];
    if (isset($body['status']) && in_array($body['status'], ['publish', 'draft', 'private'])) {
        $data['post_status'] = $body['status'];
    }

    if (count($data) === 1) return xclipse_err('no_changes', 'Không có field nào thay đổi', 400);

    $result = wp_update_post($data, true);
    if (is_wp_error($result)) return xclipse_err('update_failed', $result->get_error_message(), 500);

    return xclipse_ok(xclipse_fmt_ux_block(get_post($id)));
}

function xclipse_ux_block_delete_post(WP_REST_Request $r) {
    $id    = (int)$r['id'];
    $post  = get_post($id);
    $force = filter_var($r->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$post || $post->post_type !== 'blocks') {
        return xclipse_err('not_found', 'UX Block không tồn tại', 404);
    }

    $title = $post->post_title;
    $res   = $force ? wp_delete_post($id, true) : wp_trash_post($id);

    if (!$res) return xclipse_err('delete_failed', 'Không thể xóa UX Block', 500);

    return xclipse_ok(['id' => $id, 'title' => $title, 'action' => $force ? 'deleted' : 'trashed']);
}