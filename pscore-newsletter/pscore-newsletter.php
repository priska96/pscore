<?php
/**
 * Plugin Name: PSCORE Newsletter
 * Description: Simple, independent newsletter subscriptions, lists, forms and scheduled email campaigns for PSCORE.
 * Version: 1.3.3
 * Author: PSCORE
 * License: GPL-2.0-or-later
 * Text Domain: pscore-newsletter
 */

defined('ABSPATH') || exit;

define('PSCORE_NL_VERSION', '1.3.3');
define('PSCORE_NL_FILE', __FILE__);
define('PSCORE_NL_DIR', __DIR__);

require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-install.php';
require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-plugin.php';
require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-public.php';
require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-scheduler.php';
require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-editor.php';
require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-css-inliner.php';

if (is_admin()) {
    require_once PSCORE_NL_DIR . '/includes/class-pscore-nl-admin.php';
}

register_activation_hook(__FILE__, ['PSCORE_NL_Install', 'activate']);
register_deactivation_hook(__FILE__, ['PSCORE_NL_Install', 'deactivate']);

PSCORE_NL_Plugin::init();

