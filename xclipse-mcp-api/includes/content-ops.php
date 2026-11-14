<?php
/**
 * Content Operations — slug management, clone, restore, term CRUD, permalink flush
 * Version: 4.1.0
 */
defined('ABSPATH') || exit;

// SLUG — đổi slug cho bất kỳ post/page/CPT nào

function xclipse_content_slug_update(WP_REST_Request $req) {
    $id      = (int)$req['id'];
    $post    = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);

    $b        = $req->get_json_params();
    $new_slug = sanitize_title($b['slug'] ?? '');
    if (!$new_slug) return xclipse_err('slug không được để trống.');

    $old_slug = $post->post_name;
    $old_url  = get_permalink($id);

    $res = wp_update_post(['ID' => $id, 'post_name' => $new_slug], true);
    if (is_wp_error($res)) return xclipse_err($res->get_error_message());

    // Xóa cache permalink
    delete_post_meta($id, '_sample_permalink');
    clean_post_cache($id);

    $new_url = get_permalink($id);

    // Tạo redirect RankMath nếu available
    $redirect_created = false;
    if (class_exists('RankMath\\Redirections\\Redirection') && $old_slug !== $new_slug) {
        $old_path = rtrim(parse_url($old_url, PHP_URL_PATH), '/');
        $new_path = rtrim(parse_url($new_url, PHP_URL_PATH), '/');
        if ($old_path && $new_path && $old_path !== $new_path) {
            \RankMath\Redirections\Redirection::create([
                'sources'     => [['pattern' => ltrim($old_path, '/'), 'comparison' => 'exact']],
                'url_to'      => $new_url,
                'header_code' => 301,
                'status'      => 'active',
            ]);
            $redirect_created = true;
        }
    }

    return xclipse_ok([
        'id'               => $id,
        'old_slug'         => $old_slug,
        'new_slug'         => $new_slug,
        'old_url'          => $old_url,
        'new_url'          => $new_url,
        'redirect_created' => $redirect_created,
    ]);
}

function xclipse_content_slug_bulk(WP_REST_Request $req) {
    $b     = $req->get_json_params();
    $items = $b['items'] ?? [];
    if (empty($items) || !is_array($items))
        return xclipse_err('items phải là array [{id, slug}, ...]');

    $ok   = [];
    $fail = [];

    foreach ($items as $item) {
        $id       = (int)($item['id'] ?? 0);
        $new_slug = sanitize_title($item['slug'] ?? '');

        if (!$id || !$new_slug) {
            $fail[] = ['id' => $id, 'error' => 'id hoặc slug không hợp lệ'];
            continue;
        }
        $post = get_post($id);
        if (!$post) {
            $fail[] = ['id' => $id, 'error' => 'không tìm thấy'];
            continue;
        }

        $old_slug = $post->post_name;
        $res      = wp_update_post(['ID' => $id, 'post_name' => $new_slug], true);
        if (is_wp_error($res)) {
            $fail[] = ['id' => $id, 'error' => $res->get_error_message()];
            continue;
        }
        clean_post_cache($id);
        $ok[] = ['id' => $id, 'old_slug' => $old_slug, 'new_slug' => $new_slug, 'url' => get_permalink($id)];
    }

    return xclipse_ok(['ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}

// PERMALINK / REWRITE FLUSH

function xclipse_content_flush_permalinks(WP_REST_Request $req) {
    flush_rewrite_rules(true);  // true = hard flush (ghi lại .htaccess)
    return xclipse_ok(['flushed' => true, 'time' => current_time('mysql')]);
}

// CLONE / DUPLICATE POST

function xclipse_content_clone(WP_REST_Request $req) {
    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);

    $b          = $req->get_json_params();
    $new_title  = sanitize_text_field($b['title'] ?? ($post->post_title . ' (Copy)'));
    $new_status = sanitize_text_field($b['status'] ?? 'draft');
    $new_slug   = sanitize_title($b['slug'] ?? '');  // bỏ trống = tự sinh

    $new_id = wp_insert_post([
        'post_type'    => $post->post_type,
        'post_title'   => $new_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_status'  => $new_status,
        'post_name'    => $new_slug,
        'post_author'  => $post->post_author,
        'post_parent'  => $post->post_parent,
        'menu_order'   => $post->menu_order,
    ], true);

    if (is_wp_error($new_id)) return xclipse_err($new_id->get_error_message());

    // Copy all post meta (bỏ qua internal WP meta bắt đầu bằng _edit_)
    $meta_list = get_post_meta($id);
    foreach ($meta_list as $key => $values) {
        if (str_starts_with($key, '_edit_')) continue;
        foreach ($values as $val) {
            add_post_meta($new_id, $key, maybe_unserialize($val));
        }
    }

    // Copy taxonomy terms
    $taxonomies = get_object_taxonomies($post->post_type);
    foreach ($taxonomies as $tax) {
        $terms = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms) && !empty($terms)) {
            wp_set_object_terms($new_id, $terms, $tax);
        }
    }

    // Copy featured image
    $thumb_id = get_post_thumbnail_id($id);
    if ($thumb_id) set_post_thumbnail($new_id, $thumb_id);

    // Copy page template nếu là page
    $template = get_post_meta($id, '_wp_page_template', true);
    if ($template) update_post_meta($new_id, '_wp_page_template', $template);

    return xclipse_ok([
        'original_id' => $id,
        'new_id'      => $new_id,
        'title'       => $new_title,
        'status'      => $new_status,
        'url'         => get_permalink($new_id),
    ]);
}

// RESTORE FROM TRASH

function xclipse_content_restore(WP_REST_Request $req) {
    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);
    if ($post->post_status !== 'trash')
        return xclipse_err("Post ID {$id} không ở trong trash (status: {$post->post_status}).");

    $b          = $req->get_json_params();
    $new_status = sanitize_text_field($b['status'] ?? 'draft');

    $res = wp_untrash_post($id);
    if (!$res) return xclipse_err("Không thể khôi phục post ID {$id}.");

    // wp_untrash_post trả về post về 'draft' hoặc status trước khi trash
    // Nếu cần set status khác, update thêm
    if ($new_status !== 'draft') {
        wp_update_post(['ID' => $id, 'post_status' => $new_status]);
    }

    return xclipse_ok([
        'id'         => $id,
        'title'      => $post->post_title,
        'new_status' => get_post_status($id),
        'url'        => get_permalink($id),
    ]);
}

// TERM CRUD — Category, Tag và bất kỳ taxonomy nào

function xclipse_term_list(WP_REST_Request $req) {
    $taxonomy = sanitize_text_field($req['taxonomy'] ?? 'category');
    if (!taxonomy_exists($taxonomy))
        return xclipse_err("Taxonomy '{$taxonomy}' không tồn tại.", 404);

    $per_page = min((int)($req['per_page'] ?? 100), 500);
    $page     = max(1, (int)($req['page'] ?? 1));
    $search   = sanitize_text_field($req['search'] ?? '');
    $parent   = isset($req['parent']) ? (int)$req['parent'] : '';

    $args = [
        'taxonomy'   => $taxonomy,
        'hide_empty' => (bool)($req['hide_empty'] ?? false),
        'orderby'    => sanitize_text_field($req['orderby'] ?? 'name'),
        'order'      => strtoupper($req['order'] ?? 'ASC'),
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
    ];
    if ($search !== '')  $args['search'] = $search;
    if ($parent !== '')  $args['parent'] = $parent;

    $terms = get_terms($args);
    $total = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

    if (is_wp_error($terms)) return xclipse_err($terms->get_error_message());

    return xclipse_ok([
        'taxonomy' => $taxonomy,
        'total'    => (int)$total,
        'items'    => array_map(fn($t) => [
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description,
            'parent'      => $t->parent ?: null,
            'count'       => $t->count,
            'url'         => get_term_link($t),
        ], $terms),
    ]);
}

function xclipse_term_get(WP_REST_Request $req) {
    $taxonomy = sanitize_text_field($req['taxonomy'] ?? 'category');
    $term_id  = (int)$req['term_id'];
    $term     = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term))
        return xclipse_err("Term ID {$term_id} không tồn tại trong taxonomy '{$taxonomy}'.", 404);

    // SEO meta từ RankMath
    $seo = [];
    if (defined('RANK_MATH_VERSION')) {
        $seo = array_filter([
            't' => get_term_meta($term_id, 'rank_math_title', true),
            'd' => get_term_meta($term_id, 'rank_math_description', true),
            'k' => get_term_meta($term_id, 'rank_math_focus_keyword', true),
        ], fn($v) => $v !== '');
    }

    return xclipse_ok([
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent ?: null,
        'count'       => $term->count,
        'url'         => get_term_link($term),
        'seo'         => $seo ?: null,
    ]);
}

function xclipse_term_create(WP_REST_Request $req) {
    $taxonomy = sanitize_text_field($req['taxonomy'] ?? 'category');
    if (!taxonomy_exists($taxonomy))
        return xclipse_err("Taxonomy '{$taxonomy}' không tồn tại.", 404);

    $b    = $req->get_json_params();
    $name = sanitize_text_field($b['name'] ?? '');
    if (!$name) return xclipse_err('name không được để trống.');

    $args = [];
    if (!empty($b['slug']))        $args['slug']        = sanitize_title($b['slug']);
    if (!empty($b['description'])) $args['description'] = sanitize_textarea_field($b['description']);
    if (isset($b['parent']))       $args['parent']      = (int)$b['parent'];

    $result = wp_insert_term($name, $taxonomy, $args);
    if (is_wp_error($result)) return xclipse_err($result->get_error_message());

    $term_id = (int)$result['term_id'];

    // SEO meta
    if (!empty($b['seo_title']))  update_term_meta($term_id, 'rank_math_title',            sanitize_text_field($b['seo_title']));
    if (!empty($b['seo_desc']))   update_term_meta($term_id, 'rank_math_description',       sanitize_textarea_field($b['seo_desc']));
    if (!empty($b['focus_kw']))   update_term_meta($term_id, 'rank_math_focus_keyword',     sanitize_text_field($b['focus_kw']));

    $term = get_term($term_id, $taxonomy);
    return xclipse_ok([
        'id'  => $term_id,
        'name'=> $term->name,
        'slug'=> $term->slug,
        'url' => get_term_link($term),
    ], 201);
}

function xclipse_term_update(WP_REST_Request $req) {
    $taxonomy = sanitize_text_field($req['taxonomy'] ?? 'category');
    $term_id  = (int)$req['term_id'];
    $term     = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term))
        return xclipse_err("Term ID {$term_id} không tồn tại.", 404);

    $b    = $req->get_json_params();
    $args = [];
    if (isset($b['name']))        $args['name']        = sanitize_text_field($b['name']);
    if (isset($b['slug']))        $args['slug']        = sanitize_title($b['slug']);
    if (isset($b['description'])) $args['description'] = sanitize_textarea_field($b['description']);
    if (isset($b['parent']))      $args['parent']      = (int)$b['parent'];

    if (!empty($args)) {
        $result = wp_update_term($term_id, $taxonomy, $args);
        if (is_wp_error($result)) return xclipse_err($result->get_error_message());
    }

    // SEO meta
    if (isset($b['seo_title']))  update_term_meta($term_id, 'rank_math_title',        sanitize_text_field($b['seo_title']));
    if (isset($b['seo_desc']))   update_term_meta($term_id, 'rank_math_description',   sanitize_textarea_field($b['seo_desc']));
    if (isset($b['focus_kw']))   update_term_meta($term_id, 'rank_math_focus_keyword', sanitize_text_field($b['focus_kw']));

    clean_term_cache($term_id, $taxonomy);
    $updated = get_term($term_id, $taxonomy);

    return xclipse_ok([
        'id'   => $term_id,
        'name' => $updated->name,
        'slug' => $updated->slug,
        'url'  => get_term_link($updated),
    ]);
}

function xclipse_term_delete(WP_REST_Request $req) {
    $taxonomy = sanitize_text_field($req['taxonomy'] ?? 'category');
    $term_id  = (int)$req['term_id'];
    $term     = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term))
        return xclipse_err("Term ID {$term_id} không tồn tại.", 404);

    $name   = $term->name;
    $result = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($result)) return xclipse_err($result->get_error_message());
    if ($result === false)    return xclipse_err("Không thể xóa term ID {$term_id}.");

    return xclipse_ok(['deleted' => $term_id, 'name' => $name, 'taxonomy' => $taxonomy]);
}

// POST META — đọc/ghi post meta trực tiếp

function xclipse_meta_get(WP_REST_Request $req) {
    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);

    $prefix = sanitize_text_field($req['prefix'] ?? '');
    $all    = get_post_meta($id);

    // Flatten: mỗi key → single value (không phải array của 1 phần tử)
    $flat = [];
    foreach ($all as $key => $values) {
        if ($prefix && !str_starts_with($key, $prefix)) continue;
        $val = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        $flat[$key] = $val;
    }

    return xclipse_ok([
        'post_id' => $id,
        'title'   => $post->post_title,
        'meta'    => $flat,
    ]);
}

// v4.6.8: ghi rank_math_* meta bằng $wpdb trực tiếp để bypass RankMath hooks
function xclipse_meta_set(WP_REST_Request $req) {
    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);

    $b       = $req->get_json_params();
    $set     = $b['set'] ?? [];
    $deletes = $b['delete'] ?? [];
    $updated = [];
    $deleted = [];

    global $wpdb;
    foreach ($set as $key => $value) {
        $key = sanitize_key($key);
        if (!$key) continue;
        // rank_math_* và _yoast_* cần ghi thẳng qua $wpdb để bypass plugin hooks
        if (str_starts_with($key, 'rank_math_') || str_starts_with($key, '_yoast_')) {
            $val = is_array($value) ? maybe_serialize($value) : (string)$value;
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key]);
            wp_cache_delete($id, 'post_meta');
            if ($val !== '') {
                $wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $id,
                    'meta_key'   => $key,
                    'meta_value' => $val,
                ]);
            }
        } else {
            update_post_meta($id, $key, $value);
        }
        $updated[] = $key;
    }
    foreach ((array)$deletes as $key) {
        $key = sanitize_key($key);
        if (!$key) continue;
        if (str_starts_with($key, 'rank_math_') || str_starts_with($key, '_yoast_')) {
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key]);
        } else {
            delete_post_meta($id, $key);
        }
        $deleted[] = $key;
    }

    clean_post_cache($id);
    wp_cache_delete($id, 'post_meta');
    if (function_exists('wp_cache_flush_group')) wp_cache_flush_group('post_meta');
    return xclipse_ok(['post_id' => $id, 'updated' => $updated, 'deleted' => $deleted]);
}

// POST STATUS — đổi status hàng loạt với filter mạnh

function xclipse_content_status_bulk(WP_REST_Request $req) {
    $b          = $req->get_json_params();
    $new_status = sanitize_text_field($b['new_status'] ?? '');
    $allowed    = ['publish', 'draft', 'pending', 'private', 'trash'];

    if (!$new_status || !in_array($new_status, $allowed, true))
        return xclipse_err('new_status phải là: ' . implode(', ', $allowed));

    $ids = [];

    if (!empty($b['ids'])) {
        // Mode 1: danh sách ID cụ thể
        $ids = array_map('intval', (array)$b['ids']);
    } else {
        // Mode 2: query theo post_type + filter
        $post_type      = sanitize_text_field($b['post_type'] ?? 'post');
        $current_status = sanitize_text_field($b['current_status'] ?? 'draft');
        $limit          = min((int)($b['limit'] ?? 50), 500);
        $date_before    = sanitize_text_field($b['date_before'] ?? '');
        $date_after     = sanitize_text_field($b['date_after'] ?? '');
        $cat            = (int)($b['cat'] ?? 0);

        $args = [
            'post_type'      => $post_type,
            'post_status'    => $current_status,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
        ];
        if ($date_before) $args['date_query'][] = ['before' => $date_before, 'inclusive' => true];
        if ($date_after)  $args['date_query'][] = ['after'  => $date_after,  'inclusive' => true];
        if ($cat)         $args['cat'] = $cat;

        $ids = get_posts($args);
    }

    if (empty($ids)) return xclipse_ok(['count' => 0, 'ok' => [], 'fail' => []]);

    $ok   = [];
    $fail = [];
    foreach ($ids as $id) {
        $id   = (int)$id;
        $post = get_post($id);
        if (!$post) { $fail[] = ['id' => $id, 'error' => 'not_found']; continue; }

        $res = $new_status === 'trash'
            ? wp_trash_post($id)
            : wp_update_post(['ID' => $id, 'post_status' => $new_status], true);

        if (is_wp_error($res)) {
            $fail[] = ['id' => $id, 'error' => $res->get_error_message()];
        } else {
            $ok[] = $id;
        }
    }

    return xclipse_ok(['count' => count($ok), 'ok' => $ok, 'fail' => $fail]);
}

// POST DATE — đổi ngày publish (cho SEO refresh)

function xclipse_content_date_update(WP_REST_Request $req) {
    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post) return xclipse_err("Post ID {$id} không tồn tại.", 404);

    $b    = $req->get_json_params();
    $date = $b['date'] ?? current_time('mysql');
    if (!strtotime($date)) return xclipse_err("date không hợp lệ: {$date}");

    $date_gmt = get_gmt_from_date($date);

    $res = wp_update_post([
        'ID'                => $id,
        'post_date'         => $date,
        'post_date_gmt'     => $date_gmt,
        'post_modified'     => $date,
        'post_modified_gmt' => $date_gmt,
        'edit_date'         => true,
    ], true);

    if (is_wp_error($res)) return xclipse_err($res->get_error_message());

    return xclipse_ok([
        'id'       => $id,
        'title'    => $post->post_title,
        'date'     => $date,
        'date_gmt' => $date_gmt,
    ]);
}

// POST AUTHOR — đổi tác giả hàng loạt

function xclipse_content_author_bulk(WP_REST_Request $req) {
    $b            = $req->get_json_params();
    $to_author_id = (int)($b['to_author_id'] ?? 0);
    if (!$to_author_id || !get_user_by('id', $to_author_id))
        return xclipse_err('to_author_id không hợp lệ hoặc user không tồn tại.');

    $ids = [];

    if (!empty($b['ids'])) {
        $ids = array_map('intval', (array)$b['ids']);
    } else {
        $from_author_id = (int)($b['from_author_id'] ?? 0);
        $post_type      = sanitize_text_field($b['post_type'] ?? 'post');
        $limit          = min((int)($b['limit'] ?? 100), 1000);

        if (!$from_author_id) return xclipse_err('Cần truyền ids hoặc from_author_id.');

        $args = [
            'post_type'      => $post_type,
            'author'         => $from_author_id,
            'posts_per_page' => $limit,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ];
        $ids = get_posts($args);
    }

    if (empty($ids)) return xclipse_ok(['count' => 0, 'ok' => []]);

    global $wpdb;
    $id_list = implode(',', array_map('intval', $ids));
    $updated = $wpdb->query(
        $wpdb->prepare("UPDATE {$wpdb->posts} SET post_author = %d WHERE ID IN ({$id_list})", $to_author_id)
    );

    foreach ($ids as $pid) clean_post_cache((int)$pid);

    $to_user = get_user_by('id', $to_author_id);
    return xclipse_ok([
        'count'      => (int)$updated,
        'to_author'  => $to_user ? $to_user->display_name : $to_author_id,
        'ids'        => $ids,
    ]);
}

// POST PARENT — đổi parent hàng loạt (cho pages)

function xclipse_content_parent_bulk(WP_REST_Request $req) {
    $b         = $req->get_json_params();
    $ids       = array_map('intval', (array)($b['ids'] ?? []));
    $parent_id = (int)($b['parent_id'] ?? 0);

    if (empty($ids)) return xclipse_err('ids không được để trống.');
    if ($parent_id && !get_post($parent_id)) return xclipse_err("parent_id {$parent_id} không tồn tại.");

    $ok   = [];
    $fail = [];
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post) { $fail[] = ['id' => $id, 'error' => 'not_found']; continue; }
        if ($parent_id === $id) { $fail[] = ['id' => $id, 'error' => 'không thể set parent là chính nó']; continue; }

        $res = wp_update_post(['ID' => $id, 'post_parent' => $parent_id], true);
        if (is_wp_error($res)) {
            $fail[] = ['id' => $id, 'error' => $res->get_error_message()];
        } else {
            $ok[] = ['id' => $id, 'url' => get_permalink($id)];
        }
    }

    return xclipse_ok(['count' => count($ok), 'ok' => $ok, 'fail' => $fail]);
}
