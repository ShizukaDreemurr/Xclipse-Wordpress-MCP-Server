<?php
/** Posts CRUD + audit_post_performance */
defined('ABSPATH') || exit;

// POSTS — compact list, full detail
function xclipse_posts_list(WP_REST_Request $r) {
    $args = [
        'post_type'      => 'post',
        'post_status'    => $r['status'] ?? 'publish',
        'posts_per_page' => min((int)($r['per_page'] ?? 10), 50),
        'paged'          => (int)($r['page'] ?? 1),
        's'              => sanitize_text_field($r['search'] ?? ''),
        'cat'            => (int)($r['cat'] ?? 0) ?: null,
        'orderby'        => $r['orderby'] ?? 'date',
        'order'          => strtoupper($r['order'] ?? 'DESC'),
    ];
    if (!empty($r['author_id'])) $args['author'] = (int)$r['author_id'];
    $q      = new WP_Query($args);
    $fields = xclipse_parse_fields($r['fields'] ?? '');
    return xclipse_ok([
        'total' => $q->found_posts,
        'pages' => $q->max_num_pages,
        'posts' => array_map(fn($p) => xclipse_filter_fields(xclipse_compact([
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'slug'       => $p->post_name,
            'st'         => $p->post_status,
            'created_at' => $p->post_date,
            'modified_at'=> $p->post_modified,
            'url'        => get_permalink($p->ID),
            'cats'       => wp_get_post_categories($p->ID, ['fields' => 'names']),
            'author'     => xclipse_author_info((int)$p->post_author),
        ]), $fields), $q->posts),
    ]);
}

function xclipse_post_get(WP_REST_Request $r) {
    $p = get_post((int)$r['id']);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy bài viết');
    return xclipse_ok(xclipse_compact([
        'id'      => $p->ID,
        'title'   => $p->post_title,
        'slug'    => $p->post_name,
        'status'  => $p->post_status,
        'date'    => $p->post_date,
        'modified'=> $p->post_modified,
        'url'     => get_permalink($p->ID),
        'excerpt' => $p->post_excerpt,
        'content' => $p->post_content,
        'cats'    => wp_get_post_categories($p->ID, ['fields' => 'names']),
        'tags'    => wp_get_post_tags($p->ID, ['fields' => 'names']),
        'thumb'   => get_the_post_thumbnail_url($p->ID, 'medium') ?: null,
        'seo'     => xclipse_seo($p->ID),
    ]));
}

function xclipse_post_create(WP_REST_Request $r) {
    $b  = $r->get_json_params();
    $id = wp_insert_post([
        'post_title'   => sanitize_text_field($b['title'] ?? ''),
        'post_content' => wp_kses_post($b['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($b['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($b['status'] ?? 'draft'),
        'post_name'    => sanitize_title($b['slug'] ?? ''),
        'tags_input'   => $b['tags'] ?? [],
    ], true);
    if (is_wp_error($id)) return $id;
    if (!empty($b['categories'])) wp_set_post_categories($id, array_map('absint', $b['categories']));
    xclipse_save_seo($id, $b);
    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_post_update(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $b  = $r->get_json_params();
    $d  = ['ID' => $id];
    if (isset($b['title']))   $d['post_title']   = sanitize_text_field($b['title']);
    if (isset($b['content'])) $d['post_content'] = wp_kses_post($b['content']);
    if (isset($b['excerpt'])) $d['post_excerpt'] = sanitize_text_field($b['excerpt']);
    if (isset($b['status']))  $d['post_status']  = sanitize_text_field($b['status']);
    if (isset($b['slug']))    $d['post_name']    = sanitize_title($b['slug']);
    $res = wp_update_post($d, true);
    if (is_wp_error($res)) return $res;
    if (isset($b['categories'])) wp_set_post_categories($id, array_map('absint', $b['categories']));
    if (isset($b['tags']))       wp_set_post_tags($id, $b['tags']);
    xclipse_save_seo($id, $b);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_post_delete(WP_REST_Request $r) {
    $id = (int)$r['id'];
    $p  = get_post($id);
    if (!$p) return xclipse_err('not_found', 'Không tìm thấy');
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_audit_post_performance(WP_REST_Request $r) {
    global $wpdb;
    $post_type = sanitize_text_field($r['post_type'] ?? 'post');
    $per_page  = min((int)($r['per_page'] ?? 50), 200);
    $days      = min((int)($r['days'] ?? 30), 90);
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_date FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in})
         ORDER BY post_date DESC LIMIT {$per_page}"
    );

    // Burst pageviews (nếu có)
    $burst_views = [];
    $burst_table = $wpdb->prefix . 'burst_statistics';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$burst_table}'") === $burst_table) {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $rows = $wpdb->get_results(
            "SELECT page_id, SUM(page_views) views FROM {$burst_table}
             WHERE date >= '{$since}' AND page_id > 0
             GROUP BY page_id"
        );
        foreach ($rows as $row) $burst_views[(int)$row->page_id] = (int)$row->views;
    }

    $results = [];
    foreach ($posts as $p) {
        $pid        = (int)$p->ID;
        $score      = (int)get_post_meta($pid, 'rank_math_seo_score', true);
        $kw         = get_post_meta($pid, 'rank_math_focus_keyword', true);
        $title      = get_post_meta($pid, 'rank_math_title', true);
        $desc       = get_post_meta($pid, 'rank_math_description', true);
        $views      = $burst_views[$pid] ?? 0;
        $age_days   = (int)((time() - strtotime($p->post_date)) / 86400);

        $flags = [];
        if ($views > 100 && $score < 60)              $flags[] = 'high_traffic_low_score';
        if ($views === 0 && $age_days > 30)            $flags[] = 'no_traffic_30d';
        if (!$kw)                                      $flags[] = 'no_focus_kw';
        if (!$title)                                   $flags[] = 'no_seo_title';
        if (!$desc)                                    $flags[] = 'no_meta_desc';
        if ($score > 0 && $score < 50)                $flags[] = 'low_score';
        if ($age_days > 180 && $views < 10)           $flags[] = 'zombie_content';

        $results[] = [
            'id'       => $pid,
            'title'    => $p->post_title,
            'url'      => get_permalink($pid),
            'score'    => $score ?: null,
            'views'    => $views,
            'age_days' => $age_days,
            'has_kw'   => (bool)$kw,
            'has_title'=> (bool)$title,
            'has_desc' => (bool)$desc,
            'flags'    => $flags,
        ];
    }

    // Sort: nhiều flags nhất lên đầu, nếu bằng nhau sort theo views DESC
    usort($results, fn($a,$b) => count($b['flags']) !== count($a['flags'])
        ? count($b['flags']) - count($a['flags'])
        : $b['views'] - $a['views']);

    $flag_summary = [];
    foreach ($results as $item) {
        foreach ($item['flags'] as $f) {
            $flag_summary[$f] = ($flag_summary[$f] ?? 0) + 1;
        }
    }

    return xclipse_ok([
        'period_days'  => $days,
        'total'        => count($results),
        'flag_summary' => $flag_summary,
        'items'        => $results,
    ]);
}