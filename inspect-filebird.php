<?php
/**
 * PSCORE FileBird Inspector
 *
 * READ ONLY.
 * Does not INSERT, UPDATE or DELETE anything.
 * Delete this file after inspection.
 */

require_once __DIR__ . '/wp-load.php';

if (
    !is_user_logged_in() ||
    !current_user_can('manage_options')
) {
    status_header(403);
    exit('403 Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

global $wpdb;

echo "========================================\n";
echo "PSCORE FILEBIRD READ-ONLY INSPECTOR\n";
echo "========================================\n\n";

echo "Generated: " . current_time('mysql') . "\n";
echo "WordPress: " . get_bloginfo('version') . "\n";
echo "Table prefix: " . $wpdb->prefix . "\n\n";


/*
|--------------------------------------------------------------------------
| 1. ACTIVE PLUGINS
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "1. FILEBIRD PLUGIN INFORMATION\n";
echo "========================================\n\n";

$active_plugins = (array) get_option('active_plugins', []);

$filebird_plugins = [];

foreach ($active_plugins as $plugin) {
    if (
        stripos($plugin, 'filebird') !== false ||
        stripos($plugin, 'njt') !== false
    ) {
        $filebird_plugins[] = $plugin;
    }
}

if ($filebird_plugins) {

    foreach ($filebird_plugins as $plugin) {
        echo "Active plugin: {$plugin}\n";

        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;

        if (file_exists($plugin_file)) {

            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $data = get_plugin_data($plugin_file, false, false);

            echo "Name: " . ($data['Name'] ?? '') . "\n";
            echo "Version: " . ($data['Version'] ?? '') . "\n";
        }

        echo "\n";
    }

} else {
    echo "No active FileBird plugin detected by filename.\n\n";
}


/*
|--------------------------------------------------------------------------
| 2. DATABASE TABLES
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "2. POSSIBLE FILEBIRD DATABASE TABLES\n";
echo "========================================\n\n";

$tables = $wpdb->get_col("SHOW TABLES");

$matching_tables = [];

foreach ($tables as $table) {

    if (
        stripos($table, 'filebird') !== false ||
        stripos($table, 'fbv') !== false ||
        stripos($table, 'njt') !== false ||
        stripos($table, 'folder') !== false
    ) {
        $matching_tables[] = $table;
    }
}

if (!$matching_tables) {

    echo "No obvious FileBird/folder tables found.\n\n";

} else {

    foreach ($matching_tables as $table) {

        echo "----------------------------------------\n";
        echo "TABLE: {$table}\n";
        echo "----------------------------------------\n";

        /*
         * Table name comes directly from SHOW TABLES,
         * not from user input.
         */
        $columns = $wpdb->get_results("DESCRIBE `{$table}`");

        echo "\nCOLUMNS:\n";

        foreach ($columns as $column) {
            echo "- {$column->Field}";
            echo " | {$column->Type}";
            echo " | Null: {$column->Null}";
            echo " | Key: {$column->Key}";
            echo "\n";
        }

        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );

        echo "\nROW COUNT: {$count}\n";

        /*
         * Show only a few sample rows.
         */
        $rows = $wpdb->get_results(
            "SELECT * FROM `{$table}` LIMIT 10",
            ARRAY_A
        );

        echo "\nSAMPLE ROWS (max 10):\n";

        if (!$rows) {

            echo "(empty)\n";

        } else {

            foreach ($rows as $index => $row) {

                echo "\nRow " . ($index + 1) . ":\n";

                foreach ($row as $key => $value) {

                    if (is_null($value)) {
                        $value = 'NULL';
                    }

                    /*
                     * Avoid dumping huge values.
                     */
                    if (is_string($value) && strlen($value) > 500) {
                        $value = substr($value, 0, 500)
                            . '...[TRUNCATED]';
                    }

                    echo "  {$key}: {$value}\n";
                }
            }
        }

        echo "\n";
    }
}


/*
|--------------------------------------------------------------------------
| 3. REGISTERED TAXONOMIES
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "3. POSSIBLE FILEBIRD TAXONOMIES\n";
echo "========================================\n\n";

$taxonomies = get_taxonomies([], 'objects');

$matching_taxonomies = [];

foreach ($taxonomies as $taxonomy) {

    $haystack =
        $taxonomy->name . ' ' .
        ($taxonomy->label ?? '');

    if (
        stripos($haystack, 'filebird') !== false ||
        stripos($haystack, 'folder') !== false ||
        stripos($haystack, 'fbv') !== false ||
        stripos($haystack, 'njt') !== false
    ) {
        $matching_taxonomies[] = $taxonomy;
    }
}

if (!$matching_taxonomies) {

    echo "No obvious FileBird/folder taxonomy found.\n\n";

} else {

    foreach ($matching_taxonomies as $taxonomy) {

        echo "----------------------------------------\n";
        echo "TAXONOMY: {$taxonomy->name}\n";
        echo "----------------------------------------\n";

        echo "Label: " . ($taxonomy->label ?? '') . "\n";

        echo "Object types: ";

        if (!empty($taxonomy->object_type)) {
            echo implode(', ', $taxonomy->object_type);
        }

        echo "\n";
        echo "Hierarchical: "
            . ($taxonomy->hierarchical ? 'YES' : 'NO')
            . "\n";

        $terms = get_terms([
            'taxonomy'   => $taxonomy->name,
            'hide_empty' => false,
            'number'     => 20,
        ]);

        echo "\nTERMS (max 20):\n";

        if (is_wp_error($terms)) {

            echo "Error reading terms.\n";

        } elseif (!$terms) {

            echo "(none)\n";

        } else {

            foreach ($terms as $term) {

                echo "- ID {$term->term_id}";
                echo " | Name: {$term->name}";
                echo " | Parent: {$term->parent}";
                echo " | Count: {$term->count}";
                echo "\n";
            }
        }

        echo "\n";
    }
}


/*
|--------------------------------------------------------------------------
| 4. OPTIONS
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "4. POSSIBLE FILEBIRD OPTIONS\n";
echo "========================================\n\n";

$options = $wpdb->get_results(
    "
    SELECT option_name, LENGTH(option_value) AS value_length
    FROM {$wpdb->options}
    WHERE
        option_name LIKE '%filebird%'
        OR option_name LIKE '%fbv%'
        OR option_name LIKE '%njt%'
    ORDER BY option_name
    LIMIT 100
    "
);

if (!$options) {

    echo "No obvious FileBird options found.\n\n";

} else {

    foreach ($options as $option) {
        echo "- {$option->option_name}";
        echo " | length: {$option->value_length}";
        echo "\n";
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 5. FILEBIRD-RELATED POSTMETA KEYS
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "5. POSSIBLE FILEBIRD POSTMETA KEYS\n";
echo "========================================\n\n";

$meta_keys = $wpdb->get_results(
    "
    SELECT meta_key, COUNT(*) AS total
    FROM {$wpdb->postmeta}
    WHERE
        meta_key LIKE '%filebird%'
        OR meta_key LIKE '%fbv%'
        OR meta_key LIKE '%njt%'
        OR meta_key LIKE '%folder%'
    GROUP BY meta_key
    ORDER BY total DESC
    LIMIT 100
    "
);

if (!$meta_keys) {

    echo "No obvious FileBird postmeta keys found.\n\n";

} else {

    foreach ($meta_keys as $meta) {
        echo "- {$meta->meta_key}";
        echo " | rows: {$meta->total}";
        echo "\n";
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 6. FILEBIRD CLASSES / API
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "6. FILEBIRD PHP CLASSES\n";
echo "========================================\n\n";

$known_classes = [
    'FileBird\\Classes\\Tree',
    'FileBird\\Classes\\Helpers',
    'FileBird\\Classes\\Folder',
    'FileBird\\Classes\\Attachment',
    'FileBird\\Model\\Folder',
    'FileBird\\Model\\FolderModel',
];

foreach ($known_classes as $class) {

    echo $class . ': ';

    if (class_exists($class)) {

        echo "FOUND\n";

        try {

            $reflection = new ReflectionClass($class);

            echo "  File: ";

            $filename = $reflection->getFileName();

            if ($filename) {

                /*
                 * Don't expose the complete server path.
                 */
                $relative = str_replace(
                    ABSPATH,
                    '[ABSPATH]/',
                    $filename
                );

                echo $relative;

            } else {
                echo '(unknown)';
            }

            echo "\n";

            echo "  Public static methods:\n";

            foreach ($reflection->getMethods(
                ReflectionMethod::IS_PUBLIC
            ) as $method) {

                if ($method->isStatic()) {
                    echo "    - {$method->name}()\n";
                }
            }

        } catch (Throwable $e) {

            echo "  Reflection error\n";
        }

    } else {

        echo "not found\n";
    }

    echo "\n";
}


/*
|--------------------------------------------------------------------------
| 7. ATTACHMENT/FOLDER RELATION SAMPLE
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "7. ATTACHMENT RELATIONSHIP CLUES\n";
echo "========================================\n\n";

/*
 * For every detected custom table, see whether common
 * attachment/folder column names exist.
 */

foreach ($matching_tables as $table) {

    $columns = $wpdb->get_col(
        "SHOW COLUMNS FROM `{$table}`",
        0
    );

    $interesting = [];

    foreach ($columns as $column) {

        if (
            stripos($column, 'attachment') !== false ||
            stripos($column, 'post') !== false ||
            stripos($column, 'folder') !== false ||
            $column === 'id' ||
            $column === 'parent'
        ) {
            $interesting[] = $column;
        }
    }

    if ($interesting) {

        echo "TABLE: {$table}\n";
        echo "Potential relationship columns:\n";

        foreach ($interesting as $column) {
            echo "- {$column}\n";
        }

        echo "\n";
    }
}


echo "========================================\n";
echo "DONE\n";
echo "========================================\n";
echo "\n";
echo "This inspector made NO database changes.\n";
echo "Delete inspect-filebird.php after use.\n";