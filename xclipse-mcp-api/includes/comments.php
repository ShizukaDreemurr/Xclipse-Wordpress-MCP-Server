<?php
/** Comments: list, get, update, delete */
defined('ABSPATH') || exit;

// COMMENTS
function xclipse_comments_list(WP_REST_Request $r) {
    $args = ['number' => min((int)($r['per_page'] ?? 20), 50), 'offset' => ((int)($r['page'] ?? 1) - 1) * (int)($r['per_page'] ?? 20), 'status' => $r['status'] ?? 'hold'];
    if ($r['post_id']) $args['post_id'] = (int)$r['post_id'];
    $comments = get_comments($args);
    $total    = get_comments(['count' => true] + $args);
    return xclipse_ok(['total' => $total, 'comments' => array_map(fn($c) => ['id' => (int)$c->comment_ID, 'post_id' => (int)$c->comment_post_ID, 'post' => get_the_title($c->comment_post_ID), 'author' => $c->comment_author, 'email' => $c->comment_author_email, 'date' => substr($c->comment_date, 0, 16), 'content' => mb_substr($c->comment_content, 0, 200), 'st' => $c->comment_approved === '1' ? 'approved' : ($c->comment_approved === '0' ? 'pending' : 'spam')], $comments)]);
}

function xclipse_comment_get(WP_REST_Request $r) {
    $c = get_comment((int)$r['id']);
    if (!$c) return xclipse_err('not_found', 'Không tìm thấy comment');
    return xclipse_ok(['id' => (int)$c->comment_ID, 'post_id' => (int)$c->comment_post_ID, 'post' => get_the_title($c->comment_post_ID), 'author' => $c->comment_author, 'email' => $c->comment_author_email, 'url' => $c->comment_author_url, 'date' => $c->comment_date, 'content' => $c->comment_content, 'status' => $c->comment_approved === '1' ? 'approved' : ($c->comment_approved === '0' ? 'pending' : $c->comment_approved)]);
}

function xclipse_comment_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $d  = ['comment_ID' => $id];
    if (isset($b['content'])) $d['comment_content'] = sanitize_textarea_field($b['content']);
    if (isset($b['status'])) {
        $map = ['approved' => 1, 'pending' => 0, 'spam' => 'spam', 'trash' => 'trash'];
        wp_set_comment_status($id, $map[$b['status']] ?? $b['status']);
    }
    if (count($d) > 1) wp_update_comment($d);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_comment_delete(WP_REST_Request $r) {
    wp_delete_comment((int)$r['id'], (bool)($r['force'] ?? false));
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}