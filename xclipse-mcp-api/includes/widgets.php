<?php
/** Widget areas management */
defined('ABSPATH') || exit;

// WIDGET AREAS — v3.5.6
// Quản lý WordPress Widget Areas (Sidebars) và Widgets

/** Helper: lấy widget type và instance id từ widget_id string (vd: "text-3" → ['text', 3]) */
function xclipse_parse_widget_id(string $widget_id): array {
    if (preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
        return [$m[1], (int)$m[2]];
    }
    return [$widget_id, 0];
}

/** Helper: format thông tin một widget */
function xclipse_format_widget(string $widget_id): array {
    [$type, $instance_id] = xclipse_parse_widget_id($widget_id);
    $instances = get_option("widget_{$type}", []);
    $settings  = $instances[$instance_id] ?? null;
    return [
        'widget_id'   => $widget_id,
        'type'        => $type,
        'instance_id' => $instance_id,
        'settings'    => $settings,
    ];
}

function xclipse_widgets_list() {
    global $wp_registered_sidebars;
    $sidebars_widgets = wp_get_sidebars_widgets();
    $result = [];

    foreach ($wp_registered_sidebars as $id => $sidebar) {
        $widgets  = xclipse_format_widgets_batch($sidebars_widgets[$id] ?? []);
        $result[] = [
            'id'          => $id,
            'name'        => $sidebar['name'],
            'description' => $sidebar['description'] ?? '',
            'widget_count'=> count($widgets),
            'widgets'     => $widgets,
        ];
    }

    return xclipse_ok(['sidebars' => $result, 'total' => count($result)]);
}

function xclipse_sidebar_get(WP_REST_Request $r) {
    global $wp_registered_sidebars;
    $sidebar_id = $r['sidebar'];

    if (!isset($wp_registered_sidebars[$sidebar_id])) {
        return xclipse_err('not_found', "Sidebar '{$sidebar_id}' không tồn tại", 404);
    }

    $sidebar          = $wp_registered_sidebars[$sidebar_id];
    $sidebars_widgets = wp_get_sidebars_widgets();
    $widget_ids       = $sidebars_widgets[$sidebar_id] ?? [];
    $widgets          = xclipse_format_widgets_batch($widget_ids);

    return xclipse_ok([
        'id'          => $sidebar_id,
        'name'        => $sidebar['name'],
        'description' => $sidebar['description'] ?? '',
        'widgets'     => $widgets,
    ]);
}

/** POST /widgets/{sidebar} — Thêm widget mới vào sidebar. Body: type, settings, position */
function xclipse_widget_add(WP_REST_Request $r) {
    global $wp_registered_sidebars;
    $sidebar_id = $r['sidebar'];

    if (!isset($wp_registered_sidebars[$sidebar_id])) {
        return xclipse_err('not_found', "Sidebar '{$sidebar_id}' không tồn tại", 404);
    }

    $body     = $r->get_json_params();
    $type     = sanitize_key($body['type'] ?? '');
    $settings = $body['settings'] ?? [];
    $position = $body['position'] ?? null; // null = cuối

    if (!$type) return xclipse_err('missing_type', 'Thiếu "type" (vd: text, nav_menu, custom_html)', 400);

    // Lấy instances hiện tại, thêm instance mới
    $instances   = get_option("widget_{$type}", []);
    if (!is_array($instances)) $instances = [];

    // Tìm ID tiếp theo
    $ids = array_filter(array_keys($instances), 'is_int');
    $new_id = $ids ? (max($ids) + 1) : 2;

    $instances[$new_id] = $settings;
    update_option("widget_{$type}", $instances);

    // Thêm vào sidebar
    $sidebars_widgets = wp_get_sidebars_widgets();
    if (!isset($sidebars_widgets[$sidebar_id])) $sidebars_widgets[$sidebar_id] = [];

    $widget_id = "{$type}-{$new_id}";
    if ($position !== null && is_int($position)) {
        array_splice($sidebars_widgets[$sidebar_id], $position, 0, [$widget_id]);
    } else {
        $sidebars_widgets[$sidebar_id][] = $widget_id;
    }

    wp_set_sidebars_widgets($sidebars_widgets);

    return xclipse_ok([
        'widget_id'   => $widget_id,
        'type'        => $type,
        'instance_id' => $new_id,
        'sidebar'     => $sidebar_id,
        'settings'    => $settings,
    ]);
}

function xclipse_widget_update(WP_REST_Request $r) {
    $widget_id = $r['widget_id'];
    [$type, $instance_id] = xclipse_parse_widget_id($widget_id);

    if (!$instance_id) return xclipse_err('invalid_id', "Widget ID không hợp lệ: {$widget_id}", 400);

    $instances = get_option("widget_{$type}", []);
    if (!isset($instances[$instance_id])) {
        return xclipse_err('not_found', "Widget '{$widget_id}' không tồn tại", 404);
    }

    $body     = $r->get_json_params();
    $replace  = !empty($body['replace']);
    unset($body['replace']);

    $new_settings = $replace ? $body : array_merge($instances[$instance_id] ?? [], $body);
    $instances[$instance_id] = $new_settings;
    update_option("widget_{$type}", $instances);

    return xclipse_ok([
        'widget_id' => $widget_id,
        'type'      => $type,
        'settings'  => $new_settings,
    ]);
}

function xclipse_widget_delete(WP_REST_Request $r) {
    $sidebar_id = $r['sidebar'];
    $widget_id  = $r['widget_id'];
    $permanent  = (bool)($r->get_param('permanent') ?? false);

    // Xóa khỏi sidebar
    $sidebars_widgets = wp_get_sidebars_widgets();
    if (!isset($sidebars_widgets[$sidebar_id])) {
        return xclipse_err('not_found', "Sidebar '{$sidebar_id}' không tồn tại", 404);
    }

    $key = array_search($widget_id, $sidebars_widgets[$sidebar_id]);
    if ($key === false) {
        return xclipse_err('not_found', "Widget '{$widget_id}' không có trong sidebar '{$sidebar_id}'", 404);
    }

    unset($sidebars_widgets[$sidebar_id][$key]);
    $sidebars_widgets[$sidebar_id] = array_values($sidebars_widgets[$sidebar_id]);
    wp_set_sidebars_widgets($sidebars_widgets);

    // Xóa instance nếu permanent
    if ($permanent) {
        [$type, $instance_id] = xclipse_parse_widget_id($widget_id);
        $instances = get_option("widget_{$type}", []);
        unset($instances[$instance_id]);
        update_option("widget_{$type}", $instances);
    }

    return xclipse_ok(['removed' => $widget_id, 'sidebar' => $sidebar_id, 'permanent' => $permanent]);
}

function xclipse_widget_reorder(WP_REST_Request $r) {
    global $wp_registered_sidebars;
    $sidebar_id = $r['sidebar'];

    if (!isset($wp_registered_sidebars[$sidebar_id])) {
        return xclipse_err('not_found', "Sidebar '{$sidebar_id}' không tồn tại", 404);
    }

    $body  = $r->get_json_params();
    $order = $body['order'] ?? [];

    if (empty($order) || !is_array($order)) {
        return xclipse_err('missing_order', 'Truyền "order": ["widget_id_1", "widget_id_2", ...]', 400);
    }

    $sidebars_widgets = wp_get_sidebars_widgets();
    $current = $sidebars_widgets[$sidebar_id] ?? [];

    // Chỉ giữ widget IDs có trong sidebar hiện tại
    $new_order = array_values(array_filter($order, fn($id) => in_array($id, $current)));

    // Thêm lại những widget không có trong order (để không mất)
    foreach ($current as $id) {
        if (!in_array($id, $new_order)) $new_order[] = $id;
    }

    $sidebars_widgets[$sidebar_id] = $new_order;
    wp_set_sidebars_widgets($sidebars_widgets);

    return xclipse_ok(['sidebar' => $sidebar_id, 'order' => $new_order]);
}