<?php
/**
 * Contact Form 7 — MCP API endpoints
 * Version: 4.0.0
 */
defined('ABSPATH') || exit;

// HELPERS

/** Kiểm tra CF7 đang active */
function xclipse_cf7_active(): bool {
    return defined('WPCF7_VERSION') || function_exists('wpcf7');
}

/** Lấy object WPCF7_ContactForm từ post ID */
function xclipse_cf7_get_form(int $id): ?WPCF7_ContactForm {
    if (!xclipse_cf7_active()) return null;
    return WPCF7_ContactForm::get_instance($id) ?: null;
}

/** Format compact cho 1 form */
function xclipse_cf7_form_compact(WPCF7_ContactForm $form): array {
    $post = get_post($form->id());
    return [
        'id'           => $form->id(),
        'title'        => $form->title(),
        'slug'         => $post ? $post->post_name : '',
        'status'       => $post ? $post->post_status : '',
        'shortcode'    => '[contact-form-7 id="' . $form->id() . '" title="' . esc_attr($form->title()) . '"]',
        'locale'       => $form->locale(),
        'created_at'   => $post ? $post->post_date : '',
        'modified_at'  => $post ? $post->post_modified : '',
    ];
}

/** Format đầy đủ cho 1 form (kèm body, mail config, messages) */
function xclipse_cf7_form_full(WPCF7_ContactForm $form): array {
    $base = xclipse_cf7_form_compact($form);

    // Form body (HTML fields)
    $base['form'] = $form->prop('form');

    // Mail settings
    $mail  = $form->prop('mail');
    $mail2 = $form->prop('mail_2');
    $base['mail'] = $mail;
    if (!empty($mail2['active'])) $base['mail_2'] = $mail2;

    // Messages
    $base['messages'] = $form->prop('messages');

    // Additional settings
    $base['additional_settings'] = $form->prop('additional_settings');

    return $base;
}

/** Trích xuất tất cả field tags từ form body */
function xclipse_cf7_parse_tags(WPCF7_ContactForm $form): array {
    if (!class_exists('WPCF7_FormTagsManager')) return [];
    $manager = WPCF7_FormTagsManager::get_instance();
    $tags    = $manager->scan($form->prop('form'));
    $result  = [];
    foreach ($tags as $tag) {
        if (empty($tag->name)) continue; // bỏ submit/static tags
        $result[] = [
            'type'     => $tag->type,
            'name'     => $tag->name,
            'required' => str_ends_with($tag->type, '*'),
            'options'  => $tag->options,
            'values'   => $tag->raw_values,
        ];
    }
    return $result;
}

// FORMS — LIST

function xclipse_cf7_forms_list(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $per  = max(1, min(100, (int)($req['per_page'] ?? 20)));
    $page = max(1, (int)($req['page'] ?? 1));
    $s    = sanitize_text_field($req['search'] ?? '');

    $query_args = [
        'post_type'      => 'wpcf7_contact_form',
        'post_status'    => 'publish',
        'posts_per_page' => $per,
        'paged'          => $page,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];
    if ($s) $query_args['s'] = $s;

    $q     = new WP_Query($query_args);
    $items = [];
    foreach ($q->posts as $post) {
        $form = WPCF7_ContactForm::get_instance($post->ID);
        if ($form) $items[] = xclipse_cf7_form_compact($form);
    }

    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'items' => $items,
    ]);
}

// FORMS — GET

function xclipse_cf7_form_get(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    return xclipse_ok(xclipse_cf7_form_full($form));
}

// FORMS — CREATE

function xclipse_cf7_form_create(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $b     = $req->get_json_params();
    $title = sanitize_text_field($b['title'] ?? 'New Form');

    // Tạo form mới từ template mặc định của CF7
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    if (!$form) return xclipse_err('Không thể tạo form.');

    // Override body nếu truyền vào
    if (!empty($b['form'])) {
        $form->set_properties(['form' => wp_kses_post($b['form'])]);
    }

    // Mail config
    if (!empty($b['mail']) && is_array($b['mail'])) {
        $current = $form->prop('mail') ?: [];
        $form->set_properties(['mail' => array_merge($current, $b['mail'])]);
    }

    // Messages
    if (!empty($b['messages']) && is_array($b['messages'])) {
        $current = $form->prop('messages') ?: [];
        $form->set_properties(['messages' => array_merge($current, $b['messages'])]);
    }

    // Additional settings
    if (isset($b['additional_settings'])) {
        $form->set_properties(['additional_settings' => sanitize_textarea_field($b['additional_settings'])]);
    }

    $id = $form->save();
    if (!$id) return xclipse_err('Lưu form thất bại.');

    return xclipse_ok(xclipse_cf7_form_full(WPCF7_ContactForm::get_instance($id)), 201);
}

// FORMS — UPDATE

function xclipse_cf7_form_update(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    $b     = $req->get_json_params();
    $props = [];

    if (isset($b['title'])) {
        // Title là post_title — cập nhật qua wp_update_post
        wp_update_post(['ID' => $id, 'post_title' => sanitize_text_field($b['title'])]);
    }
    if (isset($b['form'])) {
        $props['form'] = wp_kses_post($b['form']);
    }
    if (isset($b['mail']) && is_array($b['mail'])) {
        $current = $form->prop('mail') ?: [];
        $props['mail'] = array_merge($current, $b['mail']);
    }
    if (isset($b['mail_2']) && is_array($b['mail_2'])) {
        $current = $form->prop('mail_2') ?: [];
        $props['mail_2'] = array_merge($current, $b['mail_2']);
    }
    if (isset($b['messages']) && is_array($b['messages'])) {
        $current = $form->prop('messages') ?: [];
        $props['messages'] = array_merge($current, $b['messages']);
    }
    if (isset($b['additional_settings'])) {
        $props['additional_settings'] = sanitize_textarea_field($b['additional_settings']);
    }
    if (isset($b['locale'])) {
        $props['locale'] = sanitize_text_field($b['locale']);
    }

    if (!empty($props)) {
        $form->set_properties($props);
    }

    $saved = $form->save();
    if (!$saved) return xclipse_err('Lưu form thất bại.');

    return xclipse_ok(xclipse_cf7_form_full(WPCF7_ContactForm::get_instance($id)));
}

// FORMS — DELETE

function xclipse_cf7_form_delete(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    $title = $form->title();
    $result = wp_trash_post($id);
    if (!$result) return xclipse_err("Không thể xoá form ID {$id}.");

    return xclipse_ok(['deleted' => $id, 'title' => $title]);
}

// FORMS — DUPLICATE (clone)

function xclipse_cf7_form_duplicate(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    $b         = $req->get_json_params();
    $new_title = sanitize_text_field($b['title'] ?? ($form->title() . ' (Copy)'));

    // Clone bằng cách copy tất cả props
    $new_form = WPCF7_ContactForm::get_template(['title' => $new_title]);
    if (!$new_form) return xclipse_err('Không thể tạo form mới.');

    $new_form->set_properties([
        'form'                => $form->prop('form'),
        'mail'                => $form->prop('mail'),
        'mail_2'              => $form->prop('mail_2'),
        'messages'            => $form->prop('messages'),
        'additional_settings' => $form->prop('additional_settings'),
    ]);

    $new_id = $new_form->save();
    if (!$new_id) return xclipse_err('Lưu form clone thất bại.');

    return xclipse_ok(xclipse_cf7_form_full(WPCF7_ContactForm::get_instance($new_id)), 201);
}

// FIELDS — xem tag fields trong form body

function xclipse_cf7_fields_list(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    return xclipse_ok([
        'form_id' => $id,
        'title'   => $form->title(),
        'fields'  => xclipse_cf7_parse_tags($form),
    ]);
}

// SUBMISSIONS — đọc entries từ Flamingo (CF7 addon lưu submissions)

function xclipse_cf7_submissions_list(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    // Flamingo lưu entries trong post type 'flamingo_inbound'
    if (!post_type_exists('flamingo_inbound'))
        return xclipse_err("Flamingo chưa được cài. Cài Flamingo để lưu submissions.", 503);

    $id   = (int)($req['form_id'] ?? 0);
    $per  = max(1, min(100, (int)($req['per_page'] ?? 20)));
    $page = max(1, (int)($req['page'] ?? 1));

    $args = [
        'post_type'      => 'flamingo_inbound',
        'post_status'    => 'publish',
        'posts_per_page' => $per,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    // Filter theo form nếu truyền form_id
    if ($id) {
        $args['meta_query'] = [[
            'key'     => '_channel',
            'value'   => 'contact-form-7',
            'compare' => '=',
        ], [
            'key'     => '_source',
            'value'   => 'wpcf7-' . $id,
            'compare' => '=',
        ]];
        $args['meta_query']['relation'] = 'AND';
    }

    $q = new WP_Query($args);
    $items = [];
    foreach ($q->posts as $post) {
        $fields = get_post_meta($post->ID, '_fields', true);
        if (is_string($fields)) $fields = maybe_unserialize($fields);
        $items[] = [
            'id'         => $post->ID,
            'date'       => $post->post_date,
            'subject'    => $post->post_title,
            'from'       => get_post_meta($post->ID, '_from', true),
            'from_name'  => get_post_meta($post->ID, '_from_name', true),
            'channel'    => get_post_meta($post->ID, '_channel', true),
            'source'     => get_post_meta($post->ID, '_source', true),
            'fields'     => is_array($fields) ? $fields : [],
            'spam'       => (bool)get_post_meta($post->ID, '_spam', true),
            'ip'         => get_post_meta($post->ID, '_remote_ip', true),
        ];
    }

    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'items' => $items,
    ]);
}

/** Đọc chi tiết 1 submission */
function xclipse_cf7_submission_get(WP_REST_Request $req) {
    if (!post_type_exists('flamingo_inbound'))
        return xclipse_err("Flamingo chưa được cài.", 503);

    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'flamingo_inbound')
        return xclipse_err("Submission ID {$id} không tồn tại.", 404);

    $fields = get_post_meta($id, '_fields', true);
    if (is_string($fields)) $fields = maybe_unserialize($fields);

    return xclipse_ok([
        'id'        => $post->ID,
        'date'      => $post->post_date,
        'subject'   => $post->post_title,
        'content'   => $post->post_content,
        'from'      => get_post_meta($id, '_from', true),
        'from_name' => get_post_meta($id, '_from_name', true),
        'channel'   => get_post_meta($id, '_channel', true),
        'source'    => get_post_meta($id, '_source', true),
        'fields'    => is_array($fields) ? $fields : [],
        'spam'      => (bool)get_post_meta($id, '_spam', true),
        'ip'        => get_post_meta($id, '_remote_ip', true),
        'meta'      => get_post_meta($id),
    ]);
}

/** Đánh dấu submission là spam hoặc bỏ spam */
function xclipse_cf7_submission_spam(WP_REST_Request $req) {
    if (!post_type_exists('flamingo_inbound'))
        return xclipse_err("Flamingo chưa được cài.", 503);

    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'flamingo_inbound')
        return xclipse_err("Submission ID {$id} không tồn tại.", 404);

    $b    = $req->get_json_params();
    $spam = (bool)($b['spam'] ?? true);

    update_post_meta($id, '_spam', $spam ? 1 : 0);
    return xclipse_ok(['id' => $id, 'spam' => $spam]);
}

/** Xoá 1 submission (trash) */
function xclipse_cf7_submission_delete(WP_REST_Request $req) {
    if (!post_type_exists('flamingo_inbound'))
        return xclipse_err("Flamingo chưa được cài.", 503);

    $id   = (int)$req['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'flamingo_inbound')
        return xclipse_err("Submission ID {$id} không tồn tại.", 404);

    $result = wp_trash_post($id);
    return $result
        ? xclipse_ok(['deleted' => $id])
        : xclipse_err("Không thể xoá submission ID {$id}.");
}

// MAIL CONFIG helpers — xem cấu hình mail gửi

function xclipse_cf7_mail_get(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    return xclipse_ok([
        'form_id' => $id,
        'title'   => $form->title(),
        'mail'    => $form->prop('mail'),
        'mail_2'  => $form->prop('mail_2'),
    ]);
}

function xclipse_cf7_mail_update(WP_REST_Request $req) {
    if (!xclipse_cf7_active())
        return xclipse_err('Contact Form 7 chưa được cài hoặc kích hoạt.');

    $id   = (int)$req['id'];
    $form = xclipse_cf7_get_form($id);
    if (!$form) return xclipse_err("Form ID {$id} không tồn tại.", 404);

    $b     = $req->get_json_params();
    $props = [];

    if (isset($b['mail']) && is_array($b['mail'])) {
        $current    = $form->prop('mail') ?: [];
        $props['mail'] = array_merge($current, $b['mail']);
    }
    if (isset($b['mail_2']) && is_array($b['mail_2'])) {
        $current       = $form->prop('mail_2') ?: [];
        $props['mail_2'] = array_merge($current, $b['mail_2']);
    }

    if (!empty($props)) {
        $form->set_properties($props);
        $form->save();
    }

    return xclipse_ok([
        'form_id' => $id,
        'mail'    => $form->prop('mail'),
        'mail_2'  => $form->prop('mail_2'),
    ]);
}
