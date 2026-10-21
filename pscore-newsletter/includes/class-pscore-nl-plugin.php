<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Plugin {
    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('plugins_loaded', ['PSCORE_NL_Install', 'maybe_upgrade']);
        add_action('init', ['PSCORE_NL_Public', 'init']);
        add_action('init', ['PSCORE_NL_Editor', 'init']);
        add_action('init', ['PSCORE_NL_Scheduler', 'ensure_schedule']);
        PSCORE_NL_Scheduler::init();

        if (is_admin()) {
            PSCORE_NL_Admin::init();
            if (!class_exists('DOMDocument')) {
                add_action('admin_notices', static function (): void {
                    echo '<div class="notice notice-error"><p><strong>PSCORE Newsletter:</strong> The PHP DOM extension is missing. Custom email CSS cannot be converted to Gmail-safe inline styles until the hosting provider enables <code>ext-dom</code>.</p></div>';
                });
            }
        }
    }

    public static function cron_schedules(array $schedules): array {
        $schedules['pscore_nl_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Every five minutes (PSCORE Newsletter)',
        ];
        return $schedules;
    }

    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'pscore_nl_' . $name;
    }

    public static function settings(): array {
        return wp_parse_args(get_option('pscore_nl_settings', []), [
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'batch_size' => 20,
            'double_opt_in' => 1,
            'confirmation_subject' => 'Please confirm your subscription',
            'confirmation_text' => "Hello {name},\n\nplease confirm your subscription:\n{confirmation_url}\n\n{site_name}",
        ]);
    }

    public static function replace_tags(string $text, object $subscriber, array $extra = []): string {
        $tags = array_merge([
            '{name}' => $subscriber->name ?: '',
            '{first_name}' => $subscriber->first_name ?? '',
            '{last_name}' => $subscriber->last_name ?? '',
            '{email}' => $subscriber->email,
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url('/'),
            '{unsubscribe_url}' => add_query_arg('pscore_nl_unsubscribe', rawurlencode($subscriber->unsubscribe_token), home_url('/')),
        ], $extra);
        return strtr($text, $tags);
    }
}

