<?php

defined('ABSPATH') || exit;

final class PSCORE_Newsletter_Addons_Quarterly {
    private const OPTION = 'pscore_newsletter_addons_quarterly';
    private const HOOK = 'pscore_newsletter_addons_quarterly_send';
    private const LOCK = 'pscore_newsletter_addons_quarterly_lock';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'admin_menu'], 99);
        add_action('init', [self::class, 'ensure_schedule']);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function ensure_schedule(): void {
        $settings = self::settings();
        if (!empty($settings['enabled']) && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(max(time() + 60, (int) $settings['next_send']), self::HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::HOOK);
        delete_transient(self::LOCK);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'newsletter_main_index',
            'Quarterly Newsletter',
            'Quarterly Newsletter',
            'manage_options',
            'pscore-newsletter-addons-quarterly',
            [self::class, 'render_page']
        );
    }

    private static function settings(): array {
        return wp_parse_args(get_option(self::OPTION, []), [
            'enabled' => 0,
            'source_email_id' => 0,
            'next_send' => 0,
            'last_success' => 0,
            'last_created_email_id' => 0,
            'last_message' => '',
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['pscore_newsletter_addons_quarterly_save'])) {
            check_admin_referer('pscore_newsletter_addons_quarterly_save');
            self::save_settings();
        }

        $settings = self::settings();
        $emails = self::available_emails();
        $next_send = '';
        if ($settings['next_send']) {
            $next_send = wp_date('Y-m-d\TH:i', (int) $settings['next_send'], wp_timezone());
        }
        ?>
        <div class="wrap">
            <h1>Quarterly Newsletter</h1>
            <p>This add-on creates a fresh copy of the selected newsletter every three calendar months and adds it to the normal Newsletter delivery queue.</p>

            <?php if (!class_exists('Newsletter')) : ?>
                <div class="notice notice-error"><p>The Newsletter plugin is not active.</p></div>
            <?php endif; ?>

            <?php if ($settings['last_message']) : ?>
                <div class="notice notice-info"><p><?php echo esc_html($settings['last_message']); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('pscore_newsletter_addons_quarterly_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable automatic sending</th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], 1); ?>> Send every three months</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source_email_id">Newsletter template</label></th>
                        <td>
                            <select id="source_email_id" name="source_email_id" required>
                                <option value="">Select a newsletter</option>
                                <?php foreach ($emails as $email) : ?>
                                    <option value="<?php echo esc_attr($email->id); ?>" <?php selected($settings['source_email_id'], $email->id); ?>>
                                        <?php echo esc_html('#' . $email->id . ' — ' . ($email->subject ?: 'No subject') . ' [' . $email->status . ']'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Recipients, language, tracking and list filters are copied from this newsletter.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="next_send">First/next send</label></th>
                        <td>
                            <input id="next_send" type="datetime-local" name="next_send" value="<?php echo esc_attr($next_send); ?>" required>
                            <p class="description">WordPress timezone: <?php echo esc_html(wp_timezone_string()); ?>. Following sends use the same local date and time every three months.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save schedule', 'primary', 'pscore_newsletter_addons_quarterly_save'); ?>
            </form>

            <h2>Status</h2>
            <p><strong>Next send:</strong> <?php echo $settings['next_send'] ? esc_html(wp_date('Y-m-d H:i', (int) $settings['next_send'], wp_timezone())) : 'Not scheduled'; ?></p>
            <p><strong>Last successful creation:</strong> <?php echo $settings['last_success'] ? esc_html(wp_date('Y-m-d H:i', (int) $settings['last_success'], wp_timezone())) : 'Never'; ?></p>
            <p><strong>Last generated newsletter ID:</strong> <?php echo $settings['last_created_email_id'] ? esc_html((string) $settings['last_created_email_id']) : 'None'; ?></p>
        </div>
        <?php
    }

    private static function available_emails(): array {
        if (!class_exists('Newsletter')) {
            return [];
        }

        $emails = Newsletter::instance()->get_emails();
        if (!is_array($emails)) {
            return [];
        }

        return array_values(array_filter($emails, static function ($email) {
            return $email->type === 'message'
                && !in_array($email->status, ['sending', 'error'], true);
        }));
    }

    private static function save_settings(): void {
        $settings = self::settings();
        $settings['enabled'] = empty($_POST['enabled']) ? 0 : 1;
        $settings['source_email_id'] = absint($_POST['source_email_id'] ?? 0);
        $raw_next_send = sanitize_text_field(wp_unslash($_POST['next_send'] ?? ''));

        try {
            if ($raw_next_send === '') {
                throw new InvalidArgumentException('Missing date');
            }
            $date = new DateTimeImmutable($raw_next_send, wp_timezone());
            $settings['next_send'] = $date->getTimestamp();
        } catch (Exception $exception) {
            $settings['enabled'] = 0;
            $settings['next_send'] = 0;
            $settings['last_message'] = 'The entered send date is invalid. Automatic sending remains disabled.';
        }

        $valid_source_ids = array_map(
            static fn($email): int => (int) $email->id,
            self::available_emails()
        );

        if (!$settings['source_email_id'] || !in_array($settings['source_email_id'], $valid_source_ids, true)) {
            $settings['enabled'] = 0;
            $settings['last_message'] = 'Please select a newsletter. Automatic sending remains disabled.';
        } elseif ($settings['enabled'] && $settings['next_send'] <= time()) {
            $settings['enabled'] = 0;
            $settings['last_message'] = 'The next send must be in the future. Automatic sending remains disabled.';
        } else {
            $settings['last_message'] = $settings['enabled']
                ? 'The quarterly schedule has been saved.'
                : 'The settings have been saved; automatic sending is disabled.';
        }

        update_option(self::OPTION, $settings, false);
        self::reschedule($settings);
    }

    private static function reschedule(array $settings): void {
        wp_clear_scheduled_hook(self::HOOK);

        if (!empty($settings['enabled']) && !empty($settings['next_send'])) {
            wp_schedule_single_event((int) $settings['next_send'], self::HOOK);
        }
    }

    private static function following_quarter(int $timestamp): int {
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone());
        do {
            $date = $date->modify('+3 months');
        } while ($date->getTimestamp() <= time());

        return $date->getTimestamp();
    }

    public static function run(): void {
        if (get_transient(self::LOCK)) {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::HOOK);
            }
            return;
        }
        set_transient(self::LOCK, 1, 15 * MINUTE_IN_SECONDS);

        $settings = self::settings();
        if (empty($settings['enabled']) || empty($settings['source_email_id'])) {
            delete_transient(self::LOCK);
            return;
        }

        $planned_send = (int) $settings['next_send'];
        $settings['next_send'] = self::following_quarter($planned_send ?: time());

        try {
            $created = self::create_delivery((int) $settings['source_email_id'], (int) $settings['last_success']);
            if (is_wp_error($created)) {
                $settings['last_message'] = 'Quarterly newsletter was not created: ' . $created->get_error_message();
            } else {
                $settings['last_success'] = time();
                $settings['last_created_email_id'] = (int) $created->id;
                $settings['last_message'] = 'Newsletter #' . $created->id . ' was created and added to the sending queue.';
            }
        } catch (Throwable $throwable) {
            $settings['last_message'] = 'Quarterly newsletter failed: ' . $throwable->getMessage();
        }

        update_option(self::OPTION, $settings, false);
        self::reschedule($settings);
        delete_transient(self::LOCK);
    }

    private static function create_delivery(int $source_id, int $last_success) {
    global $wpdb;

    if (!class_exists('Newsletter') || !defined('NEWSLETTER_USERS_TABLE')) {
        return new WP_Error(
            'newsletter_missing',
            'The Newsletter plugin is not active.'
        );
    }

    // Load the permanent newsletter template.
    $source = Newsletter::instance()->get_email($source_id);

    if (!$source) {
        return new WP_Error(
            'source_missing',
            'The selected newsletter no longer exists.'
        );
    }

    // Work on a clone. The selected template itself must never be modified.
    $email = clone $source;

    $email->id      = 0;
    $email->status  = TNP_Email::STATUS_DRAFT;
    $email->send_on = time();

    /*
     * Regenerate Composer content.
     *
     * Dynamic blocks such as the PSCORE posts block can intentionally stop
     * generation when there has been no new content since the previous run.
     *
     * For the quarterly newsletter this must NOT cancel the complete
     * newsletter. In that specific case we fall back to the content already
     * stored in the template.
     *
     * Other Composer errors are still treated as real errors.
     */
    if (
        (int) $email->editor === TNP_Email::EDITOR_COMPOSER
        && class_exists('NewsletterComposer')
    ) {
        // Save the original template content before regeneration.
        $fallback_message = $email->message;
        $fallback_message_text = $email->message_text;

        $regenerated = NewsletterComposer::instance()->regenerate(
            $email,
            [
                'type'     => 'automated',
                'last_run' => $last_success,
            ],
            true
        );

        if (is_wp_error($regenerated)) {
            $error_message = $regenerated->get_error_message();

            /*
             * Newsletter's dynamic blocks may return an error such as:
             * "The block number 4 stopped the generation"
             *
             * That means there is no new dynamic content. For our quarterly
             * newsletter we still want to send the newsletter.
             */
            if (stripos($error_message, 'stopped the generation') !== false) {
                $email->message = $fallback_message;
                $email->message_text = $fallback_message_text;
            } else {
                // Any other Composer error is a real failure.
                return $regenerated;
            }
        } elseif ($regenerated === false) {
            /*
             * Some Newsletter versions may signal a stopped generation with
             * false instead of WP_Error.
             */
            $email->message = $fallback_message;
            $email->message_text = $fallback_message_text;
        }
    }

    /*
     * Keep exactly the same recipient query as the template.
     */
    $query = !empty($source->query)
        ? $source->query
        : 'select * from ' . NEWSLETTER_USERS_TABLE . " where status='C'";

    /*
     * Convert the recipient SELECT query into a COUNT query.
     */
    $count_query = preg_replace(
        '/^\s*select\s+\*/i',
        'select count(*)',
        $query,
        1
    );

    $total = (int) $wpdb->get_var($count_query);

    if ($total < 1) {
        return new WP_Error(
            'no_recipients',
            'The selected newsletter has no matching recipients.'
        );
    }

    /*
     * Create a NEW newsletter.
     *
     * #4 remains the permanent draft/template.
     * This new newsletter gets its own ID and immediately enters the normal
     * Newsletter sending queue.
     */
    $new_email = [
        'language' => $source->language ?? '',
        'subject' => $email->subject,
        'message' => $email->message,
        'message_text' => $email->message_text,

        'status' => TNP_Email::STATUS_SENDING,

        'total' => $total,
        'last_id' => 0,
        'sent' => 0,

        'track' => (int) $email->track,

        'type' => 'message',
        'query' => $query,
        'editor' => (int) $email->editor,

        'send_on' => time(),

        'token' => wp_generate_password(24, false, false),

        'options' => $email->options,

        'private' => (int) ($source->private ?? 0),

        'updated' => time(),
    ];

    return Newsletter::instance()->save_email($new_email);
}
}
