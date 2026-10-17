<?php
/**
 * Plugin Name: PSCORE Newsletter Addons
 * Description: Adds PSCORE post types to the Newsletter Composer and provides quarterly automatic newsletter sending.
 * Version: 1.0.4
 * Author: PSCORE
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('PSCORE_NEWSLETTER_ADDONS_DIR', __DIR__);
define('PSCORE_NEWSLETTER_ADDONS_VERSION', '1.0.4');

require_once PSCORE_NEWSLETTER_ADDONS_DIR . '/includes/class-quarterly-newsletter.php';

/* Force browsers and proxy caches to load the current PSCORE Posts icon. */
add_filter('content_url', static function ($url, $path) {
    $icon_path = '/blocks/pscore-posts/icon.png';
    if (substr((string) $path, -strlen($icon_path)) === $icon_path) {
        return add_query_arg('ver', PSCORE_NEWSLETTER_ADDONS_VERSION, $url);
    }

    return $url;
}, 10, 2);

add_action('newsletter_register_blocks', static function () {
    if (function_exists('tnp_register_block')) {
        tnp_register_block(PSCORE_NEWSLETTER_ADDONS_DIR . '/blocks/pscore-posts');
    }
});

PSCORE_Newsletter_Addons_Quarterly::init();

register_activation_hook(__FILE__, static function () {
    PSCORE_Newsletter_Addons_Quarterly::ensure_schedule();
});

register_deactivation_hook(__FILE__, static function () {
    PSCORE_Newsletter_Addons_Quarterly::deactivate();
});
