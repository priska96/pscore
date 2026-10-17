<?php
/**
 * READ-ONLY Oxilab inspector
 * Does NOT modify the database.
 */

require_once __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    status_header( 403 );
    exit( '403 - Administrator login required.' );
}

global $wpdb;

header( 'Content-Type: text/plain; charset=utf-8' );

$tables = [
    $wpdb->prefix . 'oxi_div_list',
    $wpdb->prefix . 'oxi_div_style',
    $wpdb->prefix . 'oxi_div_import',
];

echo "========================================\n";
echo "OXILAB TABLE INSPECTOR - READ ONLY\n";
echo "========================================\n\n";

foreach ( $tables as $table ) {

    echo "\n========================================\n";
    echo "TABLE: {$table}\n";
    echo "========================================\n\n";

    // Structure
    $columns = $wpdb->get_results("DESCRIBE `{$table}`");

    if ( empty($columns) ) {
        echo "Could not read table.\n";
        continue;
    }

    echo "COLUMNS:\n\n";

    foreach ( $columns as $column ) {
        echo sprintf(
            "%-25s %-25s\n",
            $column->Field,
            $column->Type
        );
    }

    // Row count
    $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

    echo "\nROW COUNT: {$count}\n";

    // Show first 10 rows, but truncate large fields.
    echo "\nFIRST 10 ROWS:\n\n";

    $rows = $wpdb->get_results(
        "SELECT * FROM `{$table}` LIMIT 10",
        ARRAY_A
    );

    foreach ( $rows as $index => $row ) {

        echo "---- ROW " . ($index + 1) . " ----\n";

        foreach ( $row as $key => $value ) {

            if ( is_null($value) ) {
                $value = 'NULL';
            }

            $length = strlen((string)$value);

            if ( $length > 500 ) {
                $preview = substr((string)$value, 0, 500);

                echo "{$key} [{$length} chars]:\n";
                echo $preview . "\n...[TRUNCATED]\n";
            } else {
                echo "{$key}: {$value}\n";
            }
        }

        echo "\n";
    }
}

echo "\n========================================\n";
echo "READ-ONLY CHECK COMPLETE\n";
echo "========================================\n";

echo "\nNothing was changed.\n";