<?php
/**
 * ACF tools v4.9.0 — đọc/ghi custom field qua API của ACF (đúng format, kèm field key).
 * Yêu cầu ACF hoặc ACF Pro active.
 */
defined('ABSPATH') || exit;

function xclipse_acf_guard() {
    return function_exists('get_field') && function_exists('update_field')
        ? null : xclipse_err('acf_inactive', 'ACF chưa cài hoặc chưa active', 400);
}

function xclipse_acf_groups(WP_REST_Request $r) {
    if ($e = xclipse_acf_guard()) return $e;
    $groups = function_exists('acf_get_field_groups') ? acf_get_field_groups() : [];
    $out = [];
    foreach ($groups as $g) {
        $out[] = xclipse_compact([
            'key'      => $g['key'],
            'title'    => $g['title'],
            'active'   => !empty($g['active']),
            'location' => $g['location'] ?? [],
            'fields'   => function_exists('acf_get_fields') ? count((array) acf_get_fields($g['key'])) : null,
        ]);
    }
    return xclipse_ok(['count' => count($out), 'groups' => $out]);
}

function xclipse_acf_field_brief(array $f): array {
    $o = xclipse_compact([
        'key'      => $f['key'] ?? '',
        'name'     => $f['name'] ?? '',
        'label'    => $f['label'] ?? '',
        'type'     => $f['type'] ?? '',
        'required' => !empty($f['required']),
        'choices'  => $f['choices'] ?? null,
    ]);
    if (!empty($f['sub_fields'])) $o['sub_fields'] = array_map('xclipse_acf_field_brief', $f['sub_fields']);
    if (!empty($f['layouts']))    $o['layouts'] = array_values(array_map(function ($l) {
        return ['name' => $l['name'] ?? '', 'sub_fields' => array_map('xclipse_acf_field_brief', $l['sub_fields'] ?? [])];
    }, $f['layouts']));
    return $o;
}

function xclipse_acf_group_fields(WP_REST_Request $r) {
    if ($e = xclipse_acf_guard()) return $e;
    $fields = acf_get_fields($r['group_key']);
    if (!$fields) return xclipse_err('not_found', 'Không có field group ' . $r['group_key'], 404);
    return xclipse_ok(['group' => $r['group_key'], 'fields' => array_map('xclipse_acf_field_brief', $fields)]);
}

/** Field group áp dụng cho post (theo location rules). */
function xclipse_acf_fields(WP_REST_Request $r) {
    if ($e = xclipse_acf_guard()) return $e;
    $id = (int) $r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không có post #' . $id, 404);
    $out = [];
    foreach (acf_get_field_groups(['post_id' => $id]) as $g) {
        $out[] = ['group' => $g['key'], 'title' => $g['title'], 'fields' => array_map('xclipse_acf_field_brief', (array) acf_get_fields($g['key']))];
    }
    return xclipse_ok(['post_id' => $id, 'groups' => $out]);
}

function xclipse_acf_get(WP_REST_Request $r) {
    if ($e = xclipse_acf_guard()) return $e;
    $id = (int) $r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không có post #' . $id, 404);
    $format = filter_var($r['format'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $vals = get_fields($id, $format);
    return xclipse_ok(['post_id' => $id, 'formatted' => $format, 'fields' => $vals ?: new stdClass()]);
}

function xclipse_acf_set(WP_REST_Request $r) {
    if ($e = xclipse_acf_guard()) return $e;
    $id = (int) $r['id'];
    if (!get_post($id)) return xclipse_err('not_found', 'Không có post #' . $id, 404);
    $b = $r->get_json_params();
    $fields = is_array($b) && isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : null;
    if (!$fields) return xclipse_err('bad_request', 'Cần "fields": {tên_field: giá_trị}', 400);

    $ok = []; $fail = [];
    foreach ($fields as $name => $val) {
        $name = (string) $name;
        $ref  = function_exists('acf_get_field') ? acf_get_field($name) : null;
        $sel  = $ref ? $ref['key'] : $name;
        if (!$ref && !get_post_meta($id, '_' . $name, true)) {
            $fail[] = $name . ' (field chưa khai báo trong ACF)';
            continue;
        }
        if (update_field($sel, $val, $id) !== false) $ok[] = $name; else $fail[] = $name;
    }
    return xclipse_ok(['post_id' => $id, 'updated' => $ok, 'failed' => $fail]);
}
