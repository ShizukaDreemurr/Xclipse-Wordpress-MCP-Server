<?php
/**
 * WooCommerce Extended — Payment, Shipping, Variations, Reports, Settings, Webhooks
 * v4.6.5 | Tất cả hàm guard bằng xclipse_woo() — trả lỗi graceful nếu WC chưa cài
 */
defined('ABSPATH') || exit;

// PAYMENT GATEWAYS

function xclipse_payment_gateways_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $gateways = WC()->payment_gateways()->payment_gateways();
    $list = [];
    foreach ($gateways as $id => $gw) {
        $list[] = [
            'id'          => $id,
            'title'       => $gw->title,
            'description' => $gw->description,
            'enabled'     => $gw->enabled === 'yes',
            'method_title'=> $gw->method_title,
            'order'       => $gw->order,
        ];
    }
    return xclipse_ok(['total' => count($list), 'gateways' => $list]);
}

function xclipse_payment_gateway_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $gateways = WC()->payment_gateways()->payment_gateways();
    $id = $r['id'];
    if (!isset($gateways[$id])) return xclipse_err('not_found', "Gateway '{$id}' không tồn tại");
    $gw = $gateways[$id];
    return xclipse_ok([
        'id'           => $id,
        'title'        => $gw->title,
        'description'  => $gw->description,
        'instructions' => $gw->get_option('instructions', ''),
        'enabled'      => $gw->enabled === 'yes',
        'method_title' => $gw->method_title,
        'method_description' => $gw->method_description,
        'settings'     => $gw->settings,
    ]);
}

function xclipse_payment_gateway_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $gateways = WC()->payment_gateways()->payment_gateways();
    $id = $r['id'];
    if (!isset($gateways[$id])) return xclipse_err('not_found', "Gateway '{$id}' không tồn tại");
    $gw = $gateways[$id];
    $b  = $r->get_json_params();
    if (isset($b['enabled']))      $gw->settings['enabled']      = $b['enabled'] ? 'yes' : 'no';
    if (isset($b['title']))        $gw->settings['title']        = sanitize_text_field($b['title']);
    if (isset($b['description']))  $gw->settings['description']  = sanitize_textarea_field($b['description']);
    if (isset($b['instructions'])) $gw->settings['instructions'] = sanitize_textarea_field($b['instructions']);
    update_option($gw->plugin_id . $gw->id . '_settings', $gw->settings);
    return xclipse_ok(['ok' => true, 'id' => $id, 'enabled' => $gw->settings['enabled'] === 'yes']);
}

// SHIPPING ZONES

function xclipse_shipping_zones_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $zones = WC_Shipping_Zones::get_zones();
    $list  = [];
    foreach ($zones as $z) {
        $list[] = [
            'id'        => $z['id'],
            'name'      => $z['zone_name'],
            'order'     => $z['zone_order'],
            'locations' => array_map(fn($l) => ['type' => $l->type, 'code' => $l->code], $z['zone_locations']),
            'methods'   => count($z['shipping_methods']),
        ];
    }
    // Zone 0 = Rest of world
    $list[] = ['id' => 0, 'name' => 'Rest of World', 'order' => 999, 'locations' => [], 'methods' => count((new WC_Shipping_Zone(0))->get_shipping_methods())];
    return xclipse_ok(['total' => count($list), 'zones' => $list]);
}

function xclipse_shipping_zone_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $zone = new WC_Shipping_Zone((int)$r['id']);
    if (!$zone->get_id() && (int)$r['id'] !== 0) return xclipse_err('not_found', 'Zone không tồn tại');
    $methods = [];
    foreach ($zone->get_shipping_methods() as $m) {
        $methods[] = [
            'instance_id' => $m->instance_id,
            'id'          => $m->id,
            'title'       => $m->title,
            'enabled'     => $m->is_enabled(),
            'settings'    => $m->instance_settings,
        ];
    }
    return xclipse_ok([
        'id'        => $zone->get_id(),
        'name'      => $zone->get_zone_name(),
        'locations' => $zone->get_zone_locations(),
        'methods'   => $methods,
    ]);
}

function xclipse_shipping_zone_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b    = $r->get_json_params();
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name(sanitize_text_field($b['name'] ?? 'New Zone'));
    if (!empty($b['order'])) $zone->set_zone_order((int)$b['order']);
    $id = $zone->save();
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_shipping_zone_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $zone = new WC_Shipping_Zone((int)$r['id']);
    if (!$zone->get_id()) return xclipse_err('not_found', 'Zone không tồn tại');
    $b = $r->get_json_params();
    if (isset($b['name']))  $zone->set_zone_name(sanitize_text_field($b['name']));
    if (isset($b['order'])) $zone->set_zone_order((int)$b['order']);
    $zone->save();
    return xclipse_ok(['ok' => true, 'id' => $zone->get_id()]);
}

function xclipse_shipping_zone_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $zone = new WC_Shipping_Zone((int)$r['id']);
    if (!$zone->get_id()) return xclipse_err('not_found', 'Zone không tồn tại');
    $zone->delete();
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}

function xclipse_shipping_method_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $zone   = new WC_Shipping_Zone((int)$r['zone_id']);
    $b      = $r->get_json_params();
    $iid    = (int)$r['instance_id'];
    $methods = $zone->get_shipping_methods();
    if (!isset($methods[$iid])) return xclipse_err('not_found', 'Shipping method không tồn tại');
    $m = $methods[$iid];
    foreach ($b as $k => $v) {
        $m->instance_settings[$k] = sanitize_text_field((string)$v);
    }
    update_option($m->get_instance_option_key(), $m->instance_settings);
    return xclipse_ok(['ok' => true, 'instance_id' => $iid]);
}

// TAX

function xclipse_tax_classes_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $classes = WC_Tax::get_tax_classes();
    $standard = [['slug' => 'standard', 'name' => 'Standard']];
    $rest = array_map(fn($c) => ['slug' => sanitize_title($c), 'name' => $c], $classes);
    return xclipse_ok(['classes' => array_merge($standard, $rest)]);
}

function xclipse_tax_rates_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    global $wpdb;
    $class = sanitize_text_field($r['class'] ?? '');
    $sql   = "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates";
    if ($class !== '') $sql .= $wpdb->prepare(" WHERE tax_rate_class = %s", $class);
    $sql .= " ORDER BY tax_rate_order ASC";
    $rates = $wpdb->get_results($sql, ARRAY_A);
    return xclipse_ok(['total' => count($rates), 'rates' => $rates]);
}

function xclipse_tax_rate_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b  = $r->get_json_params();
    $id = WC_Tax::_insert_tax_rate([
        'tax_rate_country'  => strtoupper(sanitize_text_field($b['country']  ?? '')),
        'tax_rate_state'    => strtoupper(sanitize_text_field($b['state']    ?? '')),
        'tax_rate'          => (float)($b['rate'] ?? 0),
        'tax_rate_name'     => sanitize_text_field($b['name']  ?? 'Tax'),
        'tax_rate_priority' => (int)($b['priority'] ?? 1),
        'tax_rate_compound' => (int)($b['compound']  ?? 0),
        'tax_rate_shipping' => (int)($b['shipping']  ?? 1),
        'tax_rate_order'    => (int)($b['order']     ?? 0),
        'tax_rate_class'    => sanitize_title($b['class'] ?? ''),
    ]);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_tax_rate_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b   = $r->get_json_params();
    $map = ['country' => 'tax_rate_country', 'state' => 'tax_rate_state',
            'name' => 'tax_rate_name', 'rate' => 'tax_rate', 'class' => 'tax_rate_class'];
    $data = [];
    foreach ($map as $from => $to) {
        if (isset($b[$from])) $data[$to] = sanitize_text_field((string)$b[$from]);
    }
    WC_Tax::_update_tax_rate((int)$r['id'], $data);
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

function xclipse_tax_rate_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    WC_Tax::_delete_tax_rate((int)$r['id']);
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}

// PRODUCT VARIATIONS

function xclipse_product_variations_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p || $p->get_type() !== 'variable') return xclipse_err('not_found', 'Sản phẩm variable không tồn tại');
    $vars = $p->get_children();
    $list = array_map(function($vid) {
        $v = wc_get_product($vid);
        return [
            'id'           => $vid,
            'sku'          => $v->get_sku(),
            'price'        => $v->get_price(),
            'regular'      => $v->get_regular_price(),
            'sale'         => $v->get_sale_price() ?: null,
            'stock_status' => $v->get_stock_status(),
            'stock_qty'    => $v->get_stock_quantity(),
            'attributes'   => $v->get_variation_attributes(),
            'status'       => $v->get_status(),
            'image'        => wp_get_attachment_url($v->get_image_id()) ?: null,
        ];
    }, $vars);
    return xclipse_ok(['total' => count($list), 'variations' => $list]);
}

function xclipse_product_variation_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $v = wc_get_product((int)$r['variation_id']);
    if (!$v || $v->get_type() !== 'variation') return xclipse_err('not_found', 'Variation không tồn tại');
    return xclipse_ok([
        'id'         => $v->get_id(),
        'parent_id'  => $v->get_parent_id(),
        'sku'        => $v->get_sku(),
        'price'      => $v->get_price(),
        'regular'    => $v->get_regular_price(),
        'sale'       => $v->get_sale_price() ?: null,
        'stock'      => $v->get_stock_status(),
        'qty'        => $v->get_stock_quantity(),
        'attributes' => $v->get_variation_attributes(),
        'weight'     => $v->get_weight() ?: null,
        'dimensions' => ['l' => $v->get_length(), 'w' => $v->get_width(), 'h' => $v->get_height()],
    ]);
}

function xclipse_product_variation_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Sản phẩm không tồn tại');
    $b = $r->get_json_params();
    $v = new WC_Product_Variation();
    $v->set_parent_id($p->get_id());
    if (isset($b['regular_price']))  $v->set_regular_price($b['regular_price']);
    if (isset($b['sale_price']))     $v->set_sale_price($b['sale_price']);
    if (isset($b['sku']))            $v->set_sku(sanitize_text_field($b['sku']));
    if (isset($b['stock_quantity'])) $v->set_stock_quantity((int)$b['stock_quantity']);
    if (isset($b['stock_status']))   $v->set_stock_status($b['stock_status']);
    if (isset($b['attributes']))     $v->set_attributes($b['attributes']);
    $v->set_status($b['status'] ?? 'publish');
    $id = $v->save();
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_product_variation_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $v = wc_get_product((int)$r['variation_id']);
    if (!$v || $v->get_type() !== 'variation') return xclipse_err('not_found', 'Variation không tồn tại');
    $b = $r->get_json_params();
    if (isset($b['regular_price']))  $v->set_regular_price($b['regular_price']);
    if (isset($b['sale_price']))     $v->set_sale_price($b['sale_price']);
    if (isset($b['sku']))            $v->set_sku(sanitize_text_field($b['sku']));
    if (isset($b['stock_quantity'])) $v->set_stock_quantity((int)$b['stock_quantity']);
    if (isset($b['stock_status']))   $v->set_stock_status($b['stock_status']);
    if (isset($b['status']))         $v->set_status($b['status']);
    $v->save();
    return xclipse_ok(['ok' => true, 'id' => $v->get_id()]);
}

function xclipse_product_variation_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $v = wc_get_product((int)$r['variation_id']);
    if (!$v) return xclipse_err('not_found', 'Variation không tồn tại');
    $force = filter_var($r->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
    $v->delete($force);
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['variation_id']]);
}

// PRODUCT ATTRIBUTES

function xclipse_product_attributes_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $attrs = wc_get_attribute_taxonomies();
    return xclipse_ok(['total' => count($attrs), 'attributes' => array_map(fn($a) => [
        'id'       => $a->attribute_id,
        'name'     => $a->attribute_name,
        'label'    => $a->attribute_label,
        'type'     => $a->attribute_type,
        'orderby'  => $a->attribute_orderby,
        'public'   => (bool)$a->attribute_public,
        'taxonomy' => 'pa_' . $a->attribute_name,
    ], $attrs)]);
}

function xclipse_product_attribute_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b  = $r->get_json_params();
    $id = wc_create_attribute([
        'name'    => sanitize_text_field($b['label'] ?? ''),
        'slug'    => sanitize_title($b['name'] ?? $b['label'] ?? ''),
        'type'    => $b['type']    ?? 'select',
        'orderby' => $b['orderby'] ?? 'menu_order',
        'has_archives' => (bool)($b['public'] ?? false),
    ]);
    if (is_wp_error($id)) return xclipse_err('create_failed', $id->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_product_attribute_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b   = $r->get_json_params();
    $res = wc_update_attribute((int)$r['id'], [
        'name'    => sanitize_text_field($b['label'] ?? ''),
        'slug'    => sanitize_title($b['name'] ?? ''),
        'type'    => $b['type']    ?? 'select',
        'orderby' => $b['orderby'] ?? 'menu_order',
        'has_archives' => (bool)($b['public'] ?? false),
    ]);
    if (is_wp_error($res)) return xclipse_err('update_failed', $res->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

function xclipse_product_attribute_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $res = wc_delete_attribute((int)$r['id']);
    if (is_wp_error($res)) return xclipse_err('delete_failed', $res->get_error_message());
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}

function xclipse_product_attribute_terms_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $taxonomy = 'pa_' . sanitize_title($r['attribute']);
    $terms    = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms)) return xclipse_err('not_found', 'Attribute không tồn tại');
    return xclipse_ok(['total' => count($terms), 'terms' => array_map(fn($t) => [
        'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count,
    ], $terms)]);
}

function xclipse_product_attribute_term_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b        = $r->get_json_params();
    $taxonomy = 'pa_' . sanitize_title($r['attribute']);
    $term     = wp_insert_term(sanitize_text_field($b['name'] ?? ''), $taxonomy, [
        'slug' => sanitize_title($b['slug'] ?? $b['name'] ?? ''),
    ]);
    if (is_wp_error($term)) return xclipse_err('create_failed', $term->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => $term['term_id']]);
}

function xclipse_product_attribute_term_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b        = $r->get_json_params();
    $taxonomy = 'pa_' . sanitize_title($r['attribute']);
    $res      = wp_update_term((int)$r['term_id'], $taxonomy, [
        'name' => sanitize_text_field($b['name'] ?? ''),
        'slug' => sanitize_title($b['slug'] ?? ''),
    ]);
    if (is_wp_error($res)) return xclipse_err('update_failed', $res->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => (int)$r['term_id']]);
}

// PRODUCT REVIEWS

function xclipse_product_reviews_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $args = [
        'post_type'  => 'product',
        'number'     => min((int)($r['per_page'] ?? 20), 100),
        'offset'     => ((int)($r['page'] ?? 1) - 1) * (int)($r['per_page'] ?? 20),
        'status'     => $r['status'] ?? 'approve',
    ];
    if ($r['product_id']) $args['post_id'] = (int)$r['product_id'];
    $comments = get_comments($args);
    $total    = get_comments(array_merge($args, ['count' => true, 'number' => 0, 'offset' => 0]));
    return xclipse_ok(['total' => $total, 'reviews' => array_map(fn($c) => [
        'id'         => $c->comment_ID,
        'product_id' => $c->comment_post_ID,
        'author'     => $c->comment_author,
        'email'      => $c->comment_author_email,
        'date'       => substr($c->comment_date, 0, 16),
        'content'    => $c->comment_content,
        'rating'     => get_comment_meta($c->comment_ID, 'rating', true),
        'status'     => $c->comment_approved,
        'verified'   => (bool)get_comment_meta($c->comment_ID, 'verified', true),
    ], $comments)]);
}

function xclipse_product_review_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $c = get_comment((int)$r['id']);
    if (!$c) return xclipse_err('not_found', 'Review không tồn tại');
    return xclipse_ok([
        'id'         => $c->comment_ID,
        'product_id' => $c->comment_post_ID,
        'author'     => $c->comment_author,
        'email'      => $c->comment_author_email,
        'content'    => $c->comment_content,
        'rating'     => get_comment_meta($c->comment_ID, 'rating', true),
        'status'     => $c->comment_approved,
        'date'       => $c->comment_date,
    ]);
}

function xclipse_product_review_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $c = get_comment((int)$r['id']);
    if (!$c) return xclipse_err('not_found', 'Review không tồn tại');
    $b = $r->get_json_params();
    $data = ['comment_ID' => $c->comment_ID];
    if (isset($b['content'])) $data['comment_content'] = wp_kses_post($b['content']);
    if (isset($b['status']))  $data['comment_approved'] = $b['status'];
    if (isset($b['rating']))  update_comment_meta($c->comment_ID, 'rating', (int)$b['rating']);
    wp_update_comment($data);
    return xclipse_ok(['ok' => true, 'id' => $c->comment_ID]);
}

function xclipse_product_review_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $force = filter_var($r->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
    $res   = wp_delete_comment((int)$r['id'], $force);
    return $res ? xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]) : xclipse_err('delete_failed', 'Không xoá được review');
}

// PRODUCT TAGS

function xclipse_product_tags_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $args = ['taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => (int)($r['per_page'] ?? 100)];
    if ($r['search']) $args['search'] = sanitize_text_field($r['search']);
    $tags = get_terms($args);
    return xclipse_ok(['total' => count($tags), 'tags' => array_map(fn($t) => [
        'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count,
    ], $tags)]);
}

function xclipse_product_tag_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b    = $r->get_json_params();
    $term = wp_insert_term(sanitize_text_field($b['name'] ?? ''), 'product_tag', [
        'slug'        => sanitize_title($b['slug'] ?? ''),
        'description' => sanitize_text_field($b['description'] ?? ''),
    ]);
    if (is_wp_error($term)) return xclipse_err('create_failed', $term->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => $term['term_id']]);
}

function xclipse_product_tag_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b   = $r->get_json_params();
    $res = wp_update_term((int)$r['id'], 'product_tag', [
        'name'        => sanitize_text_field($b['name'] ?? ''),
        'slug'        => sanitize_title($b['slug'] ?? ''),
        'description' => sanitize_text_field($b['description'] ?? ''),
    ]);
    if (is_wp_error($res)) return xclipse_err('update_failed', $res->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

// PRODUCT STOCK BULK UPDATE

function xclipse_product_stock_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b       = $r->get_json_params();
    $items   = $b['items'] ?? [];
    $results = [];
    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        $p   = wc_get_product($pid);
        if (!$p) { $results[] = ['id' => $pid, 'status' => 'not_found']; continue; }
        if (isset($item['stock_quantity'])) $p->set_stock_quantity((int)$item['stock_quantity']);
        if (isset($item['stock_status']))   $p->set_stock_status($item['stock_status']);
        $p->save();
        $results[] = ['id' => $pid, 'status' => 'updated', 'stock' => $p->get_stock_status(), 'qty' => $p->get_stock_quantity()];
    }
    return xclipse_ok(['updated' => count(array_filter($results, fn($r) => $r['status'] === 'updated')), 'results' => $results]);
}

function xclipse_product_duplicate(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $p = wc_get_product((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Sản phẩm không tồn tại');
    if (!function_exists('wc_copy_product_image_from_post')) {
        require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';
    }
    $duplicate = (new WC_Admin_Duplicate_Product())->product_duplicate($p);
    return xclipse_ok(['ok' => true, 'original_id' => $p->get_id(), 'new_id' => $duplicate->get_id()]);
}

function xclipse_bulk_products_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b     = $r->get_json_params();
    $ids   = array_map('intval', $b['ids'] ?? []);
    $force = filter_var($b['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$ids) return xclipse_err('missing_ids', 'Cần truyền ids[]');
    $deleted = 0;
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p) { $p->delete($force); $deleted++; }
    }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted]);
}

// ORDER NOTES & ITEMS

function xclipse_order_notes_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    $notes = wc_get_order_notes(['order_id' => $o->get_id(), 'type' => $r['type'] ?? '']);
    return xclipse_ok(['total' => count($notes), 'notes' => array_map(fn($n) => [
        'id'            => $n->comment_ID,
        'date'          => substr($n->comment_date, 0, 16),
        'note'          => strip_tags($n->comment_content),
        'customer_note' => (bool)$n->added_by_user,
        'author'        => $n->comment_author,
    ], $notes)]);
}

function xclipse_order_note_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    $b  = $r->get_json_params();
    $id = $o->add_order_note(
        wp_kses_post($b['note'] ?? ''),
        (bool)($b['customer_note'] ?? false),
        (bool)($b['added_by_user'] ?? false)
    );
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_order_note_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    wc_delete_order_note((int)$r['note_id']);
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['note_id']]);
}

function xclipse_order_items_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $o = wc_get_order((int)$r['id']);
    if (!$o) return xclipse_err('not_found', 'Đơn hàng không tồn tại');
    $items = array_values($o->get_items());
    return xclipse_ok(['total' => count($items), 'items' => array_map(fn($i) => [
        'id'         => $i->get_id(),
        'product_id' => $i->get_product_id(),
        'variation_id' => $i->get_variation_id() ?: null,
        'name'       => $i->get_name(),
        'quantity'   => $i->get_quantity(),
        'subtotal'   => $i->get_subtotal(),
        'total'      => $i->get_total(),
        'tax'        => $i->get_total_tax(),
        'sku'        => ($p = $i->get_product()) ? $p->get_sku() : null,
    ], $items)]);
}

function xclipse_order_status_bulk(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b      = $r->get_json_params();
    $ids    = array_map('intval', $b['ids'] ?? []);
    $status = sanitize_text_field($b['status'] ?? '');
    if (!$ids)    return xclipse_err('missing_ids', 'Cần truyền ids[]');
    if (!$status) return xclipse_err('missing_status', 'Cần truyền status');
    $updated = 0;
    foreach ($ids as $id) {
        $o = wc_get_order($id);
        if ($o) { $o->update_status($status); $updated++; }
    }
    return xclipse_ok(['ok' => true, 'updated' => $updated, 'status' => $status]);
}

// CUSTOMER EXTENDED

function xclipse_customer_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b  = $r->get_json_params();
    $email = sanitize_email($b['email'] ?? '');
    if (!$email) return xclipse_err('missing_email', 'email là bắt buộc');
    $customer = new WC_Customer();
    $customer->set_email($email);
    if (isset($b['first_name'])) $customer->set_first_name(sanitize_text_field($b['first_name']));
    if (isset($b['last_name']))  $customer->set_last_name(sanitize_text_field($b['last_name']));
    if (isset($b['username']))   $customer->set_username(sanitize_text_field($b['username']));
    if (isset($b['password']))   $customer->set_password($b['password']);
    if (isset($b['phone']))      $customer->set_billing_phone(sanitize_text_field($b['phone']));
    $id = $customer->save();
    if (is_wp_error($id)) return xclipse_err('create_failed', $id->get_error_message());
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_customer_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $customer = new WC_Customer((int)$r['id']);
    if (!$customer->get_id()) return xclipse_err('not_found', 'Khách hàng không tồn tại');
    $b = $r->get_json_params();
    if (isset($b['first_name']))  $customer->set_first_name(sanitize_text_field($b['first_name']));
    if (isset($b['last_name']))   $customer->set_last_name(sanitize_text_field($b['last_name']));
    if (isset($b['email']))       $customer->set_email(sanitize_email($b['email']));
    if (isset($b['phone']))       $customer->set_billing_phone(sanitize_text_field($b['phone']));
    if (isset($b['billing']))     foreach ($b['billing'] as $k => $v) $customer->{"set_billing_{$k}"}(sanitize_text_field($v));
    if (isset($b['shipping']))    foreach ($b['shipping'] as $k => $v) $customer->{"set_shipping_{$k}"}(sanitize_text_field($v));
    $customer->save();
    return xclipse_ok(['ok' => true, 'id' => (int)$r['id']]);
}

function xclipse_customer_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $customer = new WC_Customer((int)$r['id']);
    if (!$customer->get_id()) return xclipse_err('not_found', 'Khách hàng không tồn tại');
    $customer->delete();
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}

function xclipse_customer_orders_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $orders = wc_get_orders([
        'customer_id' => (int)$r['id'],
        'limit'       => min((int)($r['per_page'] ?? 20), 50),
        'page'        => (int)($r['page'] ?? 1),
        'return'      => 'objects',
    ]);
    return xclipse_ok(['total' => count($orders), 'orders' => array_map(fn($o) => [
        'id'     => $o->get_id(),
        'status' => $o->get_status(),
        'total'  => $o->get_total(),
        'date'   => ($d = $o->get_date_created()) ? substr($d->date('Y-m-d H:i:s'), 0, 16) : '',
    ], $orders)]);
}

// WC REPORTS

function xclipse_wc_report_sales(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    global $wpdb;
    $period = $r['period'] ?? '30d';
    $days   = $period === '7d' ? 7 : ($period === '90d' ? 90 : 30);
    $from   = date('Y-m-d', strtotime("-{$days} days"));

    $row = $wpdb->get_row($wpdb->prepare("
        SELECT
            COUNT(DISTINCT p.ID) as order_count,
            SUM(pm.meta_value) as revenue
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed','wc-processing')
        AND p.post_date >= %s
    ", $from), ARRAY_A);

    return xclipse_ok([
        'period'      => $period,
        'from'        => $from,
        'order_count' => (int)($row['order_count'] ?? 0),
        'revenue'     => (float)($row['revenue'] ?? 0),
    ]);
}

function xclipse_wc_report_top_sellers(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    global $wpdb;
    $limit = min((int)($r['limit'] ?? 10), 50);
    $days  = (int)($r['days'] ?? 30);
    $from  = date('Y-m-d', strtotime("-{$days} days"));

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT oi.order_item_name as name,
               oim.meta_value as product_id,
               SUM(oim2.meta_value) as qty_sold
        FROM {$wpdb->prefix}woocommerce_order_items oi
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_qty'
        JOIN {$wpdb->posts} p ON oi.order_id = p.ID AND p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date >= %s
        GROUP BY oim.meta_value
        ORDER BY qty_sold DESC
        LIMIT %d
    ", $from, $limit), ARRAY_A);

    return xclipse_ok(['period_days' => $days, 'top_sellers' => $results]);
}

function xclipse_wc_report_stock(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $type = $r['type'] ?? 'outofstock'; // outofstock | lowstock | all
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => min((int)($r['per_page'] ?? 20), 100),
        'meta_query'     => [['key' => '_manage_stock', 'value' => 'yes']],
    ];
    if ($type === 'outofstock') {
        $args['meta_query'][] = ['key' => '_stock_status', 'value' => 'outofstock'];
    } elseif ($type === 'lowstock') {
        $low = get_option('woocommerce_notify_low_stock_amount', 2);
        $args['meta_query'][] = ['key' => '_stock', 'value' => $low, 'compare' => '<=', 'type' => 'NUMERIC'];
        $args['meta_query'][] = ['key' => '_stock', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'];
    }
    $q = new WP_Query($args);
    return xclipse_ok(['type' => $type, 'total' => $q->found_posts, 'products' => array_map(fn($p) => [
        'id'    => $p->ID,
        'name'  => $p->post_title,
        'sku'   => get_post_meta($p->ID, '_sku', true),
        'stock' => get_post_meta($p->ID, '_stock', true),
        'status'=> get_post_meta($p->ID, '_stock_status', true),
    ], $q->posts)]);
}

function xclipse_wc_report_coupons(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    global $wpdb;
    $days = (int)($r['days'] ?? 30);
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT meta_value as code, COUNT(*) as usage_count
        FROM {$wpdb->prefix}woocommerce_order_itemmeta
        WHERE meta_key = 'Coupon code'
        AND order_item_id IN (
            SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->posts} p ON oi.order_id = p.ID AND p.post_date >= %s
        )
        GROUP BY meta_value ORDER BY usage_count DESC LIMIT 20
    ", $from), ARRAY_A);
    return xclipse_ok(['period_days' => $days, 'coupons' => $rows]);
}

// WC SETTINGS

function xclipse_wc_settings_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $group = sanitize_text_field($r['group'] ?? 'general');
    $allowed = ['general', 'products', 'tax', 'shipping', 'checkout', 'email', 'advanced'];
    if (!in_array($group, $allowed, true)) return xclipse_err('invalid_group', 'group phải là: ' . implode(', ', $allowed));

    $settings_obj = new WC_REST_Setting_Options_V2_Controller();
    $request = new WP_REST_Request('GET');
    $request->set_param('group_id', $group);
    $response = $settings_obj->get_items($request);
    return xclipse_ok(['group' => $group, 'settings' => $response->get_data()]);
}

function xclipse_wc_settings_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b     = $r->get_json_params();
    $group = sanitize_text_field($b['group'] ?? 'general');
    $items = $b['settings'] ?? [];
    $updated = [];
    foreach ($items as $key => $value) {
        update_option($key, $value);
        $updated[$key] = get_option($key);
    }
    return xclipse_ok(['ok' => true, 'group' => $group, 'updated' => $updated]);
}

// WC EMAIL TEMPLATES

function xclipse_wc_email_list() {
    if (($e = xclipse_woo()) !== true) return $e;
    $emails = WC()->mailer()->get_emails();
    $list   = [];
    foreach ($emails as $id => $email) {
        $list[] = [
            'id'          => $id,
            'title'       => $email->get_title(),
            'description' => $email->get_description(),
            'enabled'     => $email->is_enabled(),
            'recipient'   => $email->get_recipient(),
            'subject'     => $email->get_subject(),
            'type'        => $email->get_email_type(),
        ];
    }
    return xclipse_ok(['total' => count($list), 'emails' => $list]);
}

function xclipse_wc_email_get(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $emails = WC()->mailer()->get_emails();
    $id     = $r['id'];
    if (!isset($emails[$id])) return xclipse_err('not_found', "Email '{$id}' không tồn tại");
    $email = $emails[$id];
    return xclipse_ok([
        'id'          => $id,
        'enabled'     => $email->is_enabled(),
        'subject'     => $email->get_subject(),
        'heading'     => $email->get_heading(),
        'recipient'   => $email->get_recipient(),
        'additional'  => $email->get_option('additional_content', ''),
        'type'        => $email->get_email_type(),
    ]);
}

function xclipse_wc_email_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $emails = WC()->mailer()->get_emails();
    $id     = $r['id'];
    if (!isset($emails[$id])) return xclipse_err('not_found', "Email '{$id}' không tồn tại");
    $email = $emails[$id];
    $b     = $r->get_json_params();
    if (isset($b['enabled']))    $email->settings['enabled']             = $b['enabled'] ? 'yes' : 'no';
    if (isset($b['subject']))    $email->settings['subject']             = sanitize_text_field($b['subject']);
    if (isset($b['heading']))    $email->settings['heading']             = sanitize_text_field($b['heading']);
    if (isset($b['recipient']))  $email->settings['recipient']           = sanitize_email($b['recipient']);
    if (isset($b['additional'])) $email->settings['additional_content']  = wp_kses_post($b['additional']);
    update_option($email->get_option_key(), $email->settings);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

// WEBHOOKS

function xclipse_webhooks_list(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $data_store = WC_Data_Store::load('webhook');
    $ids  = $data_store->get_webhooks_ids();
    $list = [];
    foreach ($ids as $id) {
        $wh = new WC_Webhook($id);
        $list[] = [
            'id'           => $wh->get_id(),
            'name'         => $wh->get_name(),
            'status'       => $wh->get_status(),
            'topic'        => $wh->get_topic(),
            'delivery_url' => $wh->get_delivery_url(),
            'created'      => substr($wh->get_date_created()->date('Y-m-d H:i:s'), 0, 16),
        ];
    }
    return xclipse_ok(['total' => count($list), 'webhooks' => $list]);
}

function xclipse_webhook_create(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $b  = $r->get_json_params();
    $wh = new WC_Webhook();
    $wh->set_name(sanitize_text_field($b['name'] ?? 'New Webhook'));
    $wh->set_topic($b['topic'] ?? 'order.created');
    $wh->set_delivery_url(esc_url_raw($b['delivery_url'] ?? ''));
    $wh->set_secret(sanitize_text_field($b['secret'] ?? wp_generate_password(50, true, true)));
    $wh->set_status($b['status'] ?? 'active');
    $id = $wh->save();
    return xclipse_ok(['ok' => true, 'id' => $id, 'secret' => $wh->get_secret()]);
}

function xclipse_webhook_update(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $wh = new WC_Webhook((int)$r['id']);
    if (!$wh->get_id()) return xclipse_err('not_found', 'Webhook không tồn tại');
    $b  = $r->get_json_params();
    if (isset($b['name']))         $wh->set_name(sanitize_text_field($b['name']));
    if (isset($b['topic']))        $wh->set_topic($b['topic']);
    if (isset($b['delivery_url'])) $wh->set_delivery_url(esc_url_raw($b['delivery_url']));
    if (isset($b['status']))       $wh->set_status($b['status']);
    $wh->save();
    return xclipse_ok(['ok' => true, 'id' => $wh->get_id()]);
}

function xclipse_webhook_delete(WP_REST_Request $r) {
    if (($e = xclipse_woo()) !== true) return $e;
    $wh = new WC_Webhook((int)$r['id']);
    if (!$wh->get_id()) return xclipse_err('not_found', 'Webhook không tồn tại');
    $wh->delete(true);
    return xclipse_ok(['ok' => true, 'deleted' => (int)$r['id']]);
}
