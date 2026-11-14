<?php
/** Users: list, get, create, update + fix_user_role */
defined('ABSPATH') || exit;

// USERS
function xclipse_users_list(WP_REST_Request $r) {
    $args = ['number' => min((int)($r['per_page'] ?? 20), 50), 'paged' => (int)($r['page'] ?? 1), 'orderby' => 'registered', 'order' => 'DESC'];
    if ($r['role'])   $args['role']   = sanitize_text_field($r['role']);
    if ($r['search']) $args['search'] = '*' . esc_attr($r['search']) . '*';
    $users = get_users($args);
    return xclipse_ok(array_map(fn($u) => ['id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'roles' => $u->roles, 'date' => substr($u->user_registered, 0, 10)], $users));
}

function xclipse_user_get(WP_REST_Request $r) {
    $u = get_user_by('id', (int)$r['id']);
    if (!$u) return xclipse_err('not_found', 'Không tìm thấy user');
    return xclipse_ok(xclipse_compact(['id' => $u->ID, 'login' => $u->user_login, 'name' => $u->display_name, 'email' => $u->user_email, 'url' => $u->user_url, 'roles' => $u->roles, 'date' => $u->user_registered, 'bio' => get_user_meta($u->ID, 'description', true)]));
}

function xclipse_user_create(WP_REST_Request $r) {
    $b  = $r->get_json_params();
    $id = wp_create_user(sanitize_user($b['username'] ?? ''), $b['password'] ?? wp_generate_password(), sanitize_email($b['email'] ?? ''));
    if (is_wp_error($id)) return $id;
    if (isset($b['role']))         $u = new WP_User($id) and $u->set_role(sanitize_text_field($b['role']));
    if (isset($b['display_name'])) wp_update_user(['ID' => $id, 'display_name' => sanitize_text_field($b['display_name'])]);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_user_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $d  = ['ID' => $id];
    if (isset($b['email']))        $d['user_email']   = sanitize_email($b['email']);
    if (isset($b['display_name'])) $d['display_name'] = sanitize_text_field($b['display_name']);
    if (isset($b['url']))          $d['user_url']      = esc_url_raw($b['url']);
    $res = wp_update_user($d);
    if (is_wp_error($res)) return $res;
    if (isset($b['role'])) { $u = new WP_User($id); $u->set_role(sanitize_text_field($b['role'])); }
    if (isset($b['bio']))  update_user_meta($id, 'description', sanitize_textarea_field($b['bio']));
    // Rank Math author social/website meta
    $rm_social = [
        'website'   => 'rank_math_author_website',
        'facebook'  => 'rank_math_social_facebook',
        'twitter'   => 'rank_math_social_twitter',
        'linkedin'  => 'rank_math_social_linkedin',
        'instagram' => 'rank_math_social_instagram',
        'youtube'   => 'rank_math_social_youtube',
        'pinterest' => 'rank_math_social_pinterest',
        'soundcloud'=> 'rank_math_social_soundcloud',
        'myspace'   => 'rank_math_social_myspace',
        'wikipedia' => 'rank_math_social_wikipedia',
    ];
    foreach ($rm_social as $field => $meta_key) {
        if (isset($b[$field])) update_user_meta($id, $meta_key, esc_url_raw($b[$field]));
    }
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

// ── Fix User Role ────────────────────────────────────────────
function xclipse_fix_user_role(WP_REST_Request $req) {
    $user_id  = (int)($req->get_param('user_id') ?? 0);
    $new_role = sanitize_text_field($req->get_param('role') ?? '');
    $allowed  = ['editor', 'author', 'contributor', 'subscriber'];

    if (!$user_id || !in_array($new_role, $allowed, true)) {
        return xclipse_err('Invalid user_id or role. Allowed roles: ' . implode(', ', $allowed));
    }

    $user = get_userdata($user_id);
    if (!$user) return xclipse_err("User {$user_id} not found");

    $old_roles = $user->roles;
    $user->set_role($new_role);

    return xclipse_ok([
        'user_id'   => $user_id,
        'login'     => $user->user_login,
        'old_roles' => $old_roles,
        'new_role'  => $new_role,
    ]);
}