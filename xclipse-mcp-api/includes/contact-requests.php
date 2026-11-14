<?php
/**
 * Contact Requests (Snippet 17 — sg_lh_request)
 * Đọc, cập nhật, xóa yêu cầu liên hệ giá từ khách hàng.
 * CPT: sg_lh_request | Meta: _sg_lh_pid, _sg_lh_name, _sg_lh_phone, _sg_lh_note, _sg_lh_status, _sg_lh_time
 */
defined('ABSPATH') || exit;

// HELPER

function xclipse_lh_format(WP_Post $p): array {
    $meta    = get_post_meta($p->ID);
    $pid     = intval($meta['_sg_lh_pid'][0]    ?? 0);
    $status  = $meta['_sg_lh_status'][0]        ?? 'new';
    $time    = $meta['_sg_lh_time'][0]          ?? $p->post_date;
    $product = ($pid && function_exists('wc_get_product')) ? wc_get_product($pid) : null;

    return [
        'id'           => $p->ID,
        'customer_name'  => $meta['_sg_lh_name'][0]  ?? '',
        'customer_phone' => $meta['_sg_lh_phone'][0] ?? '',
        'customer_note'  => $meta['_sg_lh_note'][0]  ?? '',
        'status'         => $status,              // new | contacted
        'time'           => $time,
        'product_id'     => $pid,
        'product_name'   => $product ? $product->get_name()  : null,
        'product_url'    => $product ? get_permalink($pid)    : null,
        'product_price'  => $product ? $product->get_price()  : null,
        'ip'             => $meta['_sg_lh_ip'][0] ?? '',
    ];
}

function xclipse_lh_check(): bool {
    return post_type_exists('sg_lh_request');
}

// LIST — GET /contact-requests

function xclipse_lh_list(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa được kích hoạt — CPT sg_lh_request không tồn tại');

    $status   = sanitize_text_field($r['status']   ?? 'all');
    $per_page = min((int)($r['per_page'] ?? 20), 100);
    $paged    = max(1, (int)($r['page'] ?? 1));
    $pid      = (int)($r['product_id'] ?? 0);
    $search   = sanitize_text_field($r['search'] ?? '');

    $args = [
        'post_type'      => 'sg_lh_request',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => false,
    ];

    $meta_query = [];
    if ($status !== 'all') {
        $meta_query[] = ['key' => '_sg_lh_status', 'value' => $status];
    }
    if ($pid) {
        $meta_query[] = ['key' => '_sg_lh_pid', 'value' => $pid];
    }
    if ($search) {
        // Tìm theo tên hoặc SĐT qua title (title = "Tên — SĐT")
        $args['s'] = $search;
    }
    if ($meta_query) {
        $args['meta_query'] = count($meta_query) > 1 ? array_merge(['relation' => 'AND'], $meta_query) : $meta_query;
    }

    $q = new WP_Query($args);

    // Đếm theo status
    $counts = [];
    foreach (['new', 'contacted'] as $st) {
        $c = new WP_Query([
            'post_type'      => 'sg_lh_request',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [['key' => '_sg_lh_status', 'value' => $st]],
        ]);
        $counts[$st] = $c->found_posts;
    }
    $counts['all'] = $counts['new'] + $counts['contacted'];

    return xclipse_ok([
        'total'   => $q->found_posts,
        'pages'   => ceil($q->found_posts / $per_page),
        'counts'  => $counts,
        'items'   => array_map('xclipse_lh_format', $q->posts),
    ]);
}

// GET ONE — GET /contact-requests/{id}

function xclipse_lh_get(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa kích hoạt');
    $p = get_post((int)$r['id']);
    if (!$p || $p->post_type !== 'sg_lh_request') return xclipse_err('not_found', 'Không tìm thấy yêu cầu');
    return xclipse_ok(xclipse_lh_format($p));
}

// UPDATE — PUT /contact-requests/{id}
// Hỗ trợ: status, customer_name, customer_phone, customer_note

function xclipse_lh_update(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa kích hoạt');
    $p = get_post((int)$r['id']);
    if (!$p || $p->post_type !== 'sg_lh_request') return xclipse_err('not_found', 'Không tìm thấy yêu cầu');

    $b = $r->get_json_params();

    if (isset($b['status'])) {
        $allowed = ['new', 'contacted'];
        if (!in_array($b['status'], $allowed, true))
            return xclipse_err('invalid_status', 'status phải là: ' . implode(', ', $allowed));
        update_post_meta($p->ID, '_sg_lh_status', $b['status']);
    }
    if (isset($b['customer_name'])) {
        update_post_meta($p->ID, '_sg_lh_name', sanitize_text_field($b['customer_name']));
        // Cập nhật title
        wp_update_post(['ID' => $p->ID, 'post_title' =>
            sanitize_text_field($b['customer_name']) . ' — ' . (get_post_meta($p->ID, '_sg_lh_phone', true))
        ]);
    }
    if (isset($b['customer_phone'])) {
        update_post_meta($p->ID, '_sg_lh_phone', sanitize_text_field($b['customer_phone']));
    }
    if (isset($b['customer_note'])) {
        update_post_meta($p->ID, '_sg_lh_note', sanitize_textarea_field($b['customer_note']));
    }

    return xclipse_ok(xclipse_lh_format(get_post($p->ID)));
}

// DELETE — DELETE /contact-requests/{id}
// force=false: trash | force=true: xóa vĩnh viễn

function xclipse_lh_delete(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa kích hoạt');
    $p = get_post((int)$r['id']);
    if (!$p || $p->post_type !== 'sg_lh_request') return xclipse_err('not_found', 'Không tìm thấy yêu cầu');

    $force = filter_var($r->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($force) {
        wp_delete_post($p->ID, true);
        return xclipse_ok(['ok' => true, 'deleted' => $p->ID]);
    }
    wp_trash_post($p->ID);
    return xclipse_ok(['ok' => true, 'trashed' => $p->ID]);
}

// BULK STATUS — POST /contact-requests/bulk-status
// { ids: [1,2,3], status: "contacted" }

function xclipse_lh_bulk_status(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa kích hoạt');
    $b = $r->get_json_params();

    $ids    = array_map('intval', $b['ids'] ?? []);
    $status = sanitize_text_field($b['status'] ?? '');

    if (!$ids)    return xclipse_err('missing_ids', 'Cần truyền ids[]');
    if (!in_array($status, ['new', 'contacted'], true))
        return xclipse_err('invalid_status', 'status phải là: new, contacted');

    $updated = 0;
    foreach ($ids as $id) {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'sg_lh_request') continue;
        update_post_meta($id, '_sg_lh_status', $status);
        $updated++;
    }
    return xclipse_ok(['ok' => true, 'updated' => $updated, 'status' => $status]);
}

// CREATE — POST /contact-requests  (tạo thủ công từ Claude)

function xclipse_lh_create(WP_REST_Request $r) {
    if (!xclipse_lh_check()) return xclipse_err('not_found', 'Snippet 17 chưa kích hoạt');
    $b = $r->get_json_params();

    $name  = sanitize_text_field($b['customer_name']  ?? '');
    $phone = sanitize_text_field($b['customer_phone'] ?? '');
    if (!$name || !$phone) return xclipse_err('missing_fields', 'customer_name và customer_phone là bắt buộc');

    $post_id = wp_insert_post([
        'post_type'   => 'sg_lh_request',
        'post_title'  => $name . ' — ' . $phone,
        'post_status' => 'publish',
        'meta_input'  => [
            '_sg_lh_pid'    => intval($b['product_id'] ?? 0),
            '_sg_lh_name'   => $name,
            '_sg_lh_phone'  => $phone,
            '_sg_lh_note'   => sanitize_textarea_field($b['customer_note'] ?? ''),
            '_sg_lh_status' => in_array($b['status'] ?? 'new', ['new','contacted'], true) ? $b['status'] : 'new',
            '_sg_lh_time'   => current_time('mysql'),
            '_sg_lh_ip'     => sanitize_text_field($b['ip'] ?? 'manual'),
        ],
    ]);

    if (is_wp_error($post_id)) return xclipse_err('insert_failed', $post_id->get_error_message());
    return xclipse_ok(xclipse_lh_format(get_post($post_id)));
}
