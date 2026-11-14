<?php
/** Menus & menu items */
defined('ABSPATH') || exit;

// MENUS
function xclipse_menus() {
    $navs = wp_get_nav_menus();
    $locs = get_nav_menu_locations();
    $loc_map = array_flip($locs);
    return xclipse_ok(array_map(fn($m) => ['id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => $m->count, 'location' => $loc_map[$m->term_id] ?? null], $navs));
}

function xclipse_menu_get(WP_REST_Request $r) {
    $id    = (int)$r['id'];
    $menu  = wp_get_nav_menu_object($id);
    if (!$menu) return xclipse_err('not_found', 'Menu không tồn tại');
    $items = wp_get_nav_menu_items($id) ?: [];
    return xclipse_ok(['id' => $menu->term_id, 'name' => $menu->name, 'items' => array_map(fn($i) => ['id' => $i->ID, 'title' => $i->title, 'url' => $i->url, 'parent' => (int)$i->menu_item_parent, 'order' => (int)$i->menu_order, 'type' => $i->type], $items)]);
}

// Helper: format 1 menu item thành array gọn
function xclipse_menu_item_fmt(object $i): array {
    return [
        'id'       => (int)$i->ID,
        'title'    => $i->title,
        'url'      => $i->url,
        'parent'   => (int)$i->menu_item_parent,
        'order'    => (int)$i->menu_order,
        'type'     => $i->type,           // custom|post_type|taxonomy
        'object'   => $i->object,         // page|post|category|...
        'object_id'=> (int)$i->object_id,
        'target'   => $i->target ?: null, // _blank hoặc rỗng
        'classes'  => implode(' ', array_filter((array)$i->classes)),
        'attr_title'=> $i->attr_title ?: null,
    ];
}

// POST /menus — Tạo menu mới
function xclipse_menu_create(WP_REST_Request $req) {
    $b    = $req->get_json_params();
    $name = sanitize_text_field($b['name'] ?? '');
    if (!$name) return xclipse_err('name_required', 'Thiếu tên menu');
    $id = wp_create_nav_menu($name);
    if (is_wp_error($id)) return $id;
    // Assign location nếu có
    if (!empty($b['location'])) {
        $locs = get_nav_menu_locations();
        $locs[sanitize_text_field($b['location'])] = $id;
        set_theme_mod('nav_menu_locations', $locs);
    }
    return xclipse_ok(['id' => $id, 'name' => $name]);
}

// PUT /menus/{id} — Đổi tên menu hoặc assign location
function xclipse_menu_update(WP_REST_Request $req) {
    $id = (int)$req['id'];
    $b  = $req->get_json_params();
    if (!wp_get_nav_menu_object($id)) return xclipse_err('not_found', 'Menu không tồn tại');

    if (!empty($b['name'])) {
        $r = wp_update_nav_menu_object($id, ['menu-name' => sanitize_text_field($b['name'])]);
        if (is_wp_error($r)) return $r;
    }
    if (isset($b['location'])) {
        $locs = get_nav_menu_locations();
        $loc  = sanitize_text_field($b['location']);
        if ($loc === '') {
            // Gỡ menu khỏi tất cả locations
            foreach ($locs as $l => $mid) {
                if ($mid === $id) unset($locs[$l]);
            }
        } else {
            $locs[$loc] = $id;
        }
        set_theme_mod('nav_menu_locations', $locs);
    }
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

// DELETE /menus/{id} — Xóa menu
function xclipse_menu_delete(WP_REST_Request $req) {
    $id = (int)$req['id'];
    if (!wp_get_nav_menu_object($id)) return xclipse_err('not_found', 'Menu không tồn tại');
    $r = wp_delete_nav_menu($id);
    if (is_wp_error($r)) return $r;
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

// POST /menus/{id}/items — Thêm item vào menu
function xclipse_menu_item_add(WP_REST_Request $req) {
    $menu_id = (int)$req['id'];
    if (!wp_get_nav_menu_object($menu_id)) return xclipse_err('not_found', 'Menu không tồn tại');
    $b = $req->get_json_params();

    $args = [
        'menu-item-title'     => sanitize_text_field($b['title'] ?? ''),
        'menu-item-url'       => esc_url_raw($b['url'] ?? ''),
        'menu-item-status'    => 'publish',
        'menu-item-type'      => sanitize_text_field($b['type'] ?? 'custom'),
        'menu-item-object'    => sanitize_text_field($b['object'] ?? ''),
        'menu-item-object-id' => (int)($b['object_id'] ?? 0),
        'menu-item-parent-id' => (int)($b['parent'] ?? 0),
        'menu-item-position'  => (int)($b['order'] ?? 0),
        'menu-item-target'    => sanitize_text_field($b['target'] ?? ''),
        'menu-item-classes'   => sanitize_text_field($b['classes'] ?? ''),
        'menu-item-attr-title'=> sanitize_text_field($b['attr_title'] ?? ''),
    ];

    $item_id = wp_update_nav_menu_item($menu_id, 0, $args);
    if (is_wp_error($item_id)) return $item_id;

    $items = wp_get_nav_menu_items($menu_id) ?: [];
    $new   = null;
    foreach ($items as $i) {
        if ($i->ID === $item_id) { $new = xclipse_menu_item_fmt($i); break; }
    }
    return xclipse_ok(['id' => $item_id, 'item' => $new]);
}

// PUT /menus/{id}/items/{item_id} — Sửa item
function xclipse_menu_item_update(WP_REST_Request $req) {
    $menu_id = (int)$req['id'];
    $item_id = (int)$req['item_id'];
    $b       = $req->get_json_params();

    $existing = get_post($item_id);
    if (!$existing || $existing->post_type !== 'nav_menu_item') {
        return xclipse_err('not_found', 'Menu item không tồn tại');
    }

    // Lấy meta hiện tại để giữ nguyên fields không truyền
    $current_url    = get_post_meta($item_id, '_menu_item_url', true);
    $current_type   = get_post_meta($item_id, '_menu_item_type', true);
    $current_obj    = get_post_meta($item_id, '_menu_item_object', true);
    $current_obj_id = (int)get_post_meta($item_id, '_menu_item_object_id', true);
    $current_parent = (int)get_post_meta($item_id, '_menu_item_menu_item_parent', true);

    $args = [
        'menu-item-title'     => isset($b['title'])     ? sanitize_text_field($b['title'])     : $existing->post_title,
        'menu-item-url'       => isset($b['url'])       ? esc_url_raw($b['url'])               : $current_url,
        'menu-item-status'    => 'publish',
        'menu-item-type'      => isset($b['type'])      ? sanitize_text_field($b['type'])      : $current_type,
        'menu-item-object'    => isset($b['object'])    ? sanitize_text_field($b['object'])    : $current_obj,
        'menu-item-object-id' => isset($b['object_id']) ? (int)$b['object_id']                : $current_obj_id,
        'menu-item-parent-id' => isset($b['parent'])    ? (int)$b['parent']                   : $current_parent,
        'menu-item-position'  => isset($b['order'])     ? (int)$b['order']                    : (int)$existing->menu_order,
        'menu-item-target'    => isset($b['target'])    ? sanitize_text_field($b['target'])    : get_post_meta($item_id, '_menu_item_target', true),
        'menu-item-classes'   => isset($b['classes'])   ? sanitize_text_field($b['classes'])   : implode(' ', (array)get_post_meta($item_id, '_menu_item_classes', true)),
        'menu-item-attr-title'=> isset($b['attr_title'])? sanitize_text_field($b['attr_title']): get_post_meta($item_id, '_menu_item_attr_title', true),
    ];

    $r = wp_update_nav_menu_item($menu_id, $item_id, $args);
    if (is_wp_error($r)) return $r;

    $items   = wp_get_nav_menu_items($menu_id) ?: [];
    $updated = null;
    foreach ($items as $i) {
        if ($i->ID === $item_id) { $updated = xclipse_menu_item_fmt($i); break; }
    }
    return xclipse_ok(['ok' => true, 'item' => $updated]);
}

// DELETE /menus/{id}/items/{item_id} — Xóa item
function xclipse_menu_item_delete(WP_REST_Request $req) {
    $item_id = (int)$req['item_id'];
    $existing = get_post($item_id);
    if (!$existing || $existing->post_type !== 'nav_menu_item') {
        return xclipse_err('not_found', 'Menu item không tồn tại');
    }
    wp_delete_post($item_id, true);
    return xclipse_ok(['ok' => true, 'item_id' => $item_id]);
}

// POST /menus/{id}/reorder — Sắp xếp lại thứ tự items
function xclipse_menu_reorder(WP_REST_Request $req) {
    $menu_id = (int)$req['id'];
    if (!wp_get_nav_menu_object($menu_id)) return xclipse_err('not_found', 'Menu không tồn tại');
    $b = $req->get_json_params();
    // items: [{'id': 123, 'order': 1, 'parent': 0}, ...]
    $items = $b['items'] ?? [];
    if (empty($items)) return xclipse_err('items_required', 'Thiếu danh sách items');

    foreach ((array)$items as $entry) {
        $item_id = (int)($entry['id'] ?? 0);
        $order   = (int)($entry['order'] ?? 0);
        $parent  = (int)($entry['parent'] ?? 0);
        if (!$item_id) continue;
        wp_update_post(['ID' => $item_id, 'menu_order' => $order]);
        if (isset($entry['parent'])) {
            update_post_meta($item_id, '_menu_item_menu_item_parent', $parent);
        }
    }

    // Trả về menu sau khi reorder
    $updated_items = wp_get_nav_menu_items($menu_id) ?: [];
    return xclipse_ok([
        'ok'    => true,
        'menu_id'=> $menu_id,
        'items' => array_map('xclipse_menu_item_fmt', $updated_items),
    ]);
}

// GET /menus/locations — Xem tất cả registered locations và menu đang assign
function xclipse_menu_locations() {
    $registered = get_registered_nav_menus(); // ['primary' => 'Primary Menu', ...]
    $assigned   = get_nav_menu_locations();   // ['primary' => 3, ...]
    $result     = [];
    foreach ($registered as $loc => $label) {
        $menu_id   = $assigned[$loc] ?? 0;
        $menu_obj  = $menu_id ? wp_get_nav_menu_object($menu_id) : null;
        $result[]  = [
            'location'    => $loc,
            'label'       => $label,
            'menu_id'     => $menu_id ?: null,
            'menu_name'   => $menu_obj ? $menu_obj->name : null,
        ];
    }
    return xclipse_ok($result);
}

// POST /menus/locations — Assign menu vào location
function xclipse_menu_assign_location(WP_REST_Request $req) {
    $b        = $req->get_json_params();
    $location = sanitize_text_field($b['location'] ?? '');
    $menu_id  = (int)($b['menu_id'] ?? 0);

    $registered = get_registered_nav_menus();
    if (!isset($registered[$location])) {
        return xclipse_err('invalid_location', "Location '{$location}' không tồn tại. Các location hợp lệ: " . implode(', ', array_keys($registered)));
    }

    $locs = get_nav_menu_locations();
    if ($menu_id === 0) {
        unset($locs[$location]); // Gỡ menu khỏi location
    } else {
        if (!wp_get_nav_menu_object($menu_id)) return xclipse_err('not_found', "Menu ID {$menu_id} không tồn tại");
        $locs[$location] = $menu_id;
    }
    set_theme_mod('nav_menu_locations', $locs);
    return xclipse_ok(['ok' => true, 'location' => $location, 'menu_id' => $menu_id ?: null]);
}