<?php

defined('ABSPATH') || exit;

if (!defined('NEWSLETTER_DIR')) {
    return;
}

echo '<p><strong>Included content types:</strong> Posts, Newsletters, Testimonies, Interns, Memoirs, Reports and Media.</p>';

/* Reuse the current Newsletter Posts options to stay compatible with updates. */
ob_start();
include NEWSLETTER_DIR . '/emails/blocks/posts/options.php';
$pscore_options_html = ob_get_clean();

/* Remove the core block's upgrade notice; this block already supports CPTs. */
$pscore_options_html = preg_replace(
    '#<p>\s*Custom post types can be added.*?</p>#s',
    '',
    $pscore_options_html,
    1
);

echo $pscore_options_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

