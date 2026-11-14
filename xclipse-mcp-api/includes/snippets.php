<?php
/** Code Snippets plugin support — v4.6.14 */
defined('ABSPATH') || exit;

/** Detect plugin đang dùng, trả tên table */
function xclipse_snippet_table(): string {
    global $wpdb;
    // WPCode (legacy, giữ lại để fallback)
    $t1 = $wpdb->prefix . 'wpcode_snippets';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t1}'")) return $t1;
    // Code Snippets plugin (primary)
    $t2 = $wpdb->prefix . 'snippets';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t2}'")) return $t2;
    return xclipse_err('no_snippets', 'Không tìm thấy Code Snippets plugin', 501);
}

/** Map row DB → output chuẩn */
function xclipse_snippet_row(array $row, string $table): array {
    $is_wpcode = str_contains($table, 'wpcode');
    if ($is_wpcode) {
        return [
            'id'       => (int)$row['id'],
            'name'     => $row['snippet_title'] ?? '',
            'code'     => $row['snippet_code'] ?? '',
            'type'     => $row['snippet_type'] ?? 'php',
            'scope'    => $row['location'] ?? 'everywhere',
            'active'   => (bool)($row['active'] ?? 0),
            'status'   => ($row['active'] ?? 0) ? 'active' : 'inactive',
            'priority' => (int)($row['priority'] ?? 10),
            'note'     => $row['note'] ?? null,
            'updated'  => $row['updated'] ?? null,
            'plugin'   => 'wpcode',
        ];
    }
    // Code Snippets — scope column stores scope (global/admin/front-end/...)
    // tags column may store serialized array — flatten to string
    $tags = $row['tags'] ?? '';
    if (is_string($tags) && strlen($tags) > 0 && $tags[0] === 'a') {
        $arr = @unserialize($tags);
        $tags = is_array($arr) ? implode(', ', $arr) : $tags;
    }
    return [
        'id'       => (int)$row['id'],
        'name'     => $row['name'] ?? '',
        'code'     => $row['code'] ?? '',
        'type'     => 'php', // code-snippets không phân loại type riêng, mặc định php
        'scope'    => $row['scope'] ?? 'global',
        'active'   => (bool)($row['active'] ?? 0),
        'status'   => ($row['active'] ?? 0) ? 'active' : 'inactive',
        'priority' => (int)($row['priority'] ?? 10),
        'note'     => $row['description'] ?? null,
        'tags'     => $tags ?: null,
        'updated'  => $row['modified'] ?? null,
        'plugin'   => 'code-snippets',
    ];
}

function xclipse_snippets_list(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $limit  = min((int)($r['per_page'] ?? 50), 200);
    $status = sanitize_key($r['status'] ?? 'any'); // active | inactive | any
    $search = sanitize_text_field($r['search'] ?? '');

    $where = '1=1';
    if ($status !== 'any') $where .= $wpdb->prepare(' AND active=%d', $status === 'active' ? 1 : 0);

    $is_wpcode = str_contains($table, 'wpcode');
    $name_col  = $is_wpcode ? 'snippet_title' : 'name';
    if ($search) $where .= $wpdb->prepare(" AND {$name_col} LIKE %s", '%' . $wpdb->esc_like($search) . '%');

    $rows = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$limit}",
        ARRAY_A
    );
    $out = array_map(fn($row) => xclipse_snippet_row($row, $table), $rows ?: []);
    // Ẩn code trong list — dùng snippet_get để xem full
    $out = array_map(fn($s) => array_merge($s, ['code' => strlen($s['code']) . ' chars']), $out);
    return xclipse_ok([
        'total'    => count($out),
        'plugin'   => $is_wpcode ? 'wpcode' : 'code-snippets',
        'snippets' => $out,
    ]);
}

function xclipse_snippet_get(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int)$r['id']), ARRAY_A);
    if (!$row) return xclipse_err('not_found', 'Snippet không tồn tại');
    return xclipse_ok(xclipse_snippet_row($row, $table));
}

function xclipse_snippet_create(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $b    = $r->get_json_params();
    $name = sanitize_text_field($b['name'] ?? '');
    $code = $b['code'] ?? '';
    if (!$name || !$code) return xclipse_err('missing', 'Thiếu name hoặc code');

    $active    = isset($b['active']) ? (int)(bool)$b['active'] : 0;
    $is_wpcode = str_contains($table, 'wpcode');

    if ($is_wpcode) {
        $wpdb->insert($table, [
            'snippet_title' => $name,
            'snippet_code'  => $code,
            'snippet_type'  => sanitize_key($b['type'] ?? 'php'),
            'active'        => $active,
            'location'      => sanitize_key($b['scope'] ?? $b['location'] ?? 'everywhere'),
            'priority'      => (int)($b['priority'] ?? 10),
            'note'          => sanitize_textarea_field($b['note'] ?? ''),
            'updated'       => current_time('mysql'),
        ]);
    } else {
        // Code Snippets plugin schema
        // scope: global|admin|front-end|single-use|content
        $scope = sanitize_key($b['scope'] ?? 'global');
        $wpdb->insert($table, [
            'name'        => $name,
            'code'        => $code,
            'scope'       => $scope,
            'active'      => $active,
            'priority'    => (int)($b['priority'] ?? 10),
            'description' => sanitize_textarea_field($b['note'] ?? ''),
            'tags'        => sanitize_text_field($b['tags'] ?? ''),
            'modified'    => current_time('mysql'),
        ]);
    }
    return xclipse_ok(['ok' => true, 'id' => $wpdb->insert_id, 'name' => $name]);
}

function xclipse_snippet_update(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $id  = (int)$r['id'];
    $b   = $r->get_json_params();
    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d", $id));
    if (!$row) return xclipse_err('not_found', 'Snippet không tồn tại');

    $is_wpcode = str_contains($table, 'wpcode');
    $data = [];

    if ($is_wpcode) {
        $data['updated'] = current_time('mysql');
        if (isset($b['name']))     $data['snippet_title'] = sanitize_text_field($b['name']);
        if (isset($b['code']))     $data['snippet_code']  = $b['code'];
        if (isset($b['type']))     $data['snippet_type']  = sanitize_key($b['type']);
        if (isset($b['active']))   $data['active']        = (int)(bool)$b['active'];
        if (isset($b['scope']))    $data['location']      = sanitize_key($b['scope']);
        if (isset($b['location'])) $data['location']      = sanitize_key($b['location']);
        if (isset($b['priority'])) $data['priority']      = (int)$b['priority'];
        if (isset($b['note']))     $data['note']          = sanitize_textarea_field($b['note']);
    } else {
        // Code Snippets plugin
        $data['modified'] = current_time('mysql');
        if (isset($b['name']))     $data['name']        = sanitize_text_field($b['name']);
        if (isset($b['code']))     $data['code']        = $b['code'];
        if (isset($b['scope']))    $data['scope']       = sanitize_key($b['scope']);
        if (isset($b['active']))   $data['active']      = (int)(bool)$b['active'];
        if (isset($b['priority'])) $data['priority']    = (int)$b['priority'];
        if (isset($b['note']))     $data['description'] = sanitize_textarea_field($b['note']);
        if (isset($b['tags']))     $data['tags']        = sanitize_text_field($b['tags']);
    }

    if (empty($data) || (count($data) === 1 && isset($data['modified']))) {
        return xclipse_err('no_fields', 'Không có field nào để cập nhật');
    }

    $wpdb->update($table, $data, ['id' => $id]);

    // Xoá cache
    if ($is_wpcode) {
        delete_transient('wpcode_snippets_cache');
        wp_cache_delete('wpcode_snippets', 'wpcode');
    } else {
        delete_transient('cs_active_snippets');
        wp_cache_delete('active_snippets', 'code-snippets');
    }
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_snippet_delete(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $id      = (int)$r['id'];
    $deleted = $wpdb->delete($table, ['id' => $id]);
    return $deleted
        ? xclipse_ok(['ok' => true, 'id' => $id])
        : xclipse_err('not_found', 'Snippet không tồn tại');
}

/** Bật/tắt snippet nhanh không cần gửi toàn bộ body */
function xclipse_snippet_toggle(WP_REST_Request $r) {
    global $wpdb;
    $table = xclipse_snippet_table();
    if (is_wp_error($table)) return $table;
    $id  = (int)$r['id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, active FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!$row) return xclipse_err('not_found', 'Snippet không tồn tại');

    $b          = $r->get_json_params();
    $new_active = isset($b['active']) ? (int)(bool)$b['active'] : (int)!$row['active'];
    $is_wpcode  = str_contains($table, 'wpcode');

    $wpdb->update($table,
        $is_wpcode
            ? ['active' => $new_active, 'updated'  => current_time('mysql')]
            : ['active' => $new_active, 'modified' => current_time('mysql')],
        ['id' => $id]
    );

    if ($is_wpcode) {
        delete_transient('wpcode_snippets_cache');
        wp_cache_delete('wpcode_snippets', 'wpcode');
    } else {
        delete_transient('cs_active_snippets');
        wp_cache_delete('active_snippets', 'code-snippets');
    }
    return xclipse_ok(['ok' => true, 'id' => $id, 'active' => (bool)$new_active]);
}
