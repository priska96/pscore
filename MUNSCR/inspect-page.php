<?php

require_once __DIR__ . '/wp-load.php';

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Administrator login required.');
}

header('Content-Type: text/plain; charset=utf-8');

global $wpdb;

/*
 * HIER die ID deiner neuen Testseite eintragen.
 */
$page_id = 2390;

$post = $wpdb->get_row(
    $wpdb->prepare(
        "
        SELECT
            ID,
            post_title,
            post_status,
            post_name,
            post_type,
            post_parent,
            post_content,
            post_content_filtered,
            post_date,
            post_modified
        FROM {$wpdb->posts}
        WHERE ID = %d
        ",
        $page_id
    ),
    ARRAY_A
);

echo "========================================\n";
echo "PAGE\n";
echo "========================================\n\n";

if (!$post) {
    exit("Page not found.\n");
}

echo "ID: {$post['ID']}\n";
echo "Title: {$post['post_title']}\n";
echo "Status: {$post['post_status']}\n";
echo "Slug: {$post['post_name']}\n";
echo "Type: {$post['post_type']}\n";
echo "Parent: {$post['post_parent']}\n";
echo "Modified: {$post['post_modified']}\n";

echo "\n";
echo "post_content length: "
    . strlen($post['post_content'])
    . "\n";

echo "post_content SHA256: "
    . hash('sha256', $post['post_content'])
    . "\n";

echo "\n";
echo "----- POST CONTENT -----\n\n";
echo $post['post_content'];

echo "\n\n";
echo "========================================\n";
echo "REVISIONS\n";
echo "========================================\n\n";

$revisions = $wpdb->get_results(
    $wpdb->prepare(
        "
        SELECT
            ID,
            post_status,
            post_name,
            post_type,
            post_parent,
            post_date,
            post_modified,
            post_content
        FROM {$wpdb->posts}
        WHERE post_parent = %d
          AND post_type = 'revision'
        ORDER BY ID DESC
        ",
        $page_id
    ),
    ARRAY_A
);

if (!$revisions) {
    echo "No revisions found.\n";
} else {
    foreach ($revisions as $revision) {

        echo "----------------------------------------\n";
        echo "Revision ID: {$revision['ID']}\n";
        echo "Parent: {$revision['post_parent']}\n";
        echo "Modified: {$revision['post_modified']}\n";

        echo "Content length: "
            . strlen($revision['post_content'])
            . "\n";

        echo "SHA256: "
            . hash('sha256', $revision['post_content'])
            . "\n";

        echo "\nCONTENT:\n";
        echo $revision['post_content'];
        echo "\n\n";
    }
}

echo "\n\n";
echo "========================================\n";
echo "WORDPRESS get_post()\n";
echo "========================================\n\n";

$wp_post = get_post(2390);

echo "ID: " . $wp_post->ID . "\n";
echo "Status: " . $wp_post->post_status . "\n";
echo "Type: " . $wp_post->post_type . "\n";
echo "Content length: " . strlen($wp_post->post_content) . "\n";

echo "\n\n";
echo "========================================\n";
echo "THE_CONTENT FILTER\n";
echo "========================================\n\n";

$rendered = apply_filters(
    'the_content',
    $wp_post->post_content
);

echo "Rendered length: " . strlen($rendered) . "\n\n";
echo $rendered;