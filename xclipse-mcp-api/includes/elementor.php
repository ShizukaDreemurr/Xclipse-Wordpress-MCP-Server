<?php
/**
 * Elementor — REST API tools v4.6.15
 * Hỗ trợ: Elementor Free 3.x / 4.x (không cần Pro)
 * Lưu trữ: _elementor_data (JSON), _elementor_page_settings (JSON)
 */
defined('ABSPATH') || exit;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Kiểm tra Elementor đang active */
function xclipse_el_active(): bool {
    return defined('ELEMENTOR_VERSION');
}

/** Đọc _elementor_data của post → array PHP */
function xclipse_el_get_data(int $post_id): array {
    $raw = get_post_meta($post_id, '_elementor_data', true);
    if (empty($raw)) return [];
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($data) ? $data : [];
}

/** Lưu _elementor_data, xoá cache */
function xclipse_el_save_data(int $post_id, array $data): void {
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
    update_post_meta($post_id, '_elementor_data', wp_slash($json));
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    // Xoá CSS cache Elementor
    if (xclipse_el_active()) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
    wp_cache_delete($post_id, 'post_meta');
    clean_post_cache($post_id);
}

/** Tạo ID 8 ký tự hex ngẫu nhiên (chuẩn Elementor) */
function xclipse_el_gen_id(): string {
    return substr(md5(uniqid(mt_rand(), true)), 0, 8);
}

/**
 * Tìm element theo ID trong cây JSON Elementor (đệ quy)
 * Trả về [&$element, &$parent_elements, $index]
 */
function &xclipse_el_find(array &$elements, string $id, ?array &$parent = null, ?int &$index = null) {
    $null = null;
    foreach ($elements as $i => &$el) {
        if (($el['id'] ?? '') === $id) {
            $parent = &$elements;
            $index  = $i;
            return $el;
        }
        if (!empty($el['elements'])) {
            $found = &xclipse_el_find($el['elements'], $id, $parent, $index);
            if ($found !== null) return $found;
        }
    }
    return $null;
}

/** Tìm container theo ID, trả về reference đến elements array của nó */
function &xclipse_el_find_container_elements(array &$elements, string $id) {
    $null = null;
    foreach ($elements as &$el) {
        if (($el['id'] ?? '') === $id) {
            return $el['elements'];
        }
        if (!empty($el['elements'])) {
            $found = &xclipse_el_find_container_elements($el['elements'], $id);
            if ($found !== null) return $found;
        }
    }
    return $null;
}

/** Xoá element theo ID (đệ quy), trả về true nếu tìm thấy */
function xclipse_el_delete_by_id(array &$elements, string $id): bool {
    foreach ($elements as $i => &$el) {
        if (($el['id'] ?? '') === $id) {
            array_splice($elements, $i, 1);
            return true;
        }
        if (!empty($el['elements']) && xclipse_el_delete_by_id($el['elements'], $id)) {
            return true;
        }
    }
    return false;
}

/** Flatten tất cả elements (không trả code) để list */
function xclipse_el_flatten(array $elements, string $parent_id = 'root', int $depth = 0): array {
    $out = [];
    foreach ($elements as $el) {
        $row = [
            'id'        => $el['id'] ?? '',
            'elType'    => $el['elType'] ?? '',
            'widgetType'=> $el['widgetType'] ?? null,
            'isInner'   => $el['isInner'] ?? false,
            'parent'    => $parent_id,
            'depth'     => $depth,
            'children'  => count($el['elements'] ?? []),
        ];
        // Preview: lấy title/text từ settings nếu có
        $s = $el['settings'] ?? [];
        foreach (['title','text','editor','button_text','html','url'] as $k) {
            if (!empty($s[$k]) && is_string($s[$k])) {
                $row['preview'] = mb_substr(wp_strip_all_tags($s[$k]), 0, 60);
                break;
            }
        }
        $out[] = $row;
        if (!empty($el['elements'])) {
            $out = array_merge($out, xclipse_el_flatten($el['elements'], $el['id'] ?? '', $depth + 1));
        }
    }
    return $out;
}

// ─── Endpoints ────────────────────────────────────────────────────────────────

/** Detect xem post/page có dùng Elementor không */
function xclipse_el_detect(WP_REST_Request $r) {
    $id   = (int)$r['id'];
    $mode = get_post_meta($id, '_elementor_edit_mode', true);
    $data = xclipse_el_get_data($id);
    return xclipse_ok([
        'post_id'       => $id,
        'is_elementor'  => $mode === 'builder',
        'edit_mode'     => $mode ?: 'none',
        'elementor_ver' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
        'element_count' => count($data),
        'page_settings' => (array)(json_decode(get_post_meta($id, '_elementor_page_settings', true) ?: '{}', true) ?: []),
    ]);
}

/** Lấy toàn bộ _elementor_data của 1 page/post */
function xclipse_el_page_get(WP_REST_Request $r) {
    $id   = (int)$r['id'];
    $flat = (bool)($r['flat'] ?? false);
    $data = xclipse_el_get_data($id);
    if ($flat) {
        return xclipse_ok([
            'post_id'  => $id,
            'total'    => count($data),
            'elements' => xclipse_el_flatten($data),
        ]);
    }
    return xclipse_ok(['post_id' => $id, 'data' => $data]);
}

/** Ghi toàn bộ _elementor_data (replace) */
function xclipse_el_page_set(WP_REST_Request $r) {
    $id   = (int)$r['id'];
    $b    = $r->get_json_params();
    $data = $b['data'] ?? null;
    if (!is_array($data)) return xclipse_err('invalid', 'data phải là array JSON Elementor hợp lệ');
    xclipse_el_save_data($id, $data);
    return xclipse_ok(['ok' => true, 'post_id' => $id, 'element_count' => count($data)]);
}

/** Lấy 1 element theo ID (kèm toàn bộ settings + children) */
function xclipse_el_element_get(WP_REST_Request $r) {
    $post_id = (int)($r['id'] ?? $r['post_id']);
    $el_id   = sanitize_text_field($r['el_id']);
    $data    = xclipse_el_get_data($post_id);
    $parent  = null;
    $index   = null;
    $el      = &xclipse_el_find($data, $el_id, $parent, $index);
    if ($el === null) return xclipse_err('not_found', "Element '{$el_id}' không tìm thấy");
    return xclipse_ok(['post_id' => $post_id, 'element' => $el]);
}

/** Thêm element/widget vào container (hoặc root nếu container_id rỗng) */
function xclipse_el_element_add(WP_REST_Request $r) {
    $post_id      = (int)($r['id'] ?? $r['post_id']);
    $b            = $r->get_json_params();
    $container_id = sanitize_text_field($b['container_id'] ?? '');
    $element      = $b['element'] ?? null;
    $position     = isset($b['position']) ? (int)$b['position'] : -1; // -1 = append

    if (!is_array($element)) return xclipse_err('invalid', 'element phải là object JSON');

    // Auto-generate ID nếu thiếu
    if (empty($element['id'])) $element['id'] = xclipse_el_gen_id();
    // Đảm bảo elements array tồn tại
    if (!isset($element['elements'])) $element['elements'] = [];

    $data = xclipse_el_get_data($post_id);

    if ($container_id === '' || $container_id === 'root') {
        // Thêm vào root
        $target = &$data;
    } else {
        $target = &xclipse_el_find_container_elements($data, $container_id);
        if ($target === null) return xclipse_err('not_found', "Container '{$container_id}' không tìm thấy");
    }

    if ($position < 0 || $position >= count($target)) {
        $target[] = $element;
    } else {
        array_splice($target, $position, 0, [$element]);
    }

    xclipse_el_save_data($post_id, $data);
    return xclipse_ok(['ok' => true, 'post_id' => $post_id, 'element_id' => $element['id']]);
}

/** Cập nhật settings của 1 element theo ID (merge, không replace toàn bộ) */
function xclipse_el_element_update(WP_REST_Request $r) {
    $post_id = (int)($r['id'] ?? $r['post_id']);
    $el_id   = sanitize_text_field($r['el_id']);
    $b       = $r->get_json_params();

    $data   = xclipse_el_get_data($post_id);
    $parent = null;
    $index  = null;
    $el     = &xclipse_el_find($data, $el_id, $parent, $index);
    if ($el === null) return xclipse_err('not_found', "Element '{$el_id}' không tìm thấy");

    // Merge settings nếu có
    if (isset($b['settings']) && is_array($b['settings'])) {
        $el['settings'] = array_merge($el['settings'] ?? [], $b['settings']);
    }
    // Các field khác: widgetType, isInner, elType
    foreach (['widgetType', 'isInner', 'elType'] as $field) {
        if (array_key_exists($field, $b)) $el[$field] = $b[$field];
    }
    // Replace toàn bộ element nếu truyền element_data
    if (isset($b['element_data']) && is_array($b['element_data'])) {
        $b['element_data']['id'] = $el_id; // giữ nguyên ID
        $parent[$index] = $b['element_data'];
    }

    xclipse_el_save_data($post_id, $data);
    return xclipse_ok(['ok' => true, 'post_id' => $post_id, 'element_id' => $el_id]);
}

/** Xoá element theo ID */
function xclipse_el_element_delete(WP_REST_Request $r) {
    $post_id = (int)($r['id'] ?? $r['post_id']);
    $el_id   = sanitize_text_field($r['el_id']);
    $data    = xclipse_el_get_data($post_id);
    if (!xclipse_el_delete_by_id($data, $el_id)) {
        return xclipse_err('not_found', "Element '{$el_id}' không tìm thấy");
    }
    xclipse_el_save_data($post_id, $data);
    return xclipse_ok(['ok' => true, 'post_id' => $post_id, 'deleted_id' => $el_id]);
}

/**
 * Di chuyển element từ vị trí hiện tại sang container khác
 * body: { el_id, target_container_id, position }
 */
function xclipse_el_element_move(WP_REST_Request $r) {
    $post_id          = (int)($r['id'] ?? $r['post_id']);
    $b                = $r->get_json_params();
    $el_id            = sanitize_text_field($b['el_id'] ?? '');
    $target_container = sanitize_text_field($b['target_container_id'] ?? 'root');
    $position         = isset($b['position']) ? (int)$b['position'] : -1;

    if (!$el_id) return xclipse_err('missing', 'el_id bắt buộc');

    $data = xclipse_el_get_data($post_id);

    // 1. Tìm và extract element
    $parent = null;
    $index  = null;
    $el     = xclipse_el_find($data, $el_id, $parent, $index); // value copy
    if ($el === null) return xclipse_err('not_found', "Element '{$el_id}' không tìm thấy");
    $el_copy = $el; // copy trước khi xoá

    // 2. Xoá khỏi vị trí cũ
    xclipse_el_delete_by_id($data, $el_id);

    // 3. Chèn vào target
    if ($target_container === 'root') {
        $target = &$data;
    } else {
        $target = &xclipse_el_find_container_elements($data, $target_container);
        if ($target === null) return xclipse_err('not_found', "Target container '{$target_container}' không tìm thấy");
    }

    if ($position < 0 || $position >= count($target)) {
        $target[] = $el_copy;
    } else {
        array_splice($target, $position, 0, [$el_copy]);
    }

    xclipse_el_save_data($post_id, $data);
    return xclipse_ok(['ok' => true, 'post_id' => $post_id, 'element_id' => $el_id, 'moved_to' => $target_container]);
}

/** Liệt kê Elementor templates (wp_posts type=elementor_library) */
function xclipse_el_template_list(WP_REST_Request $r) {
    $type  = sanitize_key($r['type'] ?? '');   // page, section, container, header, footer...
    $limit = min((int)($r['per_page'] ?? 20), 100);
    $args  = [
        'post_type'      => 'elementor_library',
        'posts_per_page' => $limit,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if ($type) {
        $args['tax_query'] = [[
            'taxonomy' => 'elementor_library_type',
            'field'    => 'slug',
            'terms'    => $type,
        ]];
    }
    $posts = get_posts($args);
    $out   = [];
    foreach ($posts as $p) {
        $terms = get_the_terms($p->ID, 'elementor_library_type');
        $out[] = [
            'id'       => $p->ID,
            'title'    => $p->post_title,
            'slug'     => $p->post_name,
            'type'     => $terms ? $terms[0]->slug : 'unknown',
            'modified' => $p->post_modified,
            'url'      => get_permalink($p->ID),
        ];
    }
    return xclipse_ok(['total' => count($out), 'templates' => $out]);
}

/** Lấy data đầy đủ 1 template (kèm _elementor_data) */
function xclipse_el_template_get(WP_REST_Request $r) {
    $id   = (int)$r['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'elementor_library') {
        return xclipse_err('not_found', 'Template không tồn tại');
    }
    $terms = get_the_terms($id, 'elementor_library_type');
    $flat  = (bool)($r['flat'] ?? false);
    $data  = xclipse_el_get_data($id);
    return xclipse_ok([
        'id'            => $id,
        'title'         => $post->post_title,
        'type'          => $terms ? $terms[0]->slug : 'unknown',
        'modified'      => $post->post_modified,
        'element_count' => count($data),
        'data'          => $flat ? xclipse_el_flatten($data) : $data,
    ]);
}

/** Tạo template mới (Elementor Library) */
function xclipse_el_template_create(WP_REST_Request $r) {
    $b    = $r->get_json_params();
    $name = sanitize_text_field($b['name'] ?? '');
    $type = sanitize_key($b['type'] ?? 'page'); // page|section|container|header|footer|popup
    $data = $b['data'] ?? [];
    if (!$name) return xclipse_err('missing', 'name bắt buộc');
    if (!is_array($data)) return xclipse_err('invalid', 'data phải là array');

    $post_id = wp_insert_post([
        'post_title'  => $name,
        'post_type'   => 'elementor_library',
        'post_status' => 'publish',
    ]);
    if (is_wp_error($post_id)) return $post_id;

    // Gán type taxonomy
    wp_set_object_terms($post_id, $type, 'elementor_library_type');

    // Lưu data
    xclipse_el_save_data($post_id, $data);

    return xclipse_ok(['ok' => true, 'id' => $post_id, 'name' => $name, 'type' => $type]);
}

/** Xoá CSS cache của Elementor (sau khi edit page) */
function xclipse_el_flush_css(WP_REST_Request $r) {
    if (!xclipse_el_active()) return xclipse_err('inactive', 'Elementor không active');
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    // Xoá transient liên quan
    delete_transient('elementor_pro_license_data');
    return xclipse_ok(['ok' => true, 'msg' => 'Elementor CSS cache cleared']);
}

/** Lấy danh sách widget types đang available (cần Elementor active) */
function xclipse_el_widgets_list(WP_REST_Request $r) {
    if (!xclipse_el_active()) return xclipse_err('inactive', 'Elementor không active');
    $widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
    $out = [];
    foreach ($widgets as $key => $widget) {
        $out[] = [
            'type'     => $key,
            'title'    => $widget->get_title(),
            'category' => implode(',', $widget->get_categories()),
            'icon'     => $widget->get_icon(),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['type'], $b['type']));
    return xclipse_ok(['total' => count($out), 'widgets' => $out]);
}

/** Lấy page_settings của 1 page */
function xclipse_el_page_settings_get(WP_REST_Request $r) {
    $id  = (int)$r['id'];
    $raw = get_post_meta($id, '_elementor_page_settings', true);
    $settings = $raw ? (array)json_decode($raw, true) : [];
    return xclipse_ok(['post_id' => $id, 'page_settings' => $settings]);
}

/** Cập nhật page_settings của 1 page */
function xclipse_el_page_settings_set(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $settings = $b['page_settings'] ?? [];
    if (!is_array($settings)) return xclipse_err('invalid', 'page_settings phải là object');
    // Merge với settings hiện tại
    $raw      = get_post_meta($id, '_elementor_page_settings', true);
    $existing = $raw ? (array)json_decode($raw, true) : [];
    $merged   = array_merge($existing, $settings);
    update_post_meta($id, '_elementor_page_settings', wp_slash(wp_json_encode($merged, JSON_UNESCAPED_UNICODE)));
    clean_post_cache($id);
    return xclipse_ok(['ok' => true, 'post_id' => $id]);
}

/** Duplicate element theo ID vào cùng container */
function xclipse_el_element_duplicate(WP_REST_Request $r) {
    $post_id = (int)($r['id'] ?? $r['post_id']);
    $el_id   = sanitize_text_field($r['el_id']);
    $data    = xclipse_el_get_data($post_id);
    $parent  = null;
    $index   = null;
    $el      = xclipse_el_find($data, $el_id, $parent, $index);
    if ($el === null) return xclipse_err('not_found', "Element '{$el_id}' không tìm thấy");

    // Deep clone + assign new IDs
    $clone = xclipse_el_clone_with_new_ids($el);

    // Insert sau element gốc
    array_splice($parent, $index + 1, 0, [$clone]);
    xclipse_el_save_data($post_id, $data);
    return xclipse_ok(['ok' => true, 'new_id' => $clone['id'], 'original_id' => $el_id]);
}

/** Deep clone element và generate IDs mới cho tất cả nested elements */
function xclipse_el_clone_with_new_ids(array $el): array {
    $el['id'] = xclipse_el_gen_id();
    if (!empty($el['elements'])) {
        $el['elements'] = array_map('xclipse_el_clone_with_new_ids', $el['elements']);
    }
    return $el;
}
