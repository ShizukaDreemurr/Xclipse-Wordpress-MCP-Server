<?php
/** Debug/temporary endpoints */
defined('ABSPATH') || exit;

// MENUS
// DEBUG — xoá sau khi dùng
function xclipse_debug_fatal() {
    $data = get_option('xclipse_last_fatal', ['status' => 'no_fatal_recorded']);
    return xclipse_ok($data);
}

function xclipse_debug_burst() {
    global $wpdb;
    $tables = $wpdb->get_col("SHOW TABLES LIKE '%burst%'");
    $out = ['tables' => $tables, 'schema' => [], 'sample' => [], 'count' => []];
    foreach ($tables as $t) {
        $out['schema'][$t] = $wpdb->get_results("DESCRIBE `{$t}`", ARRAY_A);
        $out['count'][$t]  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t}`");
        $out['sample'][$t] = $wpdb->get_row("SELECT * FROM `{$t}` LIMIT 1", ARRAY_A);
    }
    return xclipse_ok($out);
}