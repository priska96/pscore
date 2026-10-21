<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Scheduler {
    public const HOOK = 'pscore_nl_process';
    public const LOCK = 'pscore_nl_process_lock';

    public static function init(): void {
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'pscore_nl_five_minutes', self::HOOK);
        }
    }

    public static function run(): void {
        if (get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, 4 * MINUTE_IN_SECONDS);
        try {
            self::create_due_queues();
            self::send_batch();
        } finally {
            delete_transient(self::LOCK);
        }
    }

    private static function create_due_queues(): void {
        $now = time();
        $campaigns = get_posts([
            'post_type' => PSCORE_NL_Editor::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'meta_value_num',
            'meta_key' => '_pscore_nl_next_run',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_pscore_nl_schedule_status', 'value' => 'active'],
                ['key' => '_pscore_nl_next_run', 'value' => [1, $now], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'],
            ],
        ]);

        foreach ($campaigns as $campaign) {
            $run_key = (int) get_post_meta($campaign->ID, '_pscore_nl_next_run', true);
            $frequency = get_post_meta($campaign->ID, '_pscore_nl_frequency', true) ?: 'once';
            self::queue_campaign($campaign, $run_key);
            update_post_meta($campaign->ID, '_pscore_nl_last_run', $now);
            update_post_meta($campaign->ID, '_pscore_nl_next_run', self::next_run($frequency, $run_key));
            update_post_meta($campaign->ID, '_pscore_nl_schedule_status', $frequency === 'once' ? 'completed' : 'active');
        }
    }

    private static function queue_campaign(WP_Post $campaign, int $run_key): void {
        global $wpdb;
        $list_ids = array_filter(array_map('absint', (array) get_post_meta($campaign->ID, '_pscore_nl_list_ids', true)));
        if (!$list_ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($list_ids), '%d'));
        $sql = "SELECT DISTINCT s.id
            FROM " . PSCORE_NL_Plugin::table('subscribers') . " s
            INNER JOIN " . PSCORE_NL_Plugin::table('subscriber_lists') . " sl ON sl.subscriber_id=s.id
            WHERE s.status='confirmed' AND sl.status='confirmed' AND sl.list_id IN ($placeholders)";
        $subscriber_ids = $wpdb->get_col($wpdb->prepare($sql, ...$list_ids));
        $queue = PSCORE_NL_Plugin::table('queue');
        foreach ($subscriber_ids as $subscriber_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $queue (campaign_id, subscriber_id, run_key, status, attempts, scheduled_at, sent_at, error)
                VALUES (%d, %d, %d, 'pending', 0, %d, 0, '')",
                $campaign->ID,
                $subscriber_id,
                $run_key,
                time()
            ));
        }
    }

    private static function send_batch(): void {
        global $wpdb;
        $settings = PSCORE_NL_Plugin::settings();
        $limit = min(100, max(1, absint($settings['batch_size'])));
        $queue = PSCORE_NL_Plugin::table('queue');
        $subscribers = PSCORE_NL_Plugin::table('subscribers');
        $wpdb->query("UPDATE $queue q INNER JOIN $subscribers s ON s.id=q.subscriber_id SET q.status='cancelled', q.error='Subscriber is no longer confirmed' WHERE q.status='pending' AND s.status<>'confirmed'");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, s.email, s.name, s.first_name, s.last_name, s.unsubscribe_token
            FROM $queue q
            INNER JOIN $subscribers s ON s.id=q.subscriber_id
            WHERE q.status='pending' AND q.scheduled_at<=%d AND s.status='confirmed'
            ORDER BY q.id ASC LIMIT %d",
            time(),
            $limit
        ));

        foreach ($rows as $row) {
            $campaign = get_post((int) $row->campaign_id);
            if (!$campaign || $campaign->post_type !== PSCORE_NL_Editor::POST_TYPE) {
                $wpdb->update($queue, ['status' => 'failed', 'error' => 'Newsletter no longer exists'], ['id' => $row->id]);
                continue;
            }
            $wpdb->update($queue, ['status' => 'processing', 'attempts' => (int) $row->attempts + 1], ['id' => $row->id]);
            $raw_subject = get_post_meta($campaign->ID, '_pscore_nl_subject', true) ?: $campaign->post_title;
            $subject = PSCORE_NL_Plugin::replace_tags($raw_subject, $row);
            $has_unsubscribe_link = strpos($campaign->post_content, '{unsubscribe_url}') !== false;
            $content = apply_filters('the_content', $campaign->post_content);
            $content = PSCORE_NL_Plugin::replace_tags($content, $row);
            if (!$has_unsubscribe_link) {
                $url = esc_url(add_query_arg('pscore_nl_unsubscribe', rawurlencode($row->unsubscribe_token), home_url('/')));
                $content .= '<hr><p style="font-size:12px;color:#666"><a href="' . $url . '">Unsubscribe</a></p>';
            }
            $custom_css = (string) get_post_meta($campaign->ID, '_pscore_nl_custom_css', true);
            $body = '<div class="pscore-newsletter-email" style="max-width:680px;margin:0 auto;font-family:Arial,sans-serif;line-height:1.6">' . $content . '</div>';
            $body = PSCORE_NL_CSS_Inliner::inline($body, $custom_css);
            $style = $custom_css !== '' ? '<style type="text/css">' . $custom_css . '</style>' : '';
            $content = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $style . '</head><body>' . $body . '</body></html>';
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . sanitize_text_field($settings['from_name']) . ' <' . sanitize_email($settings['from_email']) . '>',
            ];
            $sent = wp_mail($row->email, $subject, $content, $headers);
            $wpdb->update($queue, $sent ? [
                'status' => 'sent',
                'sent_at' => time(),
                'error' => '',
            ] : [
                'status' => ((int) $row->attempts + 1 >= 3) ? 'failed' : 'pending',
                'scheduled_at' => time() + 15 * MINUTE_IN_SECONDS,
                'error' => 'wp_mail() returned false',
            ], ['id' => $row->id]);
        }
    }

    private static function next_run(string $frequency, int $from): int {
        if ($frequency === 'once') {
            return 0;
        }
        $date = (new DateTimeImmutable('@' . $from))->setTimezone(wp_timezone());
        $modifier = [
            'weekly' => '+1 week',
            'monthly' => '+1 month',
            'quarterly' => '+3 months',
        ][$frequency] ?? '+1 month';
        do {
            $date = $date->modify($modifier);
        } while ($date->getTimestamp() <= time());
        return $date->getTimestamp();
    }
}

