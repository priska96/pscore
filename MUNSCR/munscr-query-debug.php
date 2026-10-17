<?php
/*
 * TEMPORARY FRONTEND QUERY DEBUGGER
 * Danach wieder löschen.
 */

add_action('template_redirect', function () {

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['munscr_debug'])) {
        return;
    }

    global $wp_query, $post;

    header('Content-Type: text/plain; charset=utf-8');

    echo "========================================\n";
    echo "REQUEST\n";
    echo "========================================\n";

    echo "REQUEST URI: " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
    echo "Queried Object ID: " . get_queried_object_id() . "\n";
    echo "Global Post ID: " . ($post->ID ?? 'NONE') . "\n\n";

    echo "========================================\n";
    echo "CONDITIONALS\n";
    echo "========================================\n";

    echo "is_page: " . (is_page() ? 'YES' : 'NO') . "\n";
    echo "is_single: " . (is_single() ? 'YES' : 'NO') . "\n";
    echo "is_singular: " . (is_singular() ? 'YES' : 'NO') . "\n";
    echo "is_home: " . (is_home() ? 'YES' : 'NO') . "\n";
    echo "is_front_page: " . (is_front_page() ? 'YES' : 'NO') . "\n";
    echo "is_archive: " . (is_archive() ? 'YES' : 'NO') . "\n";
    echo "is_404: " . (is_404() ? 'YES' : 'NO') . "\n\n";

    echo "========================================\n";
    echo "QUERY\n";
    echo "========================================\n";

    print_r($wp_query->query_vars);

    echo "\n========================================\n";
    echo "POSTS RETURNED\n";
    echo "========================================\n";

    foreach ($wp_query->posts as $p) {
        echo "ID: " . $p->ID . "\n";
        echo "Title: " . $p->post_title . "\n";
        echo "Type: " . $p->post_type . "\n";
        echo "Status: " . $p->post_status . "\n";
        echo "Slug: " . $p->post_name . "\n";
        echo "Content length: " . strlen($p->post_content) . "\n";
        echo "----------------------------------------\n";
    }

    exit;
});