<?php
/**
 * Read-only WordPress URL diagnostic.
 * This script does NOT modify the database.
 */

require_once __DIR__ . '/wp-load.php';

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    status_header( 403 );
    exit( '403 - Administrator login required.' );
}

global $wpdb;

// Read the values DIRECTLY from wp_options.
// This is important because get_option() can be affected by
// WP_HOME / WP_SITEURL constants.
$db_home = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value
         FROM {$wpdb->options}
         WHERE option_name = %s
         LIMIT 1",
        'home'
    )
);

$db_siteurl = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value
         FROM {$wpdb->options}
         WHERE option_name = %s
         LIMIT 1",
        'siteurl'
    )
);

header( 'Content-Type: text/plain; charset=utf-8' );

echo "========================================\n";
echo "DATABASE VALUES (wp_options)\n";
echo "========================================\n\n";

echo "home:    " . $db_home . "\n";
echo "siteurl: " . $db_siteurl . "\n\n";


echo "========================================\n";
echo "EFFECTIVE WORDPRESS VALUES\n";
echo "========================================\n\n";

echo "home_url(): " . home_url() . "\n";
echo "site_url(): " . site_url() . "\n\n";


echo "========================================\n";
echo "WP-CONFIG OVERRIDES\n";
echo "========================================\n\n";

echo "WP_HOME:    ";
echo defined( 'WP_HOME' ) ? WP_HOME : 'NOT DEFINED';
echo "\n";

echo "WP_SITEURL: ";
echo defined( 'WP_SITEURL' ) ? WP_SITEURL : 'NOT DEFINED';
echo "\n\n";


echo "========================================\n";
echo "DATABASE INFO\n";
echo "========================================\n\n";

echo "Options table: " . $wpdb->options . "\n";
echo "Table prefix:  " . $wpdb->prefix . "\n\n";


echo "========================================\n";
echo "READ-ONLY CHECK COMPLETE\n";
echo "========================================\n";

echo "\nNo database values were changed.\n";