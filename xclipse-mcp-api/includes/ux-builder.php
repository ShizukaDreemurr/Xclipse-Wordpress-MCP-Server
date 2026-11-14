<?php
/** Flatsome UX Builder — inline block editor for pages/posts */
defined('ABSPATH') || exit;

// FLATSOME UX BUILDER

/**
 * Parse Flatsome shortcode content thành mảng blocks có cấu trúc.
 * Mỗi block: { tag, atts, inner, raw, children[] }
 * Hỗ trợ nested shortcodes vô hạn cấp.
 */
function xclipse_ux_parse(string $content): array {
    $blocks = [];
    // Regex khớp cả [tag atts]...[/tag] lẫn [tag atts /] (self-closing)
    $pattern = '/\[([a-z_]+)((?:\s[^\]]*)?)\]((?:[^\[]*(?:\[(?!\/)(?:[^\[\]]|\[(?:[^\[\]]|\[[^\[\]]*\])*\])*\]|[^\[]*)*)?)\[\/\1\]|\[([a-z_]+)((?:\s[^\]]*)?)\s*\/?\]/s';
    preg_match_all($pattern, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $i => $match) {
        $is_self = !empty($match[4][0]);
        $tag     = $is_self ? $match[4][0] : $match[1][0];
        $atts_raw= $is_self ? trim($match[5][0]) : trim($match[2][0]);
        $inner   = $is_self ? '' : $match[3][0];
        // Parse attributes: key="value" hoặc key='value' hoặc key=value
        $atts = [];
        if ($atts_raw) {
            preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', $atts_raw, $am, PREG_SET_ORDER);
            foreach ($am as $a) $atts[$a[1]] = $a[2] !== '' ? $a[2] : ($a[3] !== '' ? $a[3] : $a[4]);
        }
        $children = (!$is_self && trim($inner) !== '') ? xclipse_ux_parse($inner) : [];
        $blocks[] = [
            'idx'      => $i,
            'tag'      => $tag,
            'atts'     => $atts,
            'inner'    => $is_self ? null : ($children ? null : trim($inner)),
            'children' => $children,
            'raw'      => $match[0][0],
        ];
    }
    return $blocks;
}

/**
 * Rebuild Flatsome shortcode string từ mảng blocks (ngược lại parse).
 */
function xclipse_ux_build(array $blocks): string {
    $out = '';
    foreach ($blocks as $b) {
        $tag  = $b['tag'];
        $atts = '';
        foreach (($b['atts'] ?? []) as $k => $v) {
            $atts .= " {$k}=\"" . esc_attr($v) . '"';
        }
        if (!empty($b['children'])) {
            $out .= "[{$tag}{$atts}]" . xclipse_ux_build($b['children']) . "[/{$tag}]";
        } elseif (isset($b['inner']) && $b['inner'] !== null && $b['inner'] !== '') {
            $out .= "[{$tag}{$atts}]" . $b['inner'] . "[/{$tag}]";
        } else {
            // Self-closing nếu không có nội dung
            $has_close = in_array($tag, ['ux_banner','section','row','col','ux_slider','ux_products',
                'ux_banner_grid','ux_video','ux_hotspot','ux_countdown','ux_testimonials',
                'ux_tab_section','ux_accordion','ux_image_box','divider','gap','title','subtitle',
                'text_box','button','recent_products','featured_products','sale_products']);
            $out .= $has_close ? "[{$tag}{$atts}][/{$tag}]" : "[{$tag}{$atts}]";
        }
    }
    return $out;
}

/** Lấy post hỗ trợ UX Builder (page, post, product đều được) */
function xclipse_ux_post(int $id) {
    $p = get_post($id);
    if (!$p) return xclipse_err('not_found', "Không tìm thấy post ID {$id}");
    return $p;
}

function xclipse_ux_get(WP_REST_Request $r) {
    $p = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $blocks   = xclipse_ux_parse($p->post_content);
    $fs_meta  = get_post_meta($p->ID, '_flatsome_ux', true); // sidebar/header overrides
    return xclipse_ok([
        'id'       => $p->ID,
        'title'    => $p->post_title,
        'slug'     => $p->post_name,
        'status'   => $p->post_status,
        'url'      => get_permalink($p->ID),
        'raw'      => $p->post_content,
        'blocks'   => $blocks,
        'block_count' => count($blocks),
        'fs_meta'  => $fs_meta ?: null,
    ]);
}

function xclipse_ux_update(WP_REST_Request $r) {
    $p = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $b = $r->get_json_params();
    if (!isset($b['content'])) return xclipse_err('missing', 'Thiếu field content');
    $d = ['ID' => $p->ID, 'post_content' => $b['content']];
    if (isset($b['title']))  $d['post_title']  = sanitize_text_field($b['title']);
    if (isset($b['status'])) $d['post_status'] = sanitize_key($b['status']);
    wp_update_post($d);
    // Cập nhật Flatsome UX meta nếu có
    if (isset($b['fs_meta'])) update_post_meta($p->ID, '_flatsome_ux', $b['fs_meta']);
    return xclipse_ok(['ok' => true, 'id' => $p->ID, 'blocks' => count(xclipse_ux_parse($b['content']))]);
}

function xclipse_ux_blocks(WP_REST_Request $r) {
    $p = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $blocks = xclipse_ux_parse($p->post_content);
    $depth  = (int)($r['depth'] ?? 0);
    if ($depth === 1) {
        // Chỉ trả top-level, ẩn children để tiết kiệm token
        $blocks = array_map(fn($b) => array_merge($b, [
            'children' => count($b['children']) . ' children',
        ]), $blocks);
    }
    return xclipse_ok(['id' => $p->ID, 'total' => count($blocks), 'blocks' => $blocks]);
}

function xclipse_ux_block_patch(WP_REST_Request $r) {
    $p   = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $idx = (int)$r['idx'];
    $b   = $r->get_json_params();
    $blocks = xclipse_ux_parse($p->post_content);
    if (!isset($blocks[$idx])) return xclipse_err('bad_idx', "Block index {$idx} không tồn tại (total: " . count($blocks) . ")");
    // Merge atts
    if (isset($b['atts']) && is_array($b['atts'])) {
        $blocks[$idx]['atts'] = array_merge($blocks[$idx]['atts'], array_map('sanitize_text_field', $b['atts']));
    }
    // Thay inner text nếu có
    if (isset($b['inner'])) {
        $blocks[$idx]['inner'] = wp_kses_post($b['inner']);
        $blocks[$idx]['children'] = [];
    }
    // Thay tag nếu cần
    if (isset($b['tag'])) $blocks[$idx]['tag'] = sanitize_key($b['tag']);
    // Rebuild và lưu
    $new_content = xclipse_ux_build($blocks);
    wp_update_post(['ID' => $p->ID, 'post_content' => $new_content]);
    return xclipse_ok(['ok' => true, 'id' => $p->ID, 'idx' => $idx, 'block' => $blocks[$idx]]);
}

function xclipse_ux_block_add(WP_REST_Request $r) {
    $p = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $b   = $r->get_json_params();
    $tag = sanitize_key($b['tag'] ?? 'section');
    if (!$tag) return xclipse_err('missing', 'Thiếu tag');
    $new_block = [
        'tag'      => $tag,
        'atts'     => isset($b['atts']) ? array_map('sanitize_text_field', (array)$b['atts']) : [],
        'inner'    => isset($b['inner']) ? wp_kses_post($b['inner']) : null,
        'children' => [],
    ];
    $blocks   = xclipse_ux_parse($p->post_content);
    $position = isset($b['position']) ? (int)$b['position'] : null;
    if ($position === null) {
        $blocks[] = $new_block;
        $new_idx  = count($blocks) - 1;
    } else {
        array_splice($blocks, $position, 0, [$new_block]);
        $new_idx = $position;
    }
    // Re-index
    foreach ($blocks as $i => &$bl) $bl['idx'] = $i;
    unset($bl);
    $new_content = xclipse_ux_build($blocks);
    wp_update_post(['ID' => $p->ID, 'post_content' => $new_content]);
    return xclipse_ok(['ok' => true, 'id' => $p->ID, 'new_idx' => $new_idx, 'total' => count($blocks)]);
}

function xclipse_ux_block_delete(WP_REST_Request $r) {
    $p   = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $idx = (int)$r['idx'];
    $blocks = xclipse_ux_parse($p->post_content);
    if (!isset($blocks[$idx])) return xclipse_err('bad_idx', "Block index {$idx} không tồn tại");
    $deleted_tag = $blocks[$idx]['tag'];
    array_splice($blocks, $idx, 1);
    $new_content = xclipse_ux_build($blocks);
    wp_update_post(['ID' => $p->ID, 'post_content' => $new_content]);
    return xclipse_ok(['ok' => true, 'deleted_tag' => $deleted_tag, 'remaining' => count($blocks)]);
}

function xclipse_ux_reorder(WP_REST_Request $r) {
    $p = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $b      = $r->get_json_params();
    $order  = $b['order'] ?? [];
    $blocks = xclipse_ux_parse($p->post_content);
    if (count($order) !== count($blocks)) return xclipse_err('bad_order', 'order phải chứa đủ ' . count($blocks) . ' phần tử');
    $new_blocks = [];
    foreach ($order as $i) {
        if (!isset($blocks[(int)$i])) return xclipse_err('bad_idx', "Index {$i} không tồn tại");
        $new_blocks[] = $blocks[(int)$i];
    }
    $new_content = xclipse_ux_build($new_blocks);
    wp_update_post(['ID' => $p->ID, 'post_content' => $new_content]);
    return xclipse_ok(['ok' => true, 'order' => $order]);
}

function xclipse_ux_block_clone(WP_REST_Request $r) {
    $p   = xclipse_ux_post((int)$r['id']);
    if (is_wp_error($p)) return $p;
    $idx = (int)$r['idx'];
    $blocks = xclipse_ux_parse($p->post_content);
    if (!isset($blocks[$idx])) return xclipse_err('bad_idx', "Block index {$idx} không tồn tại");
    $clone = $blocks[$idx];
    array_splice($blocks, $idx + 1, 0, [$clone]);
    $new_content = xclipse_ux_build($blocks);
    wp_update_post(['ID' => $p->ID, 'post_content' => $new_content]);
    return xclipse_ok(['ok' => true, 'cloned_idx' => $idx, 'new_idx' => $idx + 1, 'total' => count($blocks)]);
}

function xclipse_ux_search(WP_REST_Request $r) {
    $q  = sanitize_text_field($r['q'] ?? '');
    if (!$q) return xclipse_err('missing', 'Thiếu query q');
    $pt = sanitize_key($r['post_type'] ?? 'page');
    $field = sanitize_key($r['field'] ?? 'any'); // inner | att | tag | any
    global $wpdb;
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_content LIKE %s LIMIT %d",
        $pt === 'any' ? 'page' : $pt,
        '%' . $wpdb->esc_like($q) . '%',
        (int)($r['per_page'] ?? 20)
    ), ARRAY_A);
    $results = [];
    foreach ($posts as $post) {
        $content = get_post_field('post_content', (int)$post['ID']);
        $blocks  = xclipse_ux_parse($content);
        $matched = [];
        foreach ($blocks as $bl) {
            $hit = false;
            if (in_array($field, ['any','inner']) && str_contains((string)($bl['inner'] ?? ''), $q)) $hit = true;
            if (in_array($field, ['any','att']))   { foreach ($bl['atts'] as $v) if (str_contains($v, $q)) { $hit = true; break; } }
            if (in_array($field, ['any','tag'])  && str_contains($bl['tag'], $q)) $hit = true;
            if ($hit) $matched[] = ['idx' => $bl['idx'], 'tag' => $bl['tag'], 'atts' => $bl['atts']];
        }
        if ($matched) $results[] = ['id' => (int)$post['ID'], 'title' => $post['post_title'], 'slug' => $post['post_name'], 'matches' => $matched];
    }
    return xclipse_ok(['q' => $q, 'total_pages' => count($results), 'results' => $results]);
}