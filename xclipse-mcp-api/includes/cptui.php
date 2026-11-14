<?php
defined('ABSPATH') || exit;

// HELPERS

/** Lấy config CPT UI từ option */
function xclipse_cptui_types(): array {
    return get_option('cptui_post_types', []);
}

function xclipse_cptui_taxonomies_raw(): array {
    return get_option('cptui_taxonomies', []);
}

/** Format 1 CPT UI post type thành output */
function xclipse_cptui_type_format(string $slug, array $cfg): array {
    // Tìm taxonomies gắn với CPT này
    $all_taxes = xclipse_cptui_taxonomies_raw();
    $linked_taxes = [];
    foreach ($all_taxes as $tax_slug => $tax_cfg) {
        if (!empty($tax_cfg['object_types']) && in_array($slug, (array)$tax_cfg['object_types'])) {
            $linked_taxes[] = $tax_slug;
        }
    }
    // Thêm built-in taxonomies đăng ký với CPT
    $wp_taxes = get_object_taxonomies($slug, 'names');
    $linked_taxes = array_unique(array_merge($linked_taxes, $wp_taxes));

    return [
        'slug'       => $slug,
        'label'      => $cfg['labels']['name'] ?? $slug,
        'singular'   => $cfg['labels']['singular_name'] ?? $slug,
        'menu_icon'  => $cfg['menu_icon'] ?? null,
        'public'     => (bool)($cfg['public'] ?? true),
        'has_archive'=> (bool)($cfg['has_archive'] ?? false),
        'supports'   => $cfg['supports'] ?? [],
        'taxonomies' => array_values($linked_taxes),
        'count'      => [
            'publish' => (int)wp_count_posts($slug)->publish,
            'draft'   => (int)wp_count_posts($slug)->draft,
            'total'   => array_sum((array)wp_count_posts($slug)),
        ],
    ];
}

/** Format 1 taxonomy CPT UI thành output */
function xclipse_cptui_tax_format(string $slug, array $cfg): array {
    $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name']);
    return [
        'slug'         => $slug,
        'label'        => $cfg['labels']['name'] ?? $slug,
        'singular'     => $cfg['labels']['singular_name'] ?? $slug,
        'hierarchical' => (bool)($cfg['hierarchical'] ?? false),
        'public'       => (bool)($cfg['public'] ?? true),
        'object_types' => (array)($cfg['object_types'] ?? []),
        'terms_count'  => is_array($terms) ? count($terms) : 0,
        'terms'        => is_array($terms) ? array_map(fn($t) => [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'count'  => $t->count,
            'parent' => $t->parent,
        ], $terms) : [],
    ];
}

/** Format post của bất kỳ CPT nào */
function xclipse_cptui_post_format($p, bool $full = false): array {
    $pid = (int)$p->ID;
    $out = [
        'id'       => $pid,
        'title'    => $p->post_title,
        'slug'     => $p->post_name,
        'status'   => $p->post_status,
        'type'     => $p->post_type,
        'date'     => $p->post_date,
        'modified' => $p->post_modified,
        'url'      => get_permalink($pid),
        'thumb'    => get_the_post_thumbnail_url($pid, 'medium') ?: null,
    ];
    if ($full) {
        $out['content'] = $p->post_content;
        $out['excerpt'] = $p->post_excerpt;
        $out['author']  = xclipse_author_info((int)$p->post_author);
        $out['seo']     = xclipse_seo($pid);
        // ACF fields nếu có
        if (function_exists('get_fields')) {
            $acf = get_fields($pid);
            $out['acf'] = is_array($acf) ? $acf : [];
        }
        // Tất cả taxonomy terms
        $taxes = get_object_taxonomies($p->post_type, 'names');
        foreach ($taxes as $tax) {
            $terms = wp_get_post_terms($pid, $tax, ['fields' => 'all']);
            if (!is_wp_error($terms)) {
                $out['terms'][$tax] = array_map(fn($t) => [
                    'id'   => $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ], $terms);
            }
        }
        // Post meta (non-ACF, non-underscore)
        $meta_raw = get_post_meta($pid);
        $meta_out = [];
        foreach ($meta_raw as $key => $vals) {
            if (str_starts_with($key, '_')) continue;  // skip private
            if (function_exists('get_fields') && str_starts_with($key, 'field_')) continue;
            $meta_out[$key] = count($vals) === 1 ? $vals[0] : $vals;
        }
        $out['meta'] = $meta_out;
    }
    return xclipse_compact($out);
}

// CPT TYPES

function xclipse_cptui_types_list(WP_REST_Request $r) {
    $cptui_types = xclipse_cptui_types();
    $include_builtin = filter_var($r['include_builtin'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $out = [];
    foreach ($cptui_types as $slug => $cfg) {
        $out[] = xclipse_cptui_type_format($slug, $cfg);
    }

    // Tùy chọn: kèm built-in CPTs của WordPress và plugins khác
    if ($include_builtin) {
        $all_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($all_types as $slug => $obj) {
            // Bỏ qua nếu đã có trong CPT UI
            if (isset($cptui_types[$slug])) continue;
            $out[] = [
                'slug'       => $slug,
                'label'      => $obj->label,
                'singular'   => $obj->labels->singular_name ?? $slug,
                'source'     => 'plugin/theme',
                'menu_icon'  => $obj->menu_icon ?? null,
                'taxonomies' => get_object_taxonomies($slug, 'names'),
                'count'      => [
                    'publish' => (int)wp_count_posts($slug)->publish,
                    'total'   => array_sum((array)wp_count_posts($slug)),
                ],
            ];
        }
    }

    return xclipse_ok(['total' => count($out), 'types' => $out]);
}

function xclipse_cptui_type_get(WP_REST_Request $r) {
    $slug = sanitize_key($r['slug']);
    $cptui_types = xclipse_cptui_types();

    if (isset($cptui_types[$slug])) {
        return xclipse_ok(xclipse_cptui_type_format($slug, $cptui_types[$slug]));
    }

    // Fallback: CPT đăng ký từ nơi khác
    if (!post_type_exists($slug)) return xclipse_err('not_found', "CPT '{$slug}' không tồn tại");
    $obj = get_post_type_object($slug);
    return xclipse_ok([
        'slug'       => $slug,
        'label'      => $obj->label,
        'singular'   => $obj->labels->singular_name ?? $slug,
        'source'     => 'plugin/theme',
        'taxonomies' => get_object_taxonomies($slug, 'names'),
        'count'      => [
            'publish' => (int)wp_count_posts($slug)->publish,
            'total'   => array_sum((array)wp_count_posts($slug)),
        ],
    ]);
}

// ── Posts của bất kỳ CPT ─────────────────────────────────────────────────────

function xclipse_cptui_posts_list(WP_REST_Request $r) {
    $slug = sanitize_key($r['slug']);
    if (!post_type_exists($slug)) return xclipse_err('not_found', "CPT '{$slug}' không tồn tại");

    $args = [
        'post_type'      => $slug,
        'post_status'    => $r['status'] ?? 'publish',
        'posts_per_page' => min((int)($r['per_page'] ?? 20), 100),
        'paged'          => (int)($r['page'] ?? 1),
        's'              => sanitize_text_field($r['search'] ?? ''),
        'orderby'        => $r['orderby'] ?? 'date',
        'order'          => strtoupper($r['order'] ?? 'DESC'),
    ];
    // Tax query
    if (!empty($r['tax']) && !empty($r['term'])) {
        $args['tax_query'] = [[
            'taxonomy' => sanitize_key($r['tax']),
            'field'    => 'slug',
            'terms'    => array_map('sanitize_title', explode(',', $r['term'])),
        ]];
    }
    if (!empty($r['term_id'])) {
        $taxes = get_object_taxonomies($slug, 'names');
        if (!empty($taxes)) {
            $args['tax_query'] = [[
                'taxonomy' => $taxes[0],
                'field'    => 'term_id',
                'terms'    => array_map('absint', explode(',', $r['term_id'])),
            ]];
        }
    }

    $q = new WP_Query($args);
    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'type'  => $slug,
        'posts' => array_map(fn($p) => xclipse_cptui_post_format($p, false), $q->posts),
    ]);
}

function xclipse_cptui_post_create(WP_REST_Request $r) {
    $slug = sanitize_key($r['slug']);
    if (!post_type_exists($slug)) return xclipse_err('not_found', "CPT '{$slug}' không tồn tại");

    $b  = $r->get_json_params();
    $id = wp_insert_post([
        'post_type'    => $slug,
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
        'post_author'  => (int)($b['author_id'] ?? get_current_user_id()),
    ], true);
    if (is_wp_error($id)) return xclipse_err('insert_error', $id->get_error_message());

    // ACF
    if (!empty($b['acf']) && is_array($b['acf']) && function_exists('update_field')) {
        foreach ($b['acf'] as $k => $v) update_field(sanitize_key($k), $v, $id);
    }
    // Post meta
    if (!empty($b['meta']) && is_array($b['meta'])) {
        foreach ($b['meta'] as $k => $v) update_post_meta($id, sanitize_key($k), $v);
    }
    // Terms
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (!empty($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_cptui_post_get(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy post');
    return xclipse_ok(xclipse_cptui_post_format($p, true));
}

function xclipse_cptui_post_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy post');

    $b = $r->get_json_params();
    $d = ['ID' => $id];
    if (isset($b['title']))   $d['post_title']   = sanitize_text_field($b['title']);
    if (isset($b['content'])) $d['post_content'] = wp_kses_post($b['content']);
    if (isset($b['excerpt'])) $d['post_excerpt'] = sanitize_text_field($b['excerpt']);
    if (isset($b['status']))  $d['post_status']  = sanitize_text_field($b['status']);
    if (isset($b['slug']))    $d['post_name']    = sanitize_title($b['slug']);
    if (count($d) > 1) {
        $res = wp_update_post($d, true);
        if (is_wp_error($res)) return xclipse_err('update_error', $res->get_error_message());
    }

    if (!empty($b['acf']) && is_array($b['acf']) && function_exists('update_field')) {
        foreach ($b['acf'] as $k => $v) update_field(sanitize_key($k), $v, $id);
    }
    if (!empty($b['meta']) && is_array($b['meta'])) {
        foreach ($b['meta'] as $k => $v) update_post_meta($id, sanitize_key($k), $v);
    }
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (isset($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_cptui_post_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không tìm thấy post');
    $force = filter_var($r['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($force) {
        wp_delete_post($id, true);
        return xclipse_ok(['ok' => true, 'id' => $id, 'action' => 'deleted']);
    }
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => 'trashed']);
}

// CPT UI TAXONOMIES

function xclipse_cptui_taxonomies_list(WP_REST_Request $r) {
    $cptui_taxes = xclipse_cptui_taxonomies_raw();
    $include_builtin = filter_var($r['include_builtin'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $out = [];
    foreach ($cptui_taxes as $slug => $cfg) {
        $out[] = xclipse_cptui_tax_format($slug, $cfg);
    }

    if ($include_builtin) {
        $all_taxes = get_taxonomies(['public' => true, '_builtin' => false], 'objects');
        foreach ($all_taxes as $slug => $obj) {
            if (isset($cptui_taxes[$slug])) continue;
            $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => false]);
            $out[] = [
                'slug'         => $slug,
                'label'        => $obj->label,
                'singular'     => $obj->labels->singular_name ?? $slug,
                'hierarchical' => $obj->hierarchical,
                'source'       => 'plugin/theme',
                'object_types' => $obj->object_type ?? [],
                'terms_count'  => is_array($terms) ? count($terms) : 0,
            ];
        }
    }

    return xclipse_ok(['total' => count($out), 'taxonomies' => $out]);
}

function xclipse_cptui_taxonomy_get(WP_REST_Request $r) {
    $slug = sanitize_key($r['slug']);
    $cptui_taxes = xclipse_cptui_taxonomies_raw();

    if (isset($cptui_taxes[$slug])) {
        return xclipse_ok(xclipse_cptui_tax_format($slug, $cptui_taxes[$slug]));
    }

    if (!taxonomy_exists($slug)) return xclipse_err('not_found', "Taxonomy '{$slug}' không tồn tại");
    $obj   = get_taxonomy($slug);
    $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name']);
    return xclipse_ok([
        'slug'         => $slug,
        'label'        => $obj->label,
        'hierarchical' => $obj->hierarchical,
        'source'       => 'plugin/theme',
        'object_types' => $obj->object_type ?? [],
        'terms_count'  => is_array($terms) ? count($terms) : 0,
        'terms'        => is_array($terms) ? array_map(fn($t) => [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'count'  => $t->count,
            'parent' => $t->parent,
        ], $terms) : [],
    ]);
}

function xclipse_cptui_term_create(WP_REST_Request $r) {
    $tax = sanitize_key($r['slug']);
    if (!taxonomy_exists($tax)) return xclipse_err('not_found', "Taxonomy '{$tax}' không tồn tại");

    $b    = $r->get_json_params();
    $name = sanitize_text_field($b['name'] ?? '');
    if (!$name) return xclipse_err('missing', 'Thiếu name');

    $args = [];
    if (!empty($b['slug']))        $args['slug']        = sanitize_title($b['slug']);
    if (!empty($b['description'])) $args['description'] = sanitize_textarea_field($b['description']);
    if (!empty($b['parent']))      $args['parent']      = (int)$b['parent'];

    $result = wp_insert_term($name, $tax, $args);
    if (is_wp_error($result)) return xclipse_err('term_error', $result->get_error_message());

    return xclipse_ok([
        'term_id'  => $result['term_id'],
        'name'     => $name,
        'taxonomy' => $tax,
    ]);
}

function xclipse_cptui_term_update(WP_REST_Request $r) {
    $tax     = sanitize_key($r['slug']);
    $term_id = (int)$r['term_id'];

    if (!taxonomy_exists($tax)) return xclipse_err('not_found', "Taxonomy '{$tax}' không tồn tại");
    $term = get_term($term_id, $tax);
    if (!$term || is_wp_error($term)) return xclipse_err('not_found', "Term {$term_id} không tồn tại");

    $b    = $r->get_json_params();
    $args = [];
    if (isset($b['name']))        $args['name']        = sanitize_text_field($b['name']);
    if (isset($b['slug']))        $args['slug']        = sanitize_title($b['slug']);
    if (isset($b['description'])) $args['description'] = sanitize_textarea_field($b['description']);
    if (isset($b['parent']))      $args['parent']      = (int)$b['parent'];

    $result = wp_update_term($term_id, $tax, $args);
    if (is_wp_error($result)) return xclipse_err('term_error', $result->get_error_message());

    return xclipse_ok(['ok' => true, 'term_id' => $term_id]);
}

function xclipse_cptui_term_delete(WP_REST_Request $r) {
    $tax     = sanitize_key($r['slug']);
    $term_id = (int)$r['term_id'];

    if (!taxonomy_exists($tax)) return xclipse_err('not_found', "Taxonomy '{$tax}' không tồn tại");
    $result = wp_delete_term($term_id, $tax);
    if (is_wp_error($result)) return xclipse_err('term_error', $result->get_error_message());
    if (!$result) return xclipse_err('not_found', "Term {$term_id} không tồn tại");

    return xclipse_ok(['ok' => true, 'term_id' => $term_id, 'taxonomy' => $tax]);
}
