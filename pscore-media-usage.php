<?php
/**
 * Plugin Name: PSCORE Media Usage
 * Description: Shows where media files are used across WordPress pages and posts. Read-only helper for cleaning the PSCORE Media Library.
 * Version: 1.0.0
 * Author: PSCORE
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add "Used on" column to Media Library list view.
 */
add_filter('manage_media_columns', function ($columns) {

    $columns['pscore_media_usage'] = 'Used on';

    return $columns;
});


/**
 * Render usage information.
 */
add_action('manage_media_custom_column', function ($column_name, $attachment_id) {

    if ($column_name !== 'pscore_media_usage') {
        return;
    }

    $usage = pscore_find_media_usage($attachment_id);

    if (empty($usage)) {

        echo '<span style="color:#b32d2e;font-weight:600;">';
        echo 'Not found';
        echo '</span>';

        return;
    }

    echo '<div style="line-height:1.5;">';

    foreach ($usage as $item) {

        $edit_link = get_edit_post_link($item['id']);

        echo '<div style="margin-bottom:5px;">';

        if ($edit_link) {

            echo '<a href="' . esc_url($edit_link) . '">';
            echo esc_html($item['title']);
            echo '</a>';

        } else {

            echo esc_html($item['title']);
        }

        echo '<br>';

        echo '<small style="color:#646970;">';
        echo esc_html($item['type']);

        if (!empty($item['location'])) {
            echo ' · ' . esc_html($item['location']);
        }

        echo '</small>';

        echo '</div>';
    }

    echo '</div>';

}, 10, 2);


/**
 * Find pages/posts where an attachment appears to be used.
 *
 * READ ONLY:
 * Only SELECT queries are performed.
 */
function pscore_find_media_usage($attachment_id) {

    global $wpdb;

    $results = [];

    $attachment = get_post($attachment_id);

    if (!$attachment) {
        return [];
    }

    $url = wp_get_attachment_url($attachment_id);

    $file = get_post_meta(
        $attachment_id,
        '_wp_attached_file',
        true
    );

    $filename = $file ? basename($file) : '';

    /*
     * -------------------------------------------------------
     * 1. Search normal WordPress post_content
     * -------------------------------------------------------
     */

    $search_values = [];

    if ($url) {
        $search_values[] = $url;
    }

    if ($filename) {
        $search_values[] = $filename;
    }

    $search_values[] = 'wp-image-' . $attachment_id;

    foreach ($search_values as $search) {

        if (!$search) {
            continue;
        }

        $like = '%' . $wpdb->esc_like($search) . '%';

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT ID, post_title, post_type, post_status
                FROM {$wpdb->posts}
                WHERE post_content LIKE %s
                AND post_type NOT IN ('attachment', 'revision')
                ",
                $like
            )
        );

        foreach ($posts as $post) {

            pscore_add_usage_result(
                $results,
                $post->ID,
                $post->post_title,
                $post->post_type,
                'Content'
            );
        }
    }


    /*
     * -------------------------------------------------------
     * 2. Featured Images
     * -------------------------------------------------------
     */

    $featured_posts = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p
                ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value = %d
            AND p.post_type NOT IN ('attachment', 'revision')
            ",
            $attachment_id
        )
    );

    foreach ($featured_posts as $post) {

        pscore_add_usage_result(
            $results,
            $post->ID,
            $post->post_title,
            $post->post_type,
            'Featured Image'
        );
    }


    /*
     * -------------------------------------------------------
     * 3. Search postmeta
     *
     * Important for builders/plugins which store content
     * outside post_content.
     * -------------------------------------------------------
     */

    $meta_search_values = [];

    if ($url) {
        $meta_search_values[] = $url;
    }

    if ($filename) {
        $meta_search_values[] = $filename;
    }

    /*
     * Search attachment ID too, but more carefully.
     */
    $meta_search_values[] = '"' . $attachment_id . '"';

    foreach ($meta_search_values as $search) {

        if (!$search) {
            continue;
        }

        $like = '%' . $wpdb->esc_like($search) . '%';

        $meta_posts = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT DISTINCT
                    p.ID,
                    p.post_title,
                    p.post_type,
                    pm.meta_key
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p
                    ON p.ID = pm.post_id
                WHERE pm.meta_value LIKE %s
                AND p.post_type NOT IN ('attachment', 'revision')
                ",
                $like
            )
        );

        foreach ($meta_posts as $post) {

            pscore_add_usage_result(
                $results,
                $post->ID,
                $post->post_title,
                $post->post_type,
                'Meta: ' . $post->meta_key
            );
        }
    }


    /*
     * -------------------------------------------------------
     * Sort results alphabetically
     * -------------------------------------------------------
     */

    uasort($results, function ($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });

    return array_values($results);
}


/**
 * Add result while avoiding duplicates.
 */
function pscore_add_usage_result(
    &$results,
    $post_id,
    $title,
    $post_type,
    $location
) {

    if (!$title) {
        $title = '(no title)';
    }

    $type_object = get_post_type_object($post_type);

    if ($type_object && isset($type_object->labels->singular_name)) {
        $type_label = $type_object->labels->singular_name;
    } else {
        $type_label = $post_type;
    }

    /*
     * Same post can have multiple kinds of references.
     */
    $key = $post_id . '|' . $location;

    if (isset($results[$key])) {
        return;
    }

    $results[$key] = [
        'id'       => (int) $post_id,
        'title'    => $title,
        'type'     => $type_label,
        'location' => $location,
    ];
}