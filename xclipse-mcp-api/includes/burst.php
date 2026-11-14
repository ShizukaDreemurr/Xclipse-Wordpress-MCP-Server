<?php
/** Burst Statistics, SiteKit, Site Speed */
defined('ABSPATH') || exit;

// BURST STATISTICS
function xclipse_burst(WP_REST_Request $r) {
    if (!defined('BURST_VERSION')) return xclipse_err('no_burst', 'Burst chưa cài', 501);
    global $wpdb;
    $days      = (int)($r['days'] ?? 30);
    $after_ts  = strtotime("-{$days} days");
    $after_str = date('Y-m-d', $after_ts);
    $t         = $wpdb->prefix . 'burst_statistics';
    if (!$wpdb->get_var("SHOW TABLES LIKE '{$t}'")) return xclipse_err('no_data', 'Burst chưa có data', 404);
    // Detect date column: Burst dùng Unix timestamp cột 'time', hoặc DATE cột 'date'
    $cols     = array_column($wpdb->get_results("DESCRIBE `{$t}`", ARRAY_A), 'Field');
    $use_unix = in_array('time', $cols) && !in_array('date', $cols);
    $where    = $use_unix
        ? $wpdb->prepare("WHERE `time` >= %d", $after_ts)
        : $wpdb->prepare("WHERE `date` >= %s", $after_str);
    // bounce
    $has_bounce = in_array('bounce', $cols);
    $bounce_sel = $has_bounce ? ',ROUND(SUM(bounce)/COUNT(*)*100,1) bounce_rate' : '';
    $total = $wpdb->get_row("SELECT COUNT(*) pv, COUNT(DISTINCT uid) uv {$bounce_sel} FROM `{$t}` {$where}", ARRAY_A);
    // bounce rate: tính từ uid có 1 pageview duy nhất
    $bounce_rate = null;
    if ($has_bounce) {
        $bounce_rate = $total['bounce_rate'] ?? null;
    } elseif (in_array('uid', $cols)) {
        $b = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT uid FROM `{$t}` {$where} GROUP BY uid HAVING COUNT(*)=1) x");
        $total_uv = (int)($total['uv'] ?? 1);
        $bounce_rate = $total_uv > 0 ? round((int)$b / $total_uv * 100, 1) : null;
    }
    // ── Devices + Referrers: statistics.session_id → sessions.ID → lookup tables
    // Schema: burst_statistics.session_id → burst_sessions.ID
    //         burst_sessions.device_id    → burst_devices.ID (name)
    //         burst_sessions.referrer     → burst_referrers.ID (name)
    $ts  = $wpdb->prefix . 'burst_sessions';
    $td  = $wpdb->prefix . 'burst_devices';
    $tr  = $wpdb->prefix . 'burst_referrers';
    $devs = [];
    $refs = [];

    if (in_array('session_id', $cols) && $wpdb->get_var("SHOW TABLES LIKE '{$ts}'")) {
        $s_cols = array_column($wpdb->get_results("DESCRIBE `{$ts}`", ARRAY_A), 'Field');

        // Devices: statistics → sessions → burst_devices
        if (in_array('device_id', $s_cols) && $wpdb->get_var("SHOW TABLES LIKE '{$td}'")) {
            $devs = $wpdb->get_results(
                "SELECT d.name device, COUNT(DISTINCT st.session_id) n
                 FROM `{$t}` st
                 JOIN `{$ts}` s ON s.ID = st.session_id
                 JOIN `{$td}` d ON d.ID = s.device_id
                 {$where}
                 GROUP BY d.name ORDER BY n DESC", ARRAY_A
            );
        }

        // Referrers: statistics → sessions → burst_referrers
        if (in_array('referrer', $s_cols) && $wpdb->get_var("SHOW TABLES LIKE '{$tr}'")) {
            $refs = $wpdb->get_results(
                "SELECT r.name referrer, COUNT(DISTINCT st.session_id) n
                 FROM `{$t}` st
                 JOIN `{$ts}` s ON s.ID = st.session_id
                 JOIN `{$tr}` r ON r.ID = s.referrer
                 {$where}
                 AND s.referrer > 0
                 GROUP BY r.name ORDER BY n DESC LIMIT 10", ARRAY_A
            );
        }

        // Bounce rate từ sessions.bounce
        if ($bounce_rate === null && in_array('bounce', $s_cols)) {
            $sess_stat = $wpdb->get_row(
                "SELECT COUNT(DISTINCT st.session_id) total,
                        SUM(s.bounce) bounced
                 FROM `{$t}` st
                 JOIN `{$ts}` s ON s.ID = st.session_id
                 {$where}", ARRAY_A
            );
            if (!empty($sess_stat['total'])) {
                $bounce_rate = round((float)$sess_stat['bounced'] / (int)$sess_stat['total'] * 100, 1);
            }
        }
    }

    return xclipse_ok([
        'days'      => $days,
        'from'      => $after_str,
        'total'     => array_merge($total ?? [], $bounce_rate !== null ? ['bounce_rate' => $bounce_rate] : []),
        'devices'   => $devs,
        'referrers' => $refs,
    ]);
}

function xclipse_burst_top(WP_REST_Request $r) {
    if (!defined('BURST_VERSION')) return xclipse_err('no_burst', 'Burst chưa cài', 501);
    global $wpdb;
    $days      = (int)($r['days'] ?? 30);
    $limit     = min((int)($r['limit'] ?? 20), 100);
    $after_ts  = strtotime("-{$days} days");
    $after_str = date('Y-m-d', $after_ts);
    $t         = $wpdb->prefix . 'burst_statistics';
    $cols      = array_column($wpdb->get_results("DESCRIBE `{$t}`", ARRAY_A), 'Field');
    $use_unix  = in_array('time', $cols) && !in_array('date', $cols);
    $where     = $use_unix
        ? $wpdb->prepare("WHERE `time` >= %d", $after_ts)
        : $wpdb->prepare("WHERE `date` >= %s", $after_str);
    // page_url column might be named 'page_url' or 'url'
    $url_col   = in_array('page_url', $cols) ? 'page_url' : (in_array('url', $cols) ? 'url' : 'page_url');
    $pages = $wpdb->get_results($wpdb->prepare(
        "SELECT `{$url_col}` url, COUNT(*) pv, COUNT(DISTINCT uid) uv FROM `{$t}` {$where} GROUP BY `{$url_col}` ORDER BY pv DESC LIMIT %d",
        $limit
    ), ARRAY_A);
    return xclipse_ok(['days' => $days, 'pages' => $pages]);
}

// SITE KIT
function xclipse_sitekit() {
    if (!defined('GOOGLESITEKIT_VERSION')) return xclipse_err('no_sk', 'Site Kit chưa cài', 501);
    $mods = [];
    foreach (['analytics-4', 'search-console', 'adsense', 'pagespeed-insights'] as $m) {
        $s = get_option("googlesitekit_{$m}_settings");
        if ($s) $mods[$m] = ['connected' => !empty($s['ownerID']), 'props' => array_intersect_key((array)$s, array_flip(['propertyID', 'measurementID', 'siteURL', 'accountID']))];
    }
    return xclipse_ok(['version' => GOOGLESITEKIT_VERSION, 'modules' => $mods, 'dashboard' => admin_url('admin.php?page=googlesitekit-dashboard')]);
}

function xclipse_site_speed(WP_REST_Request $r) {
    $check_url = esc_url_raw($r['url'] ?? '') ?: home_url('/');
    $strategy  = in_array($r['strategy'] ?? '', ['mobile', 'desktop']) ? $r['strategy'] : 'mobile';

    $psi_key = get_option('xclipse_pagespeed_key', 'AIzaSyC-QI29YeiFfrhEWJjUd7oC2AaLMEnmSjs');
    $api_url = add_query_arg(array_filter([
        'url'      => $check_url,
        'strategy' => $strategy,
        'category' => 'performance',
        'key'      => $psi_key ?: null,
    ]), 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed');

    $data = xclipse_http_get($api_url);
    if (is_wp_error($data)) return $data;

    $cats    = $data['lighthouseResult']['categories'] ?? [];
    $audits  = $data['lighthouseResult']['audits'] ?? [];
    $cwv     = $data['loadingExperience']['metrics'] ?? [];

    // Core Web Vitals từ field data
    $field = [];
    foreach (['FIRST_CONTENTFUL_PAINT_MS' => 'fcp', 'FIRST_INPUT_DELAY_MS' => 'fid',
              'LARGEST_CONTENTFUL_PAINT_MS' => 'lcp', 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'cls',
              'INTERACTION_TO_NEXT_PAINT' => 'inp', 'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb'] as $key => $alias) {
        if (isset($cwv[$key])) {
            $field[$alias] = [
                'p75'      => $cwv[$key]['percentile'] ?? null,
                'category' => $cwv[$key]['category'] ?? null,
            ];
        }
    }

    // Lab metrics
    $lab_keys = ['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time',
                 'cumulative-layout-shift', 'speed-index', 'interactive'];
    $lab = [];
    foreach ($lab_keys as $k) {
        if (isset($audits[$k])) {
            $lab[str_replace('-', '_', $k)] = [
                'value'       => $audits[$k]['numericValue'] ?? null,
                'display'     => $audits[$k]['displayValue'] ?? null,
                'score'       => $audits[$k]['score'] ?? null,
            ];
        }
    }

    // Opportunities (top 5 cải thiện)
    $opps = [];
    foreach ($audits as $audit) {
        if (($audit['details']['type'] ?? '') === 'opportunity' && ($audit['score'] ?? 1) < 0.9) {
            $opps[] = xclipse_compact([
                'id'          => $audit['id'] ?? null,
                'title'       => $audit['title'] ?? null,
                'savings_ms'  => $audit['details']['overallSavingsMs'] ?? null,
                'score'       => $audit['score'] ?? null,
            ]);
        }
    }
    usort($opps, fn($a, $b) => ($b['savings_ms'] ?? 0) <=> ($a['savings_ms'] ?? 0));
    $opps = array_slice($opps, 0, 5);

    return xclipse_ok([
        'url'      => $check_url,
        'strategy' => $strategy,
        'score'    => round(($cats['performance']['score'] ?? 0) * 100),
        'field_data' => $field ?: null,
        'lab'      => $lab,
        'opportunities' => $opps,
        'overall_category' => $data['loadingExperience']['overall_category'] ?? null,
    ]);
}