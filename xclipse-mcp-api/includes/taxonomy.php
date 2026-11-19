<?php
/** Categories & tags */
defined('ABSPATH') || exit;

// TAXONOMY
function xclipse_cats() {
    return xclipse_ok(array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count], get_categories(['hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC'])));
}

function xclipse_tags() {
    return xclipse_ok(array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count], get_tags(['hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'number' => 100])));
}