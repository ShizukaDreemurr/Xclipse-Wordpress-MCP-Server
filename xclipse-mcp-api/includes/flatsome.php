<?php
/** Flatsome theme settings: header, footer, logo, topbar, CSS */
defined('ABSPATH') || exit;

// FLATSOME HEADER — v3.5.7
// Đọc/ghi Flatsome Header Customizer mods: layout, logo, sticky, transparent,
// topbar, nav style, search, cart, colors, element rows.

/** Danh sách đầy đủ header keys Flatsome */
function xclipse_flatsome_header_keys(): array {
    return [
        // ── Layout ───────────────────────────────────────────────────────────
        'header_layout', 'header_width', 'header_max_width',
        'header_padding', 'header_padding_top', 'header_padding_bottom',
        'header_border',
        // ── Logo ─────────────────────────────────────────────────────────────
        'logo',                'logo_width',         'logo_height',
        'logo_mobile',         'logo_mobile_width',  'logo_mobile_height',
        'logo_sticky',         'logo_sticky_width',
        // ── Colors / Background ──────────────────────────────────────────────
        'header_bg',           'header_bg_color',
        'header_text_color',   'header_link_color',
        'header_border_color',
        // ── Sticky Header ─────────────────────────────────────────────────────
        'header_sticky',       'header_sticky_style',
        'header_sticky_bg',    'header_sticky_text_color',
        'header_sticky_height','header_sticky_logo_height',
        'header_sticky_nav',
        // ── Transparent Header ────────────────────────────────────────────────
        'header_transparent',  'header_transparent_color',
        'header_transparent_text_color',
        // ── Main Row (Row 2) ──────────────────────────────────────────────────
        'header_main_height',  'header_main_bg',
        // Header Builder element slots (Flatsome header rows)
        // Row 1 = top bar, Row 2 = main, Row 3 = bottom
        'header_row_1_left',   'header_row_1_center',   'header_row_1_right',
        'header_row_2_left',   'header_row_2_center',   'header_row_2_right',
        'header_row_3_left',   'header_row_3_center',   'header_row_3_right',
        // Sticky row elements
        'header_sticky_left',  'header_sticky_center',  'header_sticky_right',
        // ── Navigation ────────────────────────────────────────────────────────
        'header_nav_size',     'header_nav_weight',
        'header_nav_style',    'header_nav_bg',
        'nav_uppercase',       'nav_font',
        'header_nav_link_color', 'header_nav_hover_color',
        // ── Search ────────────────────────────────────────────────────────────
        'header_search',       'header_search_style',
        // ── Cart (WooCommerce) ─────────────────────────────────────────────────
        'header_cart',         'header_cart_style',
        // ── General ───────────────────────────────────────────────────────────
        'header_social',       'header_custom_text',
    ];
}

function xclipse_flatsome_header_get() {
    $mods = xclipse_get_mods_by_keys(xclipse_flatsome_header_keys());

    // Logo attachment info (URL + dimensions)
    $logo_url = get_theme_mod('logo', '');
    $logo_info = null;
    if ($logo_url) {
        $att_id = attachment_url_to_postid($logo_url);
        if ($att_id) {
            $meta = wp_get_attachment_metadata($att_id);
            $logo_info = ['id' => $att_id, 'width' => $meta['width'] ?? null, 'height' => $meta['height'] ?? null];
        }
    }

    return xclipse_ok([
        'mods'      => $mods,
        'logo_info' => $logo_info,
        'hint'      => 'Dùng PUT /flatsome/header để update bất kỳ key nào, PUT /flatsome/header/logo để set logo, PUT /flatsome/header/topbar cho top bar.',
    ]);
}

/** PUT /flatsome/header — Ghi Flatsome header mods. Body: bất kỳ header key nào cần update */
function xclipse_flatsome_header_set(WP_REST_Request $r) {
    $updated = xclipse_apply_theme_mods($r->get_json_params());
    if (empty($updated)) return xclipse_err('no_changes', 'Không có field nào được cập nhật', 400);
    return xclipse_ok(['updated' => $updated, 'count' => count($updated)]);
}

/** PUT /flatsome/header/logo — Set logo/sticky/mobile. Body: logo, logo_width, logo_height, logo_sticky, logo_mobile */
function xclipse_flatsome_header_logo_set(WP_REST_Request $r) {
    $body    = $r->get_json_params();
    $updated = [];

    $logo_fields = ['logo', 'logo_width', 'logo_height',
                    'logo_sticky', 'logo_sticky_width',
                    'logo_mobile', 'logo_mobile_width', 'logo_mobile_height'];

    foreach ($logo_fields as $field) {
        if (isset($body[$field])) {
            $val = $body[$field];
            // Nếu là số (attachment ID), resolve thành URL
            if ($field === 'logo' || $field === 'logo_sticky' || $field === 'logo_mobile') {
                if (is_numeric($val)) {
                    $url = wp_get_attachment_url((int)$val);
                    if ($url) $val = $url;
                }
            }
            set_theme_mod($field, $val);
            $updated[$field] = $val;
        }
    }

    if (empty($updated)) {
        return xclipse_err('no_fields', 'Truyền ít nhất một trong: logo, logo_width, logo_sticky, logo_mobile...', 400);
    }

    return xclipse_ok(['updated' => $updated]);
}

function xclipse_flatsome_header_topbar_get() {
    $keys = [
        'header_top', 'header_top_layout',
        'header_top_height', 'header_top_padding',
        'header_top_bg', 'header_top_bg_color',
        'header_top_text_color', 'header_top_border',
        'header_top_left', 'header_top_center', 'header_top_right',
        'header_top_content', 'header_top_content_right',
        'topbar_text', 'topbar_left', 'topbar_right',
    ];
    $mods = [];
    foreach ($keys as $key) {
        $val = get_theme_mod($key, null);
        if ($val !== null && $val !== false) {
            $mods[$key] = $val;
        }
    }
    return xclipse_ok(['mods' => $mods]);
}

function xclipse_flatsome_header_topbar_set(WP_REST_Request $r) {
    $updated = xclipse_apply_theme_mods($r->get_json_params());
    if (empty($updated)) return xclipse_err('no_changes', 'Không có field nào được cập nhật', 400);
    return xclipse_ok(['updated' => $updated]);
}

// FLATSOME THEME SETTINGS — v3.5.5
// Đọc/ghi Flatsome Customizer mods + Advanced Options + Custom CSS

function xclipse_flatsome_get() {
    // 1. Customizer mods (theme_mods_flatsome)
    $mods = get_theme_mods();

    // 2. Flatsome Advanced Options (stored under 'flatsome_option' or individual keys)
    $advanced = get_option('flatsome_option', []);

    // 3. Custom CSS riêng (wp_get_custom_css trả về CSS từ Customizer Additional CSS)
    $wp_custom_css = wp_get_custom_css();

    // 4. Flatsome-specific custom CSS fields (stored in theme_mods)
    $flatsome_css_all     = get_theme_mod('custom_css', '');
    $flatsome_css_tablet  = get_theme_mod('custom_css_tablet', '');
    $flatsome_css_mobile  = get_theme_mod('custom_css_mobile', '');

    return xclipse_ok([
        'theme'         => wp_get_theme()->get('Name'),
        'version'       => wp_get_theme()->get('Version'),
        'mods'          => $mods,
        'advanced'      => $advanced,
        'custom_css'    => [
            'all_screens' => $flatsome_css_all,
            'tablets'     => $flatsome_css_tablet,
            'mobile'      => $flatsome_css_mobile,
            'wp_additional' => $wp_custom_css,
        ],
    ]);
}

/** PUT /flatsome — Cập nhật Flatsome settings. Body: { "mods": {...}, "advanced": {...} } */
function xclipse_flatsome_update(WP_REST_Request $r) {
    $body     = $r->get_json_params();
    $mods     = $body['mods']     ?? [];
    $advanced = $body['advanced'] ?? [];
    $updated  = [];
    $errors   = [];

    // Update customizer mods
    if (!empty($mods) && is_array($mods)) {
        $updated['mods'] = xclipse_apply_theme_mods($mods);
    }

    // Update advanced options
    if (!empty($advanced) && is_array($advanced)) {
        $current = get_option('flatsome_option', []);
        foreach ($advanced as $key => $val) {
            $key = sanitize_key($key);
            if (!$key) { $errors[] = "invalid_key"; continue; }
            $current[$key] = $val;
            $updated['advanced'][$key] = $val;
        }
        update_option('flatsome_option', $current);
    }

    if (empty($updated)) {
        return xclipse_err('no_changes', 'Không có field nào được cập nhật', 400);
    }

    return xclipse_ok(['updated' => $updated, 'errors' => $errors]);
}

function xclipse_flatsome_css_get() {
    return xclipse_ok([
        'all_screens' => get_theme_mod('custom_css', ''),
        'tablets'     => get_theme_mod('custom_css_tablet', ''),
        'mobile'      => get_theme_mod('custom_css_mobile', ''),
    ]);
}

function xclipse_flatsome_footer_get() {
    // Tất cả keys Flatsome footer trong Customizer
    $footer_keys = [
        // Layout
        'footer_layout', 'footer_width', 'footer_widgets',
        'footer_columns', 'footer_responsive',
        // Colors / Background
        'footer_background', 'footer_text_color', 'footer_border',
        'footer_bg_color', 'footer_link_color', 'footer_heading_color',
        // Bottom bar
        'footer_bar', 'footer_bar_width', 'footer_bar_alignment',
        'footer_bar_background', 'footer_bar_text_color',
        'footer_copy', 'footer_bar_sticky',
        // Widget area spacing
        'footer_widget_padding', 'footer_padding_top', 'footer_padding_bottom',
        // Social / scripts
        'footer_show_social', 'footer_scripts',
        // Footer Builder (nếu có Flatsome Studio/UX Pro)
        'footer_builder', 'footer_builder_enabled',
    ];

    $mods = xclipse_get_mods_by_keys($footer_keys);

    // Widget areas Flatsome footer (footer-1, footer-2, footer-3, footer-4, footer-bar)
    global $wp_registered_sidebars;
    $footer_sidebars = [];
    $sidebars_widgets = wp_get_sidebars_widgets();
    foreach ($wp_registered_sidebars as $id => $sidebar) {
        if (strpos($id, 'footer') !== false) {
            $widget_ids = $sidebars_widgets[$id] ?? [];
            $footer_sidebars[] = [
                'id'      => $id,
                'name'    => $sidebar['name'],
                'widgets' => xclipse_format_widgets_batch($widget_ids),
            ];
        }
    }

    return xclipse_ok([
        'mods'            => $mods,
        'footer_sidebars' => $footer_sidebars,
        'all_mods_hint'   => 'Dùng GET /flatsome để xem toàn bộ theme_mods',
    ]);
}

/** PUT /flatsome/footer — Ghi Flatsome footer mods. Body: footer_copy, footer_bg_color, footer_text_color... */
function xclipse_flatsome_footer_set(WP_REST_Request $r) {
    $updated = xclipse_apply_theme_mods($r->get_json_params());
    if (empty($updated)) return xclipse_err('no_changes', 'Không có field nào được cập nhật', 400);
    return xclipse_ok(['updated' => $updated]);
}

function xclipse_flatsome_css_set(WP_REST_Request $r) {
    $body    = $r->get_json_params();
    $updated = [];

    if (isset($body['all_screens'])) {
        set_theme_mod('custom_css', $body['all_screens']);
        $updated['all_screens'] = $body['all_screens'];
    }
    if (isset($body['tablets'])) {
        set_theme_mod('custom_css_tablet', $body['tablets']);
        $updated['tablets'] = $body['tablets'];
    }
    if (isset($body['mobile'])) {
        set_theme_mod('custom_css_mobile', $body['mobile']);
        $updated['mobile'] = $body['mobile'];
    }

    if (empty($updated)) {
        return xclipse_err('no_fields', 'Truyền ít nhất một trong: all_screens, tablets, mobile', 400);
    }

    return xclipse_ok(['updated' => $updated]);
}