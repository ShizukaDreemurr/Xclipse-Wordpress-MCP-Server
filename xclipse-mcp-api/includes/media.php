<?php
/** Media upload, list, get, update, delete */
defined('ABSPATH') || exit;

// MEDIA
function xclipse_media_list(WP_REST_Request $r) {
    $q = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => min((int)($r['per_page'] ?? 20), 50), 'paged' => (int)($r['page'] ?? 1), 's' => $r['search'] ?? '', 'post_mime_type' => $r['mime'] ?? 'image', 'orderby' => 'date', 'order' => 'DESC']);
    return xclipse_ok(['total' => $q->found_posts, 'items' => array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title, 'url' => wp_get_attachment_url($p->ID), 'alt' => get_post_meta($p->ID, '_wp_attachment_image_alt', true), 'date' => substr($p->post_date, 0, 10)], $q->posts)]);
}

function xclipse_media_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p || $p->post_type !== 'attachment') return xclipse_err('not_found', 'Không tìm thấy');
    $m = wp_get_attachment_metadata($p->ID);
    return xclipse_ok(xclipse_compact(['id' => $p->ID, 'title' => $p->post_title, 'caption' => $p->post_excerpt, 'alt' => get_post_meta($p->ID, '_wp_attachment_image_alt', true), 'url' => wp_get_attachment_url($p->ID), 'mime' => $p->post_mime_type, 'date' => substr($p->post_date, 0, 10), 'w' => $m['width'] ?? null, 'h' => $m['height'] ?? null, 'size' => $m['filesize'] ?? null]));
}

function xclipse_media_upload(WP_REST_Request $r) {
    $b = $r->get_json_params();
    if (empty($b['url'])) return xclipse_err('missing', 'Cần url', 400);
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp  = download_url(esc_url_raw($b['url']));
    if (is_wp_error($tmp)) return $tmp;
    $file = ['name' => sanitize_file_name(basename(parse_url($b['url'], PHP_URL_PATH)) ?: 'upload.jpg'), 'tmp_name' => $tmp];
    $id   = media_handle_sideload($file, (int)($b['post_id'] ?? 0), $b['title'] ?? null);
    if (is_wp_error($id)) { @unlink($tmp); return $id; }
    if (!empty($b['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($b['alt']));
    return xclipse_ok(['ok' => true, 'id' => $id, 'url' => wp_get_attachment_url($id)]);
}

function xclipse_media_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $d  = ['ID' => $id];
    if (isset($b['title']))       $d['post_title']   = sanitize_text_field($b['title']);
    if (isset($b['caption']))     $d['post_excerpt'] = sanitize_text_field($b['caption']);
    if (isset($b['description'])) $d['post_content'] = sanitize_text_field($b['description']);
    wp_update_post($d);
    if (isset($b['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($b['alt']));
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_media_delete(WP_REST_Request $r) {
    wp_delete_attachment((int)$r['id'], (bool)($r['force'] ?? false));
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

// ── v4.9.0: upload ảnh dạng base64 ──
function xclipse_media_upload_base64(WP_REST_Request $r) {
    $b = $r->get_json_params();
    $b = is_array($b) ? $b : [];
    $data = (string) ($b['data'] ?? '');
    $name = sanitize_file_name((string) ($b['filename'] ?? 'upload.jpg'));
    if ($data === '') return xclipse_err('bad_request', 'Thiếu "data" (base64)', 400);
    if (preg_match('/^data:[^;]+;base64,/', $data)) $data = preg_replace('/^data:[^;]+;base64,/', '', $data);
    $bin = base64_decode($data, true);
    if ($bin === false || $bin === '') return xclipse_err('bad_base64', 'base64 không hợp lệ', 400);
    if (strlen($bin) > 15 * 1024 * 1024) return xclipse_err('too_large', 'Tối đa 15MB', 413);

    $ft = wp_check_filetype($name);
    if (empty($ft['type']) || strpos($ft['type'], 'image/') !== 0 && $ft['type'] !== 'application/pdf')
        return xclipse_err('bad_type', 'Chỉ cho phép ảnh/PDF (theo đuôi filename)', 400);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $up = wp_upload_bits($name, null, $bin);
    if (!empty($up['error'])) return xclipse_err('upload_failed', $up['error'], 500);

    $att = wp_insert_attachment([
        'post_mime_type' => $ft['type'],
        'post_title'     => sanitize_text_field($b['title'] ?? pathinfo($name, PATHINFO_FILENAME)),
        'post_status'    => 'inherit',
    ], $up['file'], (int) ($b['post_id'] ?? 0));
    if (is_wp_error($att)) return xclipse_err('insert_failed', $att->get_error_message(), 500);

    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));
    if (!empty($b['alt'])) update_post_meta($att, '_wp_attachment_image_alt', sanitize_text_field($b['alt']));
    return xclipse_ok(['id' => $att, 'url' => wp_get_attachment_url($att), 'mime' => $ft['type']]);
}
