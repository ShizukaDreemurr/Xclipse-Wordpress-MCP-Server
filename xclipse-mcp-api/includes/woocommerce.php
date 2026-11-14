<?php
/** WooCommerce: products, orders, customers, coupons, categories — v4.6.2 */
defined('ABSPATH') || exit;

/**
 * Guard: trả true nếu WooCommerce active, WP_Error nếu không.
 * Return type là mixed để tương thích PHP 7.4 + PHP 8 strict.
 */
function xclipse_woo() {
    return class_exists('WooCommerce') ? true : xclipse_err('no_woo', 'WooCommerce chưa cài đặt hoặc chưa kích hoạt', 501);
}

// WOOCOMMERCE — PRODUCTS
function xclipse_products_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $args = [
        'limit'   => min((int)($r['per_page'] ?? 20), 50),
        'page'    => (int)($r['page'] ?? 1),
        'status'  => $r['status'] ?? 'publish',
        'orderby' => $r['orderby'] ?? 'date',
        'order'   => strtoupper($r['order'] ?? 'DESC'),
        'return'  => 'objects',
    ];
    if (!empty($r['search']))    $args['s']         = sanitize_text_field($r['search']);
    if (!empty($r['category']))  $args['category']  = [$r['category']];
    if (!empty($r['author_id'])) $args['author']    = (int)$r['author_id'];
    $products = wc_get_products($args);
    $fields   = xclipse_parse_fields($r['fields'] ?? '');
    return xclipse_ok(array_map(function($p) use ($fields) {
        $post = get_post($p->get_id());
        return xclipse_filter_fields(xclipse_compact([
            'id'          => $p->get_id(),
            'name'        => $p->get_name(),
            'sku'         => $p->get_sku(),
            'price'       => $p->get_price(),
            'sale'        => $p->get_sale_price() ?: null,
            'stock'       => $p->get_stock_status(),
            'qty'         => $p->get_stock_quantity(),
            'st'          => $p->get_status(),
            'created_at'  => $post ? $post->post_date     : null,
            'modified_at' => $post ? $post->post_modified : null,
            'author'      => $post ? xclipse_author_info((int)$post->post_author) : null,
        ]), $fields);
    }, $products));
}

function xclipse_product_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Sản phẩm không tồn tại');
    return xclipse_ok(xclipse_compact([
        'id'     => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(),
        'status' => $p->get_status(), 'price' => $p->get_price(),
        'regular'=> $p->get_regular_price(), 'sale' => $p->get_sale_price() ?: null,
        'stock'  => $p->get_stock_status(), 'qty' => $p->get_stock_quantity(),
        'url'    => $p->get_permalink(), 'image' => wp_get_attachment_url($p->get_image_id()) ?: null,
        'desc'   => $p->get_description(), 'short' => $p->get_short_description(),
        'cats'   => wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']),
        'seo'    => xclipse_seo($p->get_id()),
    ]));
}

function xclipse_product_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b = $r->get_json_params();
    $p = new WC_Product_Simple();
    $p->set_name(sanitize_text_field($b['name'] ?? 'Sản phẩm mới'));
    if (isset($b['regular_price']))     $p->set_regular_price($b['regular_price']);
    if (isset($b['sale_price']))        $p->set_sale_price($b['sale_price']);
    if (isset($b['stock_quantity']))    $p->set_stock_quantity((int)$b['stock_quantity']);
    if (isset($b['description']))       $p->set_description(wp_kses_post($b['description']));
    if (isset($b['short_description'])) $p->set_short_description(wp_kses_post($b['short_description']));
    if (isset($b['sku']))               $p->set_sku(sanitize_text_field($b['sku']));
    $p->set_status($b['status'] ?? 'draft');
    $id = $p->save();
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_product_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Sản phẩm không tồn tại');
    $b = $r->get_json_params();
    if (isset($b['name']))              $p->set_name(sanitize_text_field($b['name']));
    if (isset($b['regular_price']))     $p->set_regular_price($b['regular_price']);
    if (isset($b['sale_price']))        $p->set_sale_price($b['sale_price']);
    if (isset($b['stock_quantity']))    $p->set_stock_quantity((int)$b['stock_quantity']);
    if (isset($b['stock_status']))      $p->set_stock_status($b['stock_status']);
    if (isset($b['description']))       $p->set_description(wp_kses_post($b['description']));
    if (isset($b['short_description'])) $p->set_short_description(wp_kses_post($b['short_description']));
    if (isset($b['status']))            $p->set_status($b['status']);
    $p->save();
    xclipse_save_seo((int)$r['id'], $b);
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id'], 'price' => $p->get_price()]);
}

// WOOCOMMERCE — ORDERS
function xclipse_orders_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $args = ['limit' => min((int)($r['per_page'] ?? 20), 50), 'page' => (int)($r['page'] ?? 1), 'return' => 'objects'];
    if (($r['status'] ?? 'any') !== 'any') $args['status'] = $r['status'];
    if ($r['after'])  $args['date_created'] = '>' . $r['after'];
    if ($r['before']) $args['date_created'] = '<' . $r['before'];
    return xclipse_ok(array_map(fn($o) => [
        'id'     => $o->get_id(), 'st' => $o->get_status(),
        'total'  => $o->get_total(), 'cur' => $o->get_currency(),
        'date'   => ($d = $o->get_date_created()) ? substr($d->date('Y-m-d H:i:s'), 0, 16) : '',
        'name'   => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
        'email'  => $o->get_billing_email(),
        'pay'    => $o->get_payment_method_title(),
    ], wc_get_orders($args)));
}

function xclipse_order_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    return xclipse_ok(xclipse_compact([
        'id'     => $o->get_id(), 'status' => $o->get_status(),
        'total'  => $o->get_total(), 'currency' => $o->get_currency(),
        'date'   => ($d = $o->get_date_created()) ? $d->date('Y-m-d H:i:s') : null,
        'name'   => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
        'email'  => $o->get_billing_email(), 'phone' => $o->get_billing_phone(),
        'pay'    => $o->get_payment_method_title(),
        'addr'   => $o->get_formatted_billing_address(),
        'items'  => array_map(fn($i) => ['name' => $i->get_name(), 'qty' => $i->get_quantity(), 'sub' => $i->get_subtotal()], array_values($o->get_items())),
        'notes'  => array_map(fn($n) => ['d' => substr($n->comment_date, 0, 16), 'c' => strip_tags($n->comment_content)], wc_get_order_notes(['order_id' => $o->get_id()])),
    ]));
}

// WOOCOMMERCE — ORDER CREATE / DELETE (v4.2.0)

function xclipse_order_create(WP_REST_Request $req) {
    if (($e = xclipse_woo()) !== true) return $e;

    $b = $req->get_json_params();

    $order = wc_create_order([
        'customer_id' => (int)($b['customer_id'] ?? 0),
        'status'      => sanitize_text_field($b['status'] ?? 'pending'),
        'created_via' => sanitize_text_field($b['created_via'] ?? 'mcp'),
    ]);

    if (is_wp_error($order)) return xclipse_err($order->get_error_message());

    // ── Billing address ────────────────────────────────────────────────────
    if (!empty($b['billing']) && is_array($b['billing'])) {
        $bf = $b['billing'];
        $order->set_billing_first_name(sanitize_text_field($bf['first_name'] ?? ''));
        $order->set_billing_last_name(sanitize_text_field($bf['last_name'] ?? ''));
        $order->set_billing_email(sanitize_email($bf['email'] ?? ''));
        $order->set_billing_phone(sanitize_text_field($bf['phone'] ?? ''));
        $order->set_billing_address_1(sanitize_text_field($bf['address_1'] ?? ''));
        $order->set_billing_address_2(sanitize_text_field($bf['address_2'] ?? ''));
        $order->set_billing_city(sanitize_text_field($bf['city'] ?? ''));
        $order->set_billing_state(sanitize_text_field($bf['state'] ?? ''));
        $order->set_billing_postcode(sanitize_text_field($bf['postcode'] ?? ''));
        $order->set_billing_country(sanitize_text_field($bf['country'] ?? 'VN'));
        $order->set_billing_company(sanitize_text_field($bf['company'] ?? ''));
    }

    // ── Shipping address ───────────────────────────────────────────────────
    $sf = $b['shipping'] ?? $b['billing'] ?? [];
    if (!empty($sf) && is_array($sf)) {
        $order->set_shipping_first_name(sanitize_text_field($sf['first_name'] ?? ''));
        $order->set_shipping_last_name(sanitize_text_field($sf['last_name'] ?? ''));
        $order->set_shipping_address_1(sanitize_text_field($sf['address_1'] ?? ''));
        $order->set_shipping_address_2(sanitize_text_field($sf['address_2'] ?? ''));
        $order->set_shipping_city(sanitize_text_field($sf['city'] ?? ''));
        $order->set_shipping_state(sanitize_text_field($sf['state'] ?? ''));
        $order->set_shipping_postcode(sanitize_text_field($sf['postcode'] ?? ''));
        $order->set_shipping_country(sanitize_text_field($sf['country'] ?? 'VN'));
        $order->set_shipping_company(sanitize_text_field($sf['company'] ?? ''));
    }

    // ── Line items ─────────────────────────────────────────────────────────
    $items = $b['items'] ?? [];
    if (!empty($items) && is_array($items)) {
        foreach ($items as $item) {
            $product_id   = (int)($item['product_id'] ?? 0);
            $qty          = max(1, (int)($item['qty'] ?? 1));
            $variation_id = (int)($item['variation_id'] ?? 0);
            if (!$product_id) continue;
            $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
            if (!$product) continue;
            $order->add_product($product, $qty, [
                'subtotal' => !empty($item['price']) ? (float)$item['price'] * $qty : null,
                'total'    => !empty($item['price']) ? (float)$item['price'] * $qty : null,
            ]);
        }
    }

    // ── Payment method ─────────────────────────────────────────────────────
    if (!empty($b['payment_method'])) {
        $order->set_payment_method(sanitize_text_field($b['payment_method']));
        $order->set_payment_method_title(sanitize_text_field($b['payment_method_title'] ?? $b['payment_method']));
    }

    // ── Coupons ────────────────────────────────────────────────────────────
    if (!empty($b['coupon_codes']) && is_array($b['coupon_codes'])) {
        foreach ($b['coupon_codes'] as $code) {
            $order->apply_coupon(sanitize_text_field($code));
        }
    }

    // ── Notes ──────────────────────────────────────────────────────────────
    if (!empty($b['customer_note'])) {
        $order->set_customer_note(sanitize_textarea_field($b['customer_note']));
    }

    // Tính lại tổng
    $order->calculate_totals();
    $order->save();

    // Ghi chú nội bộ sau khi save (cần order ID)
    if (!empty($b['note'])) {
        $order->add_order_note(sanitize_text_field($b['note']));
    }

    return xclipse_ok([
        'id'     => $order->get_id(),
        'status' => $order->get_status(),
        'total'  => $order->get_total(),
        'url'    => $order->get_edit_order_url(),
    ], 201);
}

function xclipse_order_delete(WP_REST_Request $req) {
    if (($e = xclipse_woo()) !== true) return $e;
    $id    = (int)$req['id'];
    $o     = wc_get_order($id);
    if (!$o) return xclipse_err("Đơn hàng ID {$id} không tồn tại.", 404);
    $force = (bool)($req->get_param('force') ?? false);
    $force ? wp_delete_post($id, true) : wp_trash_post($id);
    return xclipse_ok(['id' => $id, 'action' => $force ? 'deleted' : 'trashed']);
}

function xclipse_order_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    $b = $r->get_json_params();

    // Status
    if (isset($b['status'])) $o->update_status($b['status'], $b['note'] ?? '');
    elseif (isset($b['note'])) $o->add_order_note(sanitize_text_field($b['note']));

    // Ngày tạo đơn (date_created)
    if (!empty($b['date_created'])) {
        $ts = strtotime($b['date_created']);
        if ($ts) $o->set_date_created($ts);
    }
    // Ngày thanh toán (date_paid)
    if (!empty($b['date_paid'])) {
        $ts = strtotime($b['date_paid']);
        if ($ts) $o->set_date_paid($ts);
    }
    // Ngày hoàn thành (date_completed)
    if (!empty($b['date_completed'])) {
        $ts = strtotime($b['date_completed']);
        if ($ts) $o->set_date_completed($ts);
    }

    // Payment method
    if (isset($b['payment_method']))       $o->set_payment_method(sanitize_text_field($b['payment_method']));
    if (isset($b['payment_method_title'])) $o->set_payment_method_title(sanitize_text_field($b['payment_method_title']));

    // Transaction ID (mã giao dịch)
    if (isset($b['transaction_id'])) $o->set_transaction_id(sanitize_text_field($b['transaction_id']));

    // Billing info
    if (!empty($b['billing']) && is_array($b['billing'])) {
        $bf = $b['billing'];
        if (isset($bf['first_name'])) $o->set_billing_first_name(sanitize_text_field($bf['first_name']));
        if (isset($bf['last_name']))  $o->set_billing_last_name(sanitize_text_field($bf['last_name']));
        if (isset($bf['email']))      $o->set_billing_email(sanitize_email($bf['email']));
        if (isset($bf['phone']))      $o->set_billing_phone(sanitize_text_field($bf['phone']));
        if (isset($bf['address_1']))  $o->set_billing_address_1(sanitize_text_field($bf['address_1']));
        if (isset($bf['city']))       $o->set_billing_city(sanitize_text_field($bf['city']));
        if (isset($bf['country']))    $o->set_billing_country(sanitize_text_field($bf['country']));
    }

    // Customer note
    if (isset($b['customer_note'])) $o->set_customer_note(sanitize_textarea_field($b['customer_note']));

    // Nguồn gốc (created_via) — WC không có setter, phải ghi DB trực tiếp
    // Lưu ý: $o->save() bên dưới KHÔNG overwrite created_via vì WC chỉ đọc nó lúc tạo
    if (isset($b['created_via'])) {
        $source = sanitize_text_field($b['created_via']);
        // HPOS (High-Performance Order Storage) — WC 7.1+
        if (function_exists('wc_get_container') && class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'wc_orders',
                ['created_via' => $source],
                ['id' => $o->get_id()]
            );
        }
        // Legacy post meta (fallback cho HPOS tắt)
        update_post_meta($o->get_id(), '_created_via', $source);
    }

    // Coupon: thêm mã mới
    $added_coupons   = [];
    $failed_coupons  = [];
    if (!empty($b['coupon_add']) && is_array($b['coupon_add'])) {
        foreach ($b['coupon_add'] as $code) {
            $code = sanitize_text_field($code);
            $result = $o->apply_coupon($code);
            if (is_wp_error($result)) $failed_coupons[] = ['code' => $code, 'error' => $result->get_error_message()];
            else $added_coupons[] = $code;
        }
    }
    // Coupon: xóa mã khỏi đơn
    $removed_coupons = [];
    if (!empty($b['coupon_remove']) && is_array($b['coupon_remove'])) {
        foreach ($b['coupon_remove'] as $code) {
            $code = sanitize_text_field($code);
            if ($o->remove_coupon($code)) $removed_coupons[] = $code;
        }
    }

    $o->calculate_totals();
    $o->save();

    $resp = [
        'ok'             => true,
        'id'             => $o->get_id(),
        'status'         => $o->get_status(),
        'created_via'    => $o->get_created_via(),
        'date_created'   => ($d = $o->get_date_created())   ? $d->date('Y-m-d H:i:s') : null,
        'date_paid'      => ($d = $o->get_date_paid())      ? $d->date('Y-m-d H:i:s') : null,
        'date_completed' => ($d = $o->get_date_completed()) ? $d->date('Y-m-d H:i:s') : null,
        'coupons'        => array_values(array_map(fn($c) => $c->get_code(), $o->get_items('coupon'))),
        'total'          => $o->get_total(),
        'discount_total' => $o->get_discount_total(),
    ];
    if ($added_coupons)  $resp['coupons_added']   = $added_coupons;
    if ($removed_coupons) $resp['coupons_removed'] = $removed_coupons;
    if ($failed_coupons) $resp['coupons_failed']   = $failed_coupons;
    return xclipse_ok($resp);
}

function xclipse_order_refund(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    $b      = $r->get_json_params();
    $amount = (float)($b['amount'] ?? $o->get_total());
    $reason = sanitize_text_field($b['reason'] ?? '');
    $ref    = wc_create_refund(['order_id' => $o->get_id(), 'amount' => $amount, 'reason' => $reason, 'restock_items' => (bool)($b['restock'] ?? true)]);
    if (is_wp_error($ref)) return $ref;
    return xclipse_ok(['ok' => true, 'refund_id' => $ref->get_id(), 'amount' => $ref->get_amount()]);
}

// WOOCOMMERCE — CUSTOMERS
function xclipse_customers_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $args = ['role' => 'customer', 'number' => min((int)($r['per_page'] ?? 20), 50), 'paged' => (int)($r['page'] ?? 1)];
    if ($r['search']) $args['search'] = '*' . esc_attr($r['search']) . '*';
    $users = get_users($args);
    return xclipse_ok(array_map(fn($u) => [
        'id'    => $u->ID,
        'name'  => $u->display_name,
        'email' => $u->user_email,
        'date'  => substr($u->user_registered, 0, 10),
        'orders'=> wc_get_customer_order_count($u->ID),
        'spent' => wc_get_customer_total_spent($u->ID),
    ], $users));
}

function xclipse_customer_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $id  = (int)$r['id'];
    $u   = get_user_by('id', $id);
    if (!$u) return xclipse_err('not_found', 'Không tìm thấy customer');
    $c   = new WC_Customer($id);
    return xclipse_ok(xclipse_compact([
        'id'      => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email,
        'phone'   => $c->get_billing_phone(),
        'address' => $c->get_formatted_billing_address(),
        'orders'  => wc_get_customer_order_count($id),
        'spent'   => wc_get_customer_total_spent($id),
        'date_reg'=> substr($u->user_registered, 0, 10),
    ]));
}

// WOOCOMMERCE — COUPONS
function xclipse_coupons_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $q = new WP_Query(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => min((int)($r['per_page'] ?? 20), 50), 'paged' => (int)($r['page'] ?? 1), 's' => $r['search'] ?? '']);
    return xclipse_ok(array_map(function ($p) {
        $c = new WC_Coupon($p->post_title);
        return ['id' => $p->ID, 'code' => $p->post_title, 'type' => $c->get_discount_type(), 'amount' => $c->get_amount(), 'uses' => $c->get_usage_count(), 'limit' => $c->get_usage_limit() ?: null, 'exp' => ($exp = $c->get_date_expires()) ? $exp->date('Y-m-d') : null];
    }, $q->posts));
}

function xclipse_coupon_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b = $r->get_json_params();
    $c = new WC_Coupon();
    $c->set_code(strtolower(sanitize_text_field($b['code'] ?? '')));
    $c->set_discount_type($b['type'] ?? 'percent');
    $c->set_amount((float)($b['amount'] ?? 0));
    if (isset($b['usage_limit']))    $c->set_usage_limit((int)$b['usage_limit']);
    if (isset($b['expiry_date']))    $c->set_date_expires(strtotime($b['expiry_date']));
    if (isset($b['min_amount']))     $c->set_minimum_amount($b['min_amount']);
    if (isset($b['product_ids']))    $c->set_product_ids(array_map('absint', $b['product_ids']));
    $id = $c->save();
    return xclipse_ok(['ok' => true, 'id' => $id, 'code' => $c->get_code()]);
}

function xclipse_coupon_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $c = new WC_Coupon((int)$r['id']);
    $b = $r->get_json_params();
    if (isset($b['amount']))      $c->set_amount((float)$b['amount']);
    if (isset($b['type']))        $c->set_discount_type($b['type']);
    if (isset($b['usage_limit'])) $c->set_usage_limit((int)$b['usage_limit']);
    if (isset($b['expiry_date'])) $c->set_date_expires(strtotime($b['expiry_date']));
    $c->save();
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

function xclipse_coupon_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    wp_trash_post((int)$r['id']);
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

// WOOCOMMERCE — PRODUCT CATEGORIES
function xclipse_product_cats(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC']);
    return xclipse_ok(array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => $t->parent], $terms));
}

function xclipse_product_cat_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b = $r->get_json_params();
    $t = wp_insert_term(sanitize_text_field($b['name'] ?? ''), 'product_cat', ['slug' => sanitize_title($b['slug'] ?? ''), 'parent' => (int)($b['parent'] ?? 0), 'description' => sanitize_text_field($b['description'] ?? '')]);
    if (is_wp_error($t)) return $t;
    return xclipse_ok(['ok' => true, 'id' => $t['term_id']]);
}

/**
 * Bulk update nhiều WooCommerce products cùng lúc.
 * Body: { "items": [{"id":456,"price":"...","sale_price":"...","stock":10,"status":"...","meta":{}},…] }
 */
function xclipse_bulk_products(WP_REST_Request $r) {
    if (!class_exists('WooCommerce')) return xclipse_err('no_woo', 'WooCommerce chưa cài', 501);
    $b     = $r->get_json_params();
    $items = $b['items'] ?? [];
    if (empty($items) || !is_array($items))
        return xclipse_err('empty', 'items phải là array [{id, price?, sale_price?, stock?, status?, meta?},…]');
    $ok = []; $fail = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if (!$id) { $fail[] = ['id' => $id, 'error' => 'missing_id']; continue; }
        $product = wc_get_product($id);
        if (!$product) { $fail[] = ['id' => $id, 'error' => 'not_found']; continue; }
        // Cập nhật product fields
        if (isset($item['title']))       $product->set_name(sanitize_text_field($item['title']));
        if (isset($item['description'])) $product->set_description(wp_kses_post($item['description']));
        if (isset($item['short_desc']))  $product->set_short_description(wp_kses_post($item['short_desc']));
        if (isset($item['status']))      $product->set_status(sanitize_key($item['status']));
        if (isset($item['sku']))         $product->set_sku(sanitize_text_field($item['sku']));
        if (isset($item['price']))       $product->set_regular_price(wc_format_decimal($item['price']));
        if (isset($item['sale_price']))  $product->set_sale_price(wc_format_decimal($item['sale_price']));
        if (isset($item['stock']))       { $product->set_manage_stock(true); $product->set_stock_quantity((int)$item['stock']); }
        if (isset($item['stock_status'])) $product->set_stock_status(sanitize_key($item['stock_status']));
        if (isset($item['weight']))      $product->set_weight(wc_format_decimal($item['weight']));
        if (isset($item['categories'])) {
            $cats = array_map('intval', (array)$item['categories']);
            $product->set_category_ids($cats);
        }
        $saved = $product->save();
        if (!$saved) { $fail[] = ['id' => $id, 'error' => 'save_failed']; continue; }
        // Post meta tuỳ chỉnh
        if (isset($item['meta']) && is_array($item['meta'])) {
            foreach ($item['meta'] as $k => $v)
                update_post_meta($id, sanitize_key($k), is_array($v) ? $v : sanitize_text_field((string)$v));
        }
        // SEO nhanh
        if (isset($item['seo_title']))  update_post_meta($id, 'rank_math_title', sanitize_text_field($item['seo_title']));
        if (isset($item['seo_desc']))   update_post_meta($id, 'rank_math_description', sanitize_textarea_field($item['seo_desc']));
        if (isset($item['focus_kw']))   update_post_meta($id, 'rank_math_focus_keyword', sanitize_text_field($item['focus_kw']));
        $ok[] = $id;
    }
    return xclipse_ok(['ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}

// ── Fix: Bulk Update WooCommerce Product Content ─────────────
function xclipse_fix_product_content(WP_REST_Request $req) {
    $product_id  = (int)($req->get_param('product_id') ?? 0);
    $ids         = $req->get_param('ids');
    $content     = $req->get_param('content') ?? '';        // full new content
    $short_desc  = $req->get_param('short_description') ?? '';
    $append      = (bool)($req->get_param('append') ?? false);
    $dry_run     = (bool)($req->get_param('dry_run') ?? false);

    $targets = [];
    if ($product_id) {
        $targets = [(int)$product_id];
    } elseif (!empty($ids)) {
        $targets = array_map('intval', (array)$ids);
    } else {
        return xclipse_err('Provide product_id or ids');
    }

    $results = [];
    foreach ($targets as $pid) {
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'product') {
            $results[] = ['id' => $pid, 'status' => 'not_found'];
            continue;
        }

        $data = ['ID' => $pid];
        if ($content !== '') {
            $data['post_content'] = $append ? ($post->post_content . "\n\n" . $content) : $content;
        }
        if ($short_desc !== '') {
            $data['post_excerpt'] = $short_desc;
        }

        if (!$dry_run && count($data) > 1) {
            $r = wp_update_post($data, true);
            if (is_wp_error($r)) {
                $results[] = ['id' => $pid, 'status' => 'error', 'message' => $r->get_error_message()];
            } else {
                $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'updated'];
            }
        } else {
            $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'dry_run'];
        }
    }

    return xclipse_ok(['dry_run' => $dry_run, 'count' => count($results), 'results' => $results]);
}