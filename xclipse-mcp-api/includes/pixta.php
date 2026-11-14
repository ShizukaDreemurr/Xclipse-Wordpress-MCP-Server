<?php
defined('ABSPATH') || exit;

// HELPERS

/**
 * Phát hiện CPT slug của Pixta thông qua CPT UI option hoặc đọc thẳng
 * registered post types. Trả về array ['broker'=>slug, 'scam'=>slug, 'book'=>slug]
 */
function xclipse_pixta_detect_cpts(): array {
    // Đọc cấu hình CPT UI
    $cptui = get_option('cptui_post_types', []);

    // Mapping keyword → category
    $map = [
        'broker' => ['broker', 'san', 'sàn', 'forex', 'exchange', 'review'],
        'scam'   => ['scam', 'black', 'blacklist', 'lua', 'lừa'],
        'book'   => ['book', 'sach', 'sách'],
        'blog'   => ['blog', 'post', 'article', 'tin'],
    ];

    $detected = ['broker' => null, 'scam' => null, 'book' => null, 'blog' => null];

    // Thử match từ CPT UI config
    foreach ($cptui as $slug => $cfg) {
        $label = strtolower($cfg['labels']['singular_name'] ?? $slug);
        $slug_lower = strtolower($slug);
        foreach ($map as $type => $keywords) {
            if ($detected[$type]) continue;
            foreach ($keywords as $kw) {
                if (strpos($slug_lower, $kw) !== false || strpos($label, $kw) !== false) {
                    $detected[$type] = $slug;
                    break;
                }
            }
        }
    }

    // Fallback: kiểm tra CPT đã đăng ký với các slug phổ biến của vnbr plugin
    $common = [
        'broker' => ['vnbr_broker', 'pixta_broker', 'broker', 'sanforex', 'forex_broker'],
        'scam'   => ['vnbr_scam', 'pixta_scam', 'scam', 'blacklist'],
        'book'   => ['vnbr_book', 'pixta_book', 'book', 'sach'],
        'blog'   => ['post'],  // Nếu dùng built-in post type
    ];

    $all_types = get_post_types(['public' => true], 'names');
    foreach ($common as $type => $candidates) {
        if ($detected[$type]) continue;
        foreach ($candidates as $candidate) {
            if (isset($all_types[$candidate]) || post_type_exists($candidate)) {
                $detected[$type] = $candidate;
                break;
            }
        }
    }

    // Nếu blog vẫn null, dùng 'post'
    if (!$detected['blog']) $detected['blog'] = 'post';

    return $detected;
}

/**
 * Lấy tất cả ACF fields của 1 post (nếu ACF PRO đang active)
 */
function xclipse_pixta_acf_get(int $post_id): array {
    if (!function_exists('get_fields')) return [];
    $fields = get_fields($post_id);
    return is_array($fields) ? $fields : [];
}

/**
 * Lưu ACF fields hàng loạt. $data là array ['field_name' => value]
 */
function xclipse_pixta_acf_save(int $post_id, array $data): array {
    if (!function_exists('update_field')) return ['acf_not_available' => true];
    $updated = [];
    $failed  = [];
    foreach ($data as $key => $value) {
        $result = update_field(sanitize_key($key), $value, $post_id);
        if ($result !== false) {
            $updated[] = $key;
        } else {
            // Thử add_post_meta nếu update_field không nhận
            update_post_meta($post_id, $key, $value);
            $updated[] = $key . ' (meta_fallback)';
        }
    }
    return ['updated' => $updated, 'failed' => $failed];
}

/**
 * Format 1 post thành compact response cho Pixta CPT
 */
function xclipse_pixta_post_compact($p, bool $include_acf = false, bool $include_content = false): array {
    $pid = (int)$p->ID;
    $out = [
        'id'          => $pid,
        'title'       => $p->post_title,
        'slug'        => $p->post_name,
        'status'      => $p->post_status,
        'type'        => $p->post_type,
        'date'        => $p->post_date,
        'modified'    => $p->post_modified,
        'url'         => get_permalink($pid),
        'thumb'       => get_the_post_thumbnail_url($pid, 'medium') ?: null,
    ];
    if ($include_content) {
        $out['content'] = $p->post_content;
        $out['excerpt'] = $p->post_excerpt;
    }
    if ($include_acf) {
        $out['acf'] = xclipse_pixta_acf_get($pid);
    }
    // Taxonomy terms
    $taxes = get_object_taxonomies($p->post_type, 'names');
    foreach ($taxes as $tax) {
        $terms = wp_get_post_terms($pid, $tax, ['fields' => 'all']);
        if (!is_wp_error($terms) && !empty($terms)) {
            $out['terms'][$tax] = array_map(fn($t) => [
                'id'   => $t->term_id,
                'name' => $t->name,
                'slug' => $t->slug,
            ], $terms);
        }
    }
    $out['seo'] = xclipse_seo($pid);
    return xclipse_compact($out);
}

/**
 * Build WP_Query args chung cho list endpoints
 */
function xclipse_pixta_list_args(WP_REST_Request $r, string $post_type): array {
    $args = [
        'post_type'      => $post_type,
        'post_status'    => $r['status'] ?? 'publish',
        'posts_per_page' => min((int)($r['per_page'] ?? 20), 100),
        'paged'          => (int)($r['page'] ?? 1),
        's'              => sanitize_text_field($r['search'] ?? ''),
        'orderby'        => $r['orderby'] ?? 'date',
        'order'          => strtoupper($r['order'] ?? 'DESC'),
    ];
    // Filter theo taxonomy term ID
    if (!empty($r['cat'])) {
        $taxes = get_object_taxonomies($post_type, 'names');
        if (!empty($taxes)) {
            // Thử category taxonomy đầu tiên tìm thấy
            $args['tax_query'] = [[
                'taxonomy' => $taxes[0],
                'field'    => 'term_id',
                'terms'    => array_map('absint', explode(',', $r['cat'])),
            ]];
        }
    }
    // Filter theo taxonomy slug
    if (!empty($r['tax']) && !empty($r['term'])) {
        $args['tax_query'] = [[
            'taxonomy' => sanitize_key($r['tax']),
            'field'    => 'slug',
            'terms'    => array_map('sanitize_title', explode(',', $r['term'])),
        ]];
    }
    return $args;
}

// SCHEMA DISCOVERY

function xclipse_pixta_schema() {
    $detected = xclipse_pixta_detect_cpts();

    // CPT UI config
    $cptui_raw = get_option('cptui_post_types', []);
    $cpts = [];
    foreach ($cptui_raw as $slug => $cfg) {
        $taxes_registered = [];
        // Kiểm tra taxonomies đăng ký với CPT này từ CPT UI
        $cptui_taxes = get_option('cptui_taxonomies', []);
        foreach ($cptui_taxes as $tax_slug => $tax_cfg) {
            if (!empty($tax_cfg['object_types']) && in_array($slug, (array)$tax_cfg['object_types'])) {
                $taxes_registered[] = $tax_slug;
            }
        }

        $cpts[$slug] = [
            'label'       => $cfg['labels']['name'] ?? $slug,
            'singular'    => $cfg['labels']['singular_name'] ?? $slug,
            'public'      => $cfg['public'] ?? true,
            'taxonomies'  => $taxes_registered,
            'count'       => (int)wp_count_posts($slug)->publish,
        ];
    }

    // ACF field groups
    $field_groups = [];
    if (function_exists('acf_get_field_groups')) {
        $groups = acf_get_field_groups();
        foreach ($groups as $group) {
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group['key']) : [];
            $field_groups[$group['key']] = [
                'title'       => $group['title'],
                'location'    => $group['location'] ?? [],
                'field_count' => is_array($fields) ? count($fields) : 0,
                'fields'      => is_array($fields) ? array_map(fn($f) => [
                    'key'   => $f['key'],
                    'name'  => $f['name'],
                    'label' => $f['label'],
                    'type'  => $f['type'],
                ], $fields) : [],
            ];
        }
    }

    // Taxonomy terms cho các CPT Pixta
    $tax_data = [];
    foreach (array_filter($detected) as $type => $slug) {
        if ($slug === 'post' && $type === 'blog') continue;
        $taxes = get_object_taxonomies($slug, 'objects');
        foreach ($taxes as $tax) {
            $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
            $tax_data[$tax->name] = [
                'label'  => $tax->label,
                'terms'  => is_array($terms) ? array_map(fn($t) => [
                    'id'    => $t->term_id,
                    'name'  => $t->name,
                    'slug'  => $t->slug,
                    'count' => $t->count,
                ], $terms) : [],
            ];
        }
    }

    return xclipse_ok([
        'detected_slugs' => $detected,
        'cpt_ui_types'   => $cpts,
        'acf_groups'     => $field_groups,
        'taxonomies'     => $tax_data,
    ]);
}

// BROKER ENDPOINTS

function xclipse_pixta_brokers_list(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['broker'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT broker. Dùng GET /pixta/schema để kiểm tra.');

    $args = xclipse_pixta_list_args($r, $slug);
    $q    = new WP_Query($args);
    $include_acf = filter_var($r['include_acf'] ?? false, FILTER_VALIDATE_BOOLEAN);

    return xclipse_ok([
        'total'   => $q->found_posts,
        'pages'   => $q->max_num_pages,
        'cpt'     => $slug,
        'brokers' => array_map(fn($p) => xclipse_pixta_post_compact($p, $include_acf), $q->posts),
    ]);
}

function xclipse_pixta_broker_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy broker');
    return xclipse_ok(xclipse_pixta_post_compact($p, true, true));
}

function xclipse_pixta_broker_create(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['broker'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT broker');

    $b  = $r->get_json_params();
    $id = wp_insert_post([
        'post_type'    => $slug,
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
    ], true);
    if (is_wp_error($id)) return xclipse_err('insert_error', $id->get_error_message());

    // ACF fields
    if (!empty($b['acf']) && is_array($b['acf'])) {
        xclipse_pixta_acf_save($id, $b['acf']);
    }
    // Taxonomy terms
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    // Featured image
    if (!empty($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    // SEO
    xclipse_save_seo($id, $b);

    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_pixta_broker_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy broker');

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

    if (!empty($b['acf']) && is_array($b['acf']))   xclipse_pixta_acf_save($id, $b['acf']);
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (isset($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_pixta_broker_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không tìm thấy broker');
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => 'trashed']);
}

// SCAM / BLACKLIST ENDPOINTS

function xclipse_pixta_scam_list(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['scam'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT scam. Dùng GET /pixta/schema để kiểm tra.');

    $args = xclipse_pixta_list_args($r, $slug);
    $q    = new WP_Query($args);
    $include_acf = filter_var($r['include_acf'] ?? false, FILTER_VALIDATE_BOOLEAN);

    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'cpt'   => $slug,
        'items' => array_map(fn($p) => xclipse_pixta_post_compact($p, $include_acf), $q->posts),
    ]);
}

function xclipse_pixta_scam_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy scam entry');
    return xclipse_ok(xclipse_pixta_post_compact($p, true, true));
}

function xclipse_pixta_scam_create(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['scam'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT scam');

    $b  = $r->get_json_params();
    $id = wp_insert_post([
        'post_type'    => $slug,
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
    ], true);
    if (is_wp_error($id)) return xclipse_err('insert_error', $id->get_error_message());

    if (!empty($b['acf']) && is_array($b['acf']))   xclipse_pixta_acf_save($id, $b['acf']);
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (!empty($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_pixta_scam_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy scam entry');

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

    if (!empty($b['acf']) && is_array($b['acf']))   xclipse_pixta_acf_save($id, $b['acf']);
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (isset($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_pixta_scam_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không tìm thấy scam entry');
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => 'trashed']);
}

// BOOK ENDPOINTS

function xclipse_pixta_books_list(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['book'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT book. Dùng GET /pixta/schema để kiểm tra.');

    $args = xclipse_pixta_list_args($r, $slug);
    $q    = new WP_Query($args);
    $include_acf = filter_var($r['include_acf'] ?? false, FILTER_VALIDATE_BOOLEAN);

    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'cpt'   => $slug,
        'books' => array_map(fn($p) => xclipse_pixta_post_compact($p, $include_acf), $q->posts),
    ]);
}

function xclipse_pixta_book_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy sách');
    return xclipse_ok(xclipse_pixta_post_compact($p, true, true));
}

function xclipse_pixta_book_create(WP_REST_Request $r) {
    $cpts = xclipse_pixta_detect_cpts();
    $slug = $cpts['book'];
    if (!$slug) return xclipse_err('not_found', 'Không tìm thấy CPT book');

    $b  = $r->get_json_params();
    $id = wp_insert_post([
        'post_type'    => $slug,
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
    ], true);
    if (is_wp_error($id)) return xclipse_err('insert_error', $id->get_error_message());

    if (!empty($b['acf']) && is_array($b['acf']))   xclipse_pixta_acf_save($id, $b['acf']);
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (!empty($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_pixta_book_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy sách');

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

    if (!empty($b['acf']) && is_array($b['acf']))   xclipse_pixta_acf_save($id, $b['acf']);
    if (!empty($b['terms']) && is_array($b['terms'])) {
        foreach ($b['terms'] as $tax => $term_ids) {
            wp_set_object_terms($id, array_map('absint', (array)$term_ids), sanitize_key($tax));
        }
    }
    if (isset($b['thumb_id'])) set_post_thumbnail($id, (int)$b['thumb_id']);
    xclipse_save_seo($id, $b);

    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_pixta_book_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không tìm thấy sách');
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => 'trashed']);
}

// GENERIC ACF ENDPOINTS (cho bất kỳ post ID nào)

function xclipse_pixta_acf_get_endpoint(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy post');
    if (!function_exists('get_fields')) return xclipse_err('acf_missing', 'ACF PRO không có sẵn');

    $all_fields = get_fields($id);

    // Nếu muốn kèm field definitions
    $with_schema = filter_var($r['with_schema'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $out = [
        'id'         => $id,
        'post_type'  => $p->post_type,
        'title'      => $p->post_title,
        'acf_values' => is_array($all_fields) ? $all_fields : [],
    ];

    if ($with_schema && function_exists('acf_get_field_groups')) {
        $groups = acf_get_field_groups(['post_id' => $id]);
        $schema = [];
        foreach ($groups as $group) {
            $fields = acf_get_fields($group['key']);
            if (is_array($fields)) {
                foreach ($fields as $f) {
                    $schema[$f['name']] = [
                        'key'     => $f['key'],
                        'label'   => $f['label'],
                        'type'    => $f['type'],
                        'required'=> $f['required'] ?? false,
                    ];
                }
            }
        }
        $out['acf_schema'] = $schema;
    }

    return xclipse_ok($out);
}

function xclipse_pixta_acf_set_endpoint(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy post');

    $b = $r->get_json_params();
    if (empty($b['acf']) || !is_array($b['acf'])) {
        return xclipse_err('missing_fields', 'Thiếu trường "acf": {"field_name": value, ...}');
    }

    $result = xclipse_pixta_acf_save($id, $b['acf']);
    return xclipse_ok(array_merge(['id' => $id], $result));
}

// CATEGORIES / TAXONOMIES

function xclipse_pixta_categories(WP_REST_Request $r) {
    $cpts     = xclipse_pixta_detect_cpts();
    $tax_data = [];

    foreach (array_filter($cpts) as $type => $slug) {
        $taxes = get_object_taxonomies($slug, 'objects');
        foreach ($taxes as $tax) {
            if (isset($tax_data[$tax->name])) continue;
            $terms = get_terms([
                'taxonomy'   => $tax->name,
                'hide_empty' => false,
                'orderby'    => 'name',
            ]);
            $tax_data[$tax->name] = [
                'label'       => $tax->label,
                'public'      => $tax->public,
                'used_by_cpt' => $type,
                'terms'       => is_array($terms) ? array_map(fn($t) => [
                    'id'     => $t->term_id,
                    'name'   => $t->name,
                    'slug'   => $t->slug,
                    'count'  => $t->count,
                    'parent' => $t->parent,
                ], $terms) : [],
            ];
        }
    }

    return xclipse_ok(['taxonomies' => $tax_data]);
}
