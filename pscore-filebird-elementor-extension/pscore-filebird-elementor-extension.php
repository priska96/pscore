<?php
/**
 * Plugin Name: PSCORE FileBird Elementor Builder Extension
 * Description: Adds the existing FileBird folder structure to the WordPress media modal used by Elementor. FileBird data is read-only.
 * Version: 1.0.0
 * Author: PSCORE
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}


/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

define('PSCORE_FBE_VERSION', '1.0.0');
define('PSCORE_FBE_URL', plugin_dir_url(__FILE__));


/*
|--------------------------------------------------------------------------
| FileBird database tables
|--------------------------------------------------------------------------
*/

function pscore_fbe_get_tables() {
    global $wpdb;

    return [
        'folders'   => $wpdb->prefix . 'fbv',
        'relations' => $wpdb->prefix . 'fbv_attachment_folder',
    ];
}


/*
|--------------------------------------------------------------------------
| Check FileBird tables
|--------------------------------------------------------------------------
*/

function pscore_fbe_filebird_tables_exist() {
    global $wpdb;

    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $tables = pscore_fbe_get_tables();

    $folder_table = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($tables['folders'])
        )
    );

    $relation_table = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($tables['relations'])
        )
    );

    $exists =
        $folder_table === $tables['folders'] &&
        $relation_table === $tables['relations'];

    return $exists;
}


/*
|--------------------------------------------------------------------------
| Admin assets
|--------------------------------------------------------------------------
*/

function pscore_fbe_enqueue_admin_assets() {

    if (!current_user_can('upload_files')) {
        return;
    }

    if (!pscore_fbe_filebird_tables_exist()) {
        return;
    }

    wp_enqueue_style(
        'pscore-fbe-media-folders',
        PSCORE_FBE_URL . 'assets/media-folders.css',
        [],
        PSCORE_FBE_VERSION
    );

    wp_enqueue_script(
        'pscore-fbe-media-folders',
        PSCORE_FBE_URL . 'assets/media-folders.js',
        [
            'jquery',
            'media-views',
            'media-models',
            'wp-util',
        ],
        PSCORE_FBE_VERSION,
        true
    );

    wp_localize_script(
        'pscore-fbe-media-folders',
        'PSCOREFBE',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),

            'nonce' => wp_create_nonce(
                'pscore_fbe_media'
            ),

            'labels' => [
                'filebird'      => 'FileBird',
                'allMedia'      => 'All Files',
                'uncategorized' => 'Uncategorized',
                'loading'       => 'Loading folders…',
                'empty'         => 'No folders found.',
                'error'         => 'Could not load FileBird folders.',
            ],
        ]
    );
}

add_action(
    'admin_enqueue_scripts',
    'pscore_fbe_enqueue_admin_assets'
);


/*
|--------------------------------------------------------------------------
| AJAX security
|--------------------------------------------------------------------------
*/

function pscore_fbe_verify_ajax_request() {

    check_ajax_referer(
        'pscore_fbe_media',
        'nonce'
    );

    if (!current_user_can('upload_files')) {
        wp_send_json_error(
            [
                'message' => 'Permission denied.',
            ],
            403
        );
    }

    if (!pscore_fbe_filebird_tables_exist()) {
        wp_send_json_error(
            [
                'message' => 'FileBird tables were not found.',
            ],
            404
        );
    }
}


/*
|--------------------------------------------------------------------------
| Build FileBird folder tree
|--------------------------------------------------------------------------
*/

function pscore_fbe_build_folder_tree(
    array $folders,
    int $parent_id = 0
) {

    $branch = [];

    foreach ($folders as $folder) {

        if ((int) $folder['parent'] !== $parent_id) {
            continue;
        }

        $folder['children'] =
            pscore_fbe_build_folder_tree(
                $folders,
                (int) $folder['id']
            );

        $branch[] = $folder;
    }

    return $branch;
}


/*
|--------------------------------------------------------------------------
| AJAX: FileBird tree
|--------------------------------------------------------------------------
|
| Returns:
|
| - All Files count
| - Uncategorized count
| - FileBird folder hierarchy
| - Direct media count for each folder
|
| READ ONLY.
|
*/

function pscore_fbe_ajax_get_tree() {

    pscore_fbe_verify_ajax_request();

    global $wpdb;

    $tables = pscore_fbe_get_tables();

    $folder_table =
        $tables['folders'];

    $relation_table =
        $tables['relations'];


    /*
     * Load FileBird folders.
     */

    $folders = $wpdb->get_results(
        "
        SELECT
            id,
            name,
            parent,
            type,
            ord,
            created_by

        FROM {$folder_table}

        ORDER BY
            parent ASC,
            ord ASC,
            name ASC
        ",
        ARRAY_A
    );

    if (!is_array($folders)) {
        $folders = [];
    }


    /*
     * Count valid WordPress attachments
     * directly assigned to each FileBird folder.
     */

    $count_rows = $wpdb->get_results(
        "
        SELECT
            r.folder_id,
            COUNT(DISTINCT r.attachment_id) AS total

        FROM {$relation_table} r

        INNER JOIN {$wpdb->posts} p
            ON p.ID = r.attachment_id

        WHERE
            p.post_type = 'attachment'
            AND p.post_status != 'trash'

        GROUP BY
            r.folder_id
        ",
        ARRAY_A
    );


    $folder_counts = [];

    foreach ($count_rows as $row) {

        $folder_counts[
            (int) $row['folder_id']
        ] = (int) $row['total'];
    }


    /*
     * Normalize folder data.
     */

    foreach ($folders as &$folder) {

        $folder_id =
            (int) $folder['id'];

        $folder['id'] =
            $folder_id;

        $folder['parent'] =
            (int) $folder['parent'];

        $folder['type'] =
            (int) $folder['type'];

        $folder['ord'] =
            (int) $folder['ord'];

        $folder['created_by'] =
            (int) $folder['created_by'];

        $folder['name'] =
            sanitize_text_field(
                $folder['name']
            );

        $folder['count'] =
            $folder_counts[$folder_id] ?? 0;
    }

    unset($folder);


    /*
     * All WordPress media attachments.
     */

    $all_count =
        (int) $wpdb->get_var(
            "
            SELECT COUNT(*)

            FROM {$wpdb->posts}

            WHERE
                post_type = 'attachment'
                AND post_status != 'trash'
            "
        );


    /*
     * Attachments without a FileBird folder.
     */

    $uncategorized_count =
        (int) $wpdb->get_var(
            "
            SELECT COUNT(DISTINCT p.ID)

            FROM {$wpdb->posts} p

            LEFT JOIN {$relation_table} r
                ON r.attachment_id = p.ID

            WHERE
                p.post_type = 'attachment'
                AND p.post_status != 'trash'
                AND r.attachment_id IS NULL
            "
        );


    /*
     * Convert flat FileBird folder list
     * into parent/child hierarchy.
     */

    $tree =
        pscore_fbe_build_folder_tree(
            $folders
        );


    wp_send_json_success(
        [
            'tree' => $tree,

            'counts' => [
                'all' =>
                    $all_count,

                'uncategorized' =>
                    $uncategorized_count,
            ],
        ]
    );
}

add_action(
    'wp_ajax_pscore_fbe_get_tree',
    'pscore_fbe_ajax_get_tree'
);


/*
|--------------------------------------------------------------------------
| AJAX: Attachments in one FileBird folder
|--------------------------------------------------------------------------
|
| Returns attachment IDs directly assigned to the selected folder.
|
| READ ONLY.
|
*/

function pscore_fbe_ajax_get_attachments() {

    pscore_fbe_verify_ajax_request();


    $folder_id =
        isset($_POST['folder_id'])
            ? absint($_POST['folder_id'])
            : 0;


    if (!$folder_id) {

        wp_send_json_error(
            [
                'message' => 'Invalid folder ID.',
            ],
            400
        );
    }


    global $wpdb;

    $tables = pscore_fbe_get_tables();

    $folder_table =
        $tables['folders'];

    $relation_table =
        $tables['relations'];


    /*
     * Make sure the FileBird folder exists.
     */

    $folder = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT
                id,
                name,
                parent

            FROM {$folder_table}

            WHERE id = %d

            LIMIT 1
            ",
            $folder_id
        ),
        ARRAY_A
    );


    if (!$folder) {

        wp_send_json_error(
            [
                'message' => 'Folder not found.',
            ],
            404
        );
    }


    /*
     * Get valid attachments assigned to this folder.
     */

    $attachment_ids =
        $wpdb->get_col(
            $wpdb->prepare(
                "
                SELECT DISTINCT
                    r.attachment_id

                FROM {$relation_table} r

                INNER JOIN {$wpdb->posts} p
                    ON p.ID = r.attachment_id

                WHERE
                    r.folder_id = %d

                    AND p.post_type = 'attachment'

                    AND p.post_status != 'trash'

                ORDER BY
                    r.attachment_id DESC
                ",
                $folder_id
            )
        );


    $attachment_ids =
        array_values(
            array_map(
                'absint',
                $attachment_ids
            )
        );


    wp_send_json_success(
        [
            'folder' => [
                'id' =>
                    (int) $folder['id'],

                'name' =>
                    sanitize_text_field(
                        $folder['name']
                    ),

                'parent' =>
                    (int) $folder['parent'],
            ],

            'attachment_ids' =>
                $attachment_ids,

            'count' =>
                count($attachment_ids),
        ]
    );
}

add_action(
    'wp_ajax_pscore_fbe_get_attachments',
    'pscore_fbe_ajax_get_attachments'
);


/*
|--------------------------------------------------------------------------
| AJAX: Uncategorized attachments
|--------------------------------------------------------------------------
|
| Returns attachments which do not have any FileBird folder relation.
|
| READ ONLY.
|
*/

function pscore_fbe_ajax_get_uncategorized() {

    pscore_fbe_verify_ajax_request();

    global $wpdb;

    $tables = pscore_fbe_get_tables();

    $relation_table =
        $tables['relations'];


    $attachment_ids =
        $wpdb->get_col(
            "
            SELECT DISTINCT
                p.ID

            FROM {$wpdb->posts} p

            LEFT JOIN {$relation_table} r
                ON r.attachment_id = p.ID

            WHERE
                p.post_type = 'attachment'

                AND p.post_status != 'trash'

                AND r.attachment_id IS NULL

            ORDER BY
                p.ID DESC
            "
        );


    $attachment_ids =
        array_values(
            array_map(
                'absint',
                $attachment_ids
            )
        );


    wp_send_json_success(
        [
            'attachment_ids' =>
                $attachment_ids,

            'count' =>
                count($attachment_ids),
        ]
    );
}

add_action(
    'wp_ajax_pscore_fbe_get_uncategorized',
    'pscore_fbe_ajax_get_uncategorized'
);


/*
|--------------------------------------------------------------------------
| Admin notice
|--------------------------------------------------------------------------
*/

function pscore_fbe_admin_notice() {

    if (!current_user_can('manage_options')) {
        return;
    }

    if (pscore_fbe_filebird_tables_exist()) {
        return;
    }

    echo '<div class="notice notice-warning"><p>';

    echo '<strong>';
    echo esc_html(
        'PSCORE FileBird Elementor Builder Extension'
    );
    echo ':</strong> ';

    echo esc_html(
        'The FileBird database tables could not be found. '
        . 'The Elementor media folder extension is inactive.'
    );

    echo '</p></div>';
}

add_action(
    'admin_notices',
    'pscore_fbe_admin_notice'
);