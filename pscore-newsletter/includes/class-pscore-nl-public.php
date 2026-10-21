<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Public {
    public static function init(): void {
        add_shortcode('pscore_newsletter_form', [self::class, 'shortcode']);
        add_action('admin_post_nopriv_pscore_nl_subscribe', [self::class, 'subscribe']);
        add_action('admin_post_pscore_nl_subscribe', [self::class, 'subscribe']);
        add_action('template_redirect', [self::class, 'token_actions']);
    }

    public static function shortcode(array $atts): string {
        global $wpdb;
        $atts = shortcode_atts(['id' => 1], $atts, 'pscore_newsletter_form');
        $form = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . PSCORE_NL_Plugin::table('forms') . ' WHERE id=%d',
            absint($atts['id'])
        ));
        if (!$form) {
            return current_user_can('manage_options') ? '<p>PSCORE Newsletter form not found.</p>' : '';
        }

        $state = sanitize_key(wp_unslash($_GET['pscore_nl'] ?? ''));
        ob_start();
        ?>
        <div class="pscore-newsletter-form-wrap">
            <?php if ($state === 'success') : ?>
                <p class="pscore-newsletter-message success"><?php echo esc_html($form->success_message); ?></p>
            <?php elseif ($state === 'error') : ?>
                <p class="pscore-newsletter-message error">Please enter a valid email address and try again.</p>
            <?php endif; ?>
            <form class="pscore-newsletter-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pscore_nl_subscribe">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($form->id); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(self::current_url()); ?>">
                <?php wp_nonce_field('pscore_nl_subscribe_' . $form->id, 'pscore_nl_nonce'); ?>
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <?php if ($form->title) : ?><h3><?php echo esc_html($form->title); ?></h3><?php endif; ?>
                <?php if ($form->description) : ?><div><?php echo wp_kses_post(wpautop($form->description)); ?></div><?php endif; ?>
                <?php $name_mode = $form->name_mode ?? ($form->show_name ? 'full' : 'none'); ?>
                <?php if ($name_mode === 'full') : ?>
                    <p><label><?php echo esc_html($form->full_name_label ?: 'Full name'); ?><br><input type="text" name="subscriber_name" autocomplete="name"></label></p>
                <?php elseif ($name_mode === 'separate') : ?>
                    <p><label><?php echo esc_html($form->first_name_label ?: 'First name'); ?><br><input type="text" name="subscriber_first_name" autocomplete="given-name"></label></p>
                    <p><label><?php echo esc_html($form->last_name_label ?: 'Last name'); ?><br><input type="text" name="subscriber_last_name" autocomplete="family-name"></label></p>
                <?php endif; ?>
                <p><label><?php echo esc_html($form->email_label ?: 'Email'); ?><br><input type="email" name="subscriber_email" autocomplete="email" required></label></p>
                <p><label><input type="checkbox" name="consent" value="1" required> I agree to receive the selected emails and can unsubscribe at any time.</label></p>
                <p><button type="submit"><?php echo esc_html($form->button_label); ?></button></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function subscribe(): void {
        global $wpdb;
        $form_id = absint($_POST['form_id'] ?? 0);
        $redirect = wp_validate_redirect(wp_unslash($_POST['redirect_to'] ?? ''), home_url('/'));
        if (!empty($_POST['website'])) {
            wp_safe_redirect(add_query_arg('pscore_nl', 'success', $redirect));
            exit;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pscore_nl_nonce'] ?? '')), 'pscore_nl_subscribe_' . $form_id)) {
            wp_die('Invalid subscription request.', 403);
        }

        $email = sanitize_email(wp_unslash($_POST['subscriber_email'] ?? ''));
        $first_name = sanitize_text_field(wp_unslash($_POST['subscriber_first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['subscriber_last_name'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['subscriber_name'] ?? ''));
        if (!$name && ($first_name || $last_name)) {
            $name = trim($first_name . ' ' . $last_name);
        }
        if (!is_email($email) || empty($_POST['consent'])) {
            wp_safe_redirect(add_query_arg('pscore_nl', 'error', $redirect));
            exit;
        }

        $form = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . PSCORE_NL_Plugin::table('forms') . ' WHERE id=%d', $form_id));
        if (!$form) {
            wp_safe_redirect(add_query_arg('pscore_nl', 'error', $redirect));
            exit;
        }

        $settings = PSCORE_NL_Plugin::settings();
        $subscribers = PSCORE_NL_Plugin::table('subscribers');
        $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subscribers WHERE email=%s", $email));
        $confirm_token = wp_generate_password(48, false, false);
        $unsubscribe_token = $subscriber ? $subscriber->unsubscribe_token : wp_generate_password(48, false, false);
        $status = $settings['double_opt_in'] ? 'pending' : 'confirmed';

        if ($subscriber) {
            $wpdb->update($subscribers, [
                'name' => $name ?: $subscriber->name,
                'first_name' => $first_name ?: $subscriber->first_name,
                'last_name' => $last_name ?: $subscriber->last_name,
                'status' => $status,
                'confirm_token' => $confirm_token,
                'confirmed_at' => $status === 'confirmed' ? current_time('mysql') : null,
            ], ['id' => $subscriber->id]);
            $subscriber_id = (int) $subscriber->id;
        } else {
            $wpdb->insert($subscribers, [
                'email' => $email,
                'name' => $name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'status' => $status,
                'confirm_token' => $confirm_token,
                'unsubscribe_token' => $unsubscribe_token,
                'created_at' => current_time('mysql'),
                'confirmed_at' => $status === 'confirmed' ? current_time('mysql') : null,
            ]);
            $subscriber_id = (int) $wpdb->insert_id;
        }

        $list_ids = array_filter(array_map('absint', (array) json_decode($form->list_ids, true)));
        foreach ($list_ids as $list_id) {
            $wpdb->replace(PSCORE_NL_Plugin::table('subscriber_lists'), [
                'subscriber_id' => $subscriber_id,
                'list_id' => $list_id,
                'status' => $status,
                'created_at' => current_time('mysql'),
            ]);
        }

        if ($settings['double_opt_in']) {
            $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM $subscribers WHERE id=%d", $subscriber_id));
            $confirmation_url = add_query_arg('pscore_nl_confirm', rawurlencode($confirm_token), home_url('/'));
            $body = PSCORE_NL_Plugin::replace_tags($settings['confirmation_text'], $subscriber, ['{confirmation_url}' => $confirmation_url]);
            $headers = ['From: ' . sanitize_text_field($settings['from_name']) . ' <' . sanitize_email($settings['from_email']) . '>'];
            wp_mail($email, $settings['confirmation_subject'], $body, $headers);
        }

        wp_safe_redirect(add_query_arg('pscore_nl', 'success', $redirect));
        exit;
    }

    public static function token_actions(): void {
        global $wpdb;
        if (isset($_GET['pscore_nl_confirm'])) {
            $token = sanitize_text_field(wp_unslash($_GET['pscore_nl_confirm']));
            $subscriber = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . PSCORE_NL_Plugin::table('subscribers') . ' WHERE confirm_token=%s', $token));
            if (!$subscriber) {
                wp_die('This confirmation link is invalid or expired.', 'Subscription confirmation', ['response' => 400]);
            }
            $wpdb->update(PSCORE_NL_Plugin::table('subscribers'), ['status' => 'confirmed', 'confirmed_at' => current_time('mysql')], ['id' => $subscriber->id]);
            $wpdb->update(PSCORE_NL_Plugin::table('subscriber_lists'), ['status' => 'confirmed'], ['subscriber_id' => $subscriber->id]);
            wp_die('Your subscription has been confirmed.', 'Subscription confirmed', ['response' => 200]);
        }

        if (isset($_GET['pscore_nl_unsubscribe'])) {
            $token = sanitize_text_field(wp_unslash($_GET['pscore_nl_unsubscribe']));
            $subscriber = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . PSCORE_NL_Plugin::table('subscribers') . ' WHERE unsubscribe_token=%s', $token));
            if (!$subscriber) {
                wp_die('This unsubscribe link is invalid.', 'Unsubscribe', ['response' => 400]);
            }
            $wpdb->update(PSCORE_NL_Plugin::table('subscribers'), ['status' => 'unsubscribed'], ['id' => $subscriber->id]);
            $wpdb->update(PSCORE_NL_Plugin::table('subscriber_lists'), ['status' => 'unsubscribed'], ['subscriber_id' => $subscriber->id]);
            wp_die('You have been unsubscribed.', 'Unsubscribed', ['response' => 200]);
        }
    }

    private static function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        return $scheme . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    }
}

