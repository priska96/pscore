<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Editor {
    public const POST_TYPE = 'pscore_email';
    private const BASE_CONTENT_TYPES = ['post', 'newsletter', 'testimony', 'intern', 'memoir', 'report', 'media'];

    public static function init(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Newsletters',
                'singular_name' => 'Newsletter',
                'add_new_item' => 'Add newsletter',
                'edit_item' => 'Edit newsletter',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'pscore-newsletter',
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'revisions'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        wp_register_script(
            'pscore-nl-posts-block',
            plugins_url('../assets/posts-block.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-i18n'],
            PSCORE_NL_VERSION,
            true
        );
        register_block_type('pscore-newsletter/posts', [
            'api_version' => 2,
            'editor_script' => 'pscore-nl-posts-block',
            'render_callback' => [self::class, 'render_posts_block'],
            'attributes' => [
                'postType' => ['type' => 'string', 'default' => 'post'],
                'postsToShow' => ['type' => 'number', 'default' => 2],
                'showImage' => ['type' => 'boolean', 'default' => true],
                'showDate' => ['type' => 'boolean', 'default' => true],
                'showExcerpt' => ['type' => 'boolean', 'default' => false],
                'buttonLabel' => ['type' => 'string', 'default' => 'Read more...'],
                'imageWidth' => ['type' => 'number', 'default' => 52],
                'contentPadding' => ['type' => 'number', 'default' => 20],
                'rowSpacing' => ['type' => 'number', 'default' => 28],
                'backgroundColor' => ['type' => 'string', 'default' => '#ffffff'],
                'dateColor' => ['type' => 'string', 'default' => '#1d2327'],
                'dateFontSize' => ['type' => 'number', 'default' => 18],
                'titleColor' => ['type' => 'string', 'default' => '#1d2327'],
                'titleFontSize' => ['type' => 'number', 'default' => 34],
                'titleFontWeight' => ['type' => 'number', 'default' => 400],
                'excerptColor' => ['type' => 'string', 'default' => '#333333'],
                'excerptFontSize' => ['type' => 'number', 'default' => 16],
                'buttonBackground' => ['type' => 'string', 'default' => '#2f7da5'],
                'buttonColor' => ['type' => 'string', 'default' => '#ffffff'],
                'buttonFontSize' => ['type' => 'number', 'default' => 20],
            ],
        ]);
        register_block_type('pscore-newsletter/social-icons', [
            'api_version' => 2,
            'editor_script' => 'pscore-nl-posts-block',
            'render_callback' => [self::class, 'render_social_icons_block'],
            'attributes' => [
                'instagramUrl' => ['type' => 'string', 'default' => 'https://www.instagram.com/pscorekorea/?hl=en'],
                'facebookUrl' => ['type' => 'string', 'default' => 'https://www.facebook.com/PscoreKorea/'],
                'xUrl' => ['type' => 'string', 'default' => 'https://twitter.com/PSCORE911'],
                'youtubeUrl' => ['type' => 'string', 'default' => 'https://www.youtube.com/pscore911'],
                'linkedinUrl' => ['type' => 'string', 'default' => 'https://www.linkedin.com/company/pscore/mycompany/'],
                'iconSize' => ['type' => 'number', 'default' => 28],
                'iconGap' => ['type' => 'number', 'default' => 10],
                'alignment' => ['type' => 'string', 'default' => 'left'],
            ],
        ]);

        add_action('enqueue_block_editor_assets', [self::class, 'editor_assets']);
        add_filter('block_categories_all', [self::class, 'block_categories'], 10, 2);
        add_action('rest_api_init', [self::class, 'rest_routes']);
        add_action('add_meta_boxes_' . self::POST_TYPE, [self::class, 'meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'save_meta'], 10, 2);
        add_filter('preview_post_link', [self::class, 'preview_link'], 10, 2);
        add_action('admin_post_pscore_nl_preview', [self::class, 'preview']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'column_content'], 10, 2);
    }

    public static function editor_assets(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        wp_localize_script('pscore-nl-posts-block', 'PSCORENewsletterBlock', [
            'postTypes' => self::available_content_types(),
            'socialIcons' => self::social_icon_urls(),
        ]);
    }

    public static function block_categories(array $categories, $context): array {
        if (!empty($context->post) && $context->post->post_type === self::POST_TYPE) {
            array_unshift($categories, ['slug' => 'pscore-newsletter', 'title' => 'PSCORE Newsletter']);
        }
        return $categories;
    }

    public static function rest_routes(): void {
        register_rest_route('pscore-newsletter/v1', '/posts', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'args' => [
                'type' => ['sanitize_callback' => 'sanitize_key'],
                'search' => ['sanitize_callback' => 'sanitize_text_field'],
                'per_page' => ['sanitize_callback' => 'absint'],
            ],
            'callback' => [self::class, 'rest_posts'],
        ]);
    }

    public static function rest_posts(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type') ?: 'post';
        if ($type !== 'any' && (!in_array($type, self::content_type_slugs(), true) || !post_type_exists($type))) {
            return new WP_REST_Response([], 200);
        }
        $limit = min(20, max(1, absint($request->get_param('per_page') ?: 10)));
        $posts = get_posts([
            'post_type' => $type === 'any' ? self::content_type_slugs() : $type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            's' => $request->get_param('search') ?: '',
            'suppress_filters' => false,
        ]);
        return new WP_REST_Response(array_map(static function (WP_Post $post): array {
            return [
                'id' => $post->ID,
                'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'date' => get_the_date('', $post),
                'image' => get_the_post_thumbnail_url($post, 'medium_large') ?: '',
            ];
        }, $posts), 200);
    }

    public static function render_posts_block(array $attributes): string {
        $type = sanitize_key($attributes['postType'] ?? 'post');
        $types = self::content_type_slugs();
        $query_types = $type === 'any' ? $types : (in_array($type, $types, true) ? $type : 'post');
        $count = min(20, max(1, absint($attributes['postsToShow'] ?? 2)));
        $posts = get_posts([
            'post_type' => $query_types,
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);
        if (!$posts) {
            return '';
        }

        $image_width = min(70, max(25, absint($attributes['imageWidth'] ?? 52)));
        $text_width = 100 - $image_width;
        $content_padding = min(60, max(0, absint($attributes['contentPadding'] ?? 20)));
        $row_spacing = min(80, max(0, absint($attributes['rowSpacing'] ?? 28)));
        $background = self::email_color($attributes['backgroundColor'] ?? '', '#ffffff');
        $date_color = self::email_color($attributes['dateColor'] ?? '', '#1d2327');
        $title_color = self::email_color($attributes['titleColor'] ?? '', '#1d2327');
        $excerpt_color = self::email_color($attributes['excerptColor'] ?? '', '#333333');
        $button_background = self::email_color($attributes['buttonBackground'] ?? '', '#2f7da5');
        $button_color = self::email_color($attributes['buttonColor'] ?? '', '#ffffff');
        $date_size = min(40, max(10, absint($attributes['dateFontSize'] ?? 18)));
        $title_size = min(60, max(16, absint($attributes['titleFontSize'] ?? 34)));
        $title_weight = min(900, max(100, absint($attributes['titleFontWeight'] ?? 400)));
        $excerpt_size = min(30, max(10, absint($attributes['excerptFontSize'] ?? 16)));
        $button_size = min(32, max(10, absint($attributes['buttonFontSize'] ?? 20)));

        $html = '<div class="pscore-recent-posts">';
        foreach ($posts as $post) {
            $url = get_permalink($post);
            $title = get_the_title($post);
            $image = !empty($attributes['showImage']) ? get_the_post_thumbnail_url($post, 'large') : '';
            $excerpt = !empty($attributes['showExcerpt']) ? wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 35) : '';
            $html .= '<table class="pscore-recent-post" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:' . esc_attr($background) . ';margin:0 0 ' . $row_spacing . 'px"><tr>';
            if ($image) {
                $html .= '<td class="pscore-recent-post-image" width="' . $image_width . '%" valign="top" style="width:' . $image_width . '%;vertical-align:top"><a href="' . esc_url($url) . '" style="text-decoration:none"><img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" width="100%" style="display:block;width:100%;max-width:100%;height:auto;border:0"></a></td>';
            }
            $actual_text_width = $image ? $text_width : 100;
            $html .= '<td class="pscore-recent-post-content" width="' . $actual_text_width . '%" valign="top" style="width:' . $actual_text_width . '%;vertical-align:top;padding:0 0 0 ' . ($image ? $content_padding : 0) . 'px">';
            if (!empty($attributes['showDate'])) {
                $html .= '<div class="pscore-recent-post-date" style="margin:0 0 18px;color:' . esc_attr($date_color) . ';font-size:' . $date_size . 'px;line-height:1.3;font-style:italic">' . esc_html(get_the_date('', $post)) . '</div>';
            }
            $html .= '<h2 class="pscore-recent-post-title" style="margin:0 0 22px;font-size:' . $title_size . 'px;line-height:1.15;font-weight:' . $title_weight . '"><a href="' . esc_url($url) . '" style="color:' . esc_attr($title_color) . ';text-decoration:none">' . esc_html($title) . '</a></h2>';
            if ($excerpt) {
                $html .= '<p class="pscore-recent-post-excerpt" style="margin:0 0 22px;color:' . esc_attr($excerpt_color) . ';font-size:' . $excerpt_size . 'px;line-height:1.5">' . esc_html($excerpt) . '</p>';
            }
            if (!empty($attributes['buttonLabel'])) {
                $html .= '<div class="pscore-recent-post-button-wrap" style="margin:0"><a class="pscore-recent-post-button" href="' . esc_url($url) . '" style="display:inline-block;background:' . esc_attr($button_background) . ';color:' . esc_attr($button_color) . ';font-size:' . $button_size . 'px;line-height:1.2;text-decoration:none;padding:13px 28px">' . esc_html($attributes['buttonLabel']) . '</a></div>';
            }
            $html .= '</td></tr></table>';
        }
        return $html . '</div>';
    }

    public static function render_social_icons_block(array $attributes): string {
        $networks = [
            'instagram' => ['label' => 'Instagram', 'attribute' => 'instagramUrl'],
            'facebook' => ['label' => 'Facebook', 'attribute' => 'facebookUrl'],
            'x' => ['label' => 'X', 'attribute' => 'xUrl'],
            'youtube' => ['label' => 'YouTube', 'attribute' => 'youtubeUrl'],
            'linkedin' => ['label' => 'LinkedIn', 'attribute' => 'linkedinUrl'],
        ];
        $defaults = [
            'instagramUrl' => 'https://www.instagram.com/pscorekorea/?hl=en',
            'facebookUrl' => 'https://www.facebook.com/PscoreKorea/',
            'xUrl' => 'https://twitter.com/PSCORE911',
            'youtubeUrl' => 'https://www.youtube.com/pscore911',
            'linkedinUrl' => 'https://www.linkedin.com/company/pscore/mycompany/',
        ];
        $size = min(64, max(16, absint($attributes['iconSize'] ?? 28)));
        $gap = min(40, max(0, absint($attributes['iconGap'] ?? 10)));
        $alignment = sanitize_key($attributes['alignment'] ?? 'left');
        if (!in_array($alignment, ['left', 'center', 'right'], true)) $alignment = 'left';
        $icons = self::social_icon_urls();
        $cells = '';
        foreach ($networks as $network => $settings) {
            $url = trim((string) ($attributes[$settings['attribute']] ?? $defaults[$settings['attribute']]));
            if ($url === '') continue;
            $cells .= '<td class="pscore-social-icon pscore-social-icon-' . esc_attr($network) . '" width="' . ($size + $gap) . '" style="width:' . ($size + $gap) . 'px;padding:0 ' . (int) floor($gap / 2) . 'px">'
                . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" style="display:block;text-decoration:none">'
                . '<img src="' . esc_url($icons[$network]) . '" alt="' . esc_attr($settings['label']) . '" width="' . $size . '" height="' . $size . '" style="display:block;width:' . $size . 'px;height:' . $size . 'px;border:0">'
                . '</a></td>';
        }
        if ($cells === '') return '';
        return '<table class="pscore-social-icons" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="' . esc_attr($alignment) . '"><table role="presentation" cellspacing="0" cellpadding="0" border="0" align="' . esc_attr($alignment) . '"><tr>' . $cells . '</tr></table></td></tr></table>';
    }

    private static function social_icon_urls(): array {
        $base = plugins_url('assets/social-icons/', PSCORE_NL_FILE);
        return [
            'instagram' => $base . 'instagram.png',
            'facebook' => $base . 'facebook.png',
            'x' => $base . 'x.png',
            'youtube' => $base . 'youtube.png',
            'linkedin' => $base . 'linkedin.png',
        ];
    }

    private static function email_color(string $color, string $fallback): string {
        $color = sanitize_hex_color($color);
        return $color ?: $fallback;
    }

    public static function meta_boxes(): void {
        add_meta_box('pscore-nl-delivery', 'Newsletter delivery', [self::class, 'delivery_box'], self::POST_TYPE, 'side', 'high');
    }

    public static function delivery_box(WP_Post $post): void {
        global $wpdb;
        wp_nonce_field('pscore_nl_save_email', 'pscore_nl_email_nonce');
        $subject = get_post_meta($post->ID, '_pscore_nl_subject', true);
        $selected = array_map('intval', (array) get_post_meta($post->ID, '_pscore_nl_list_ids', true));
        $frequency = get_post_meta($post->ID, '_pscore_nl_frequency', true) ?: 'once';
        $schedule_status = get_post_meta($post->ID, '_pscore_nl_schedule_status', true) ?: 'draft';
        $next_run = (int) get_post_meta($post->ID, '_pscore_nl_next_run', true);
        $custom_css = get_post_meta($post->ID, '_pscore_nl_custom_css', true);
        $next_value = $next_run ? wp_date('Y-m-d\TH:i', $next_run, wp_timezone()) : '';
        $lists = $wpdb->get_results('SELECT * FROM ' . PSCORE_NL_Plugin::table('lists') . ' ORDER BY name');
        ?>
        <p><label><strong>Email subject</strong><br><input style="width:100%" name="pscore_nl_subject" value="<?php echo esc_attr($subject); ?>" placeholder="<?php echo esc_attr($post->post_title); ?>"></label></p>
        <p><strong>Recipient lists</strong></p>
        <?php foreach ($lists as $list) : ?><p><label><input type="checkbox" name="pscore_nl_list_ids[]" value="<?php echo esc_attr($list->id); ?>" <?php checked(in_array((int) $list->id, $selected, true)); ?>> <?php echo esc_html($list->name); ?></label></p><?php endforeach; ?>
        <p><label><strong>Frequency</strong><br><select name="pscore_nl_frequency" style="width:100%">
            <?php foreach (['once'=>'Once','weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly'] as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($frequency, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
        </select></label></p>
        <p><label><strong>First/next send</strong><br><input style="width:100%" type="datetime-local" name="pscore_nl_next_run" value="<?php echo esc_attr($next_value); ?>"></label><br><small><?php echo esc_html(wp_timezone_string()); ?></small></p>
        <p><label><strong>Automatic sending</strong><br><select name="pscore_nl_schedule_status" style="width:100%"><option value="draft" <?php selected($schedule_status, 'draft'); ?>>Inactive</option><option value="active" <?php selected($schedule_status, 'active'); ?>>Active</option><option value="paused" <?php selected($schedule_status, 'paused'); ?>>Paused</option></select></label></p>
        <p><small>The newsletter must also be published. Active newsletters are sent automatically by WP-Cron.</small></p>
        <p><a class="button" target="_blank" href="<?php echo esc_url(self::preview_link('', $post)); ?>">Preview newsletter</a></p>
        <hr><p><label><strong>Custom email CSS</strong><br><textarea name="pscore_nl_custom_css" rows="8" style="width:100%;font-family:monospace" placeholder=".wp-block-heading { color: #082b68; }"><?php echo esc_textarea($custom_css); ?></textarea></label></p>
        <p><small>Enter CSS rules only, without <code>&lt;style&gt;</code> tags. Support varies between email clients; inline block settings remain the most reliable option.</small></p>
        <hr><p><small>Text placeholders: <code>{name}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{email}</code>, <code>{site_name}</code>, <code>{site_url}</code>, <code>{unsubscribe_url}</code>.</small></p>
        <?php
    }

    public static function save_meta(int $post_id, WP_Post $post): void {
        if (!isset($_POST['pscore_nl_email_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pscore_nl_email_nonce'])), 'pscore_nl_save_email') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
            return;
        }
        update_post_meta($post_id, '_pscore_nl_subject', sanitize_text_field(wp_unslash($_POST['pscore_nl_subject'] ?? '')));
        update_post_meta($post_id, '_pscore_nl_list_ids', array_values(array_filter(array_map('absint', (array) ($_POST['pscore_nl_list_ids'] ?? [])))));
        $frequency = sanitize_key(wp_unslash($_POST['pscore_nl_frequency'] ?? 'once'));
        update_post_meta($post_id, '_pscore_nl_frequency', in_array($frequency, ['once','weekly','monthly','quarterly'], true) ? $frequency : 'once');
        $status = sanitize_key(wp_unslash($_POST['pscore_nl_schedule_status'] ?? 'draft'));
        update_post_meta($post_id, '_pscore_nl_schedule_status', in_array($status, ['draft','active','paused'], true) ? $status : 'draft');
        $raw = sanitize_text_field(wp_unslash($_POST['pscore_nl_next_run'] ?? ''));
        try {
            $next = $raw ? (new DateTimeImmutable($raw, wp_timezone()))->getTimestamp() : 0;
        } catch (Exception $exception) {
            $next = 0;
        }
        update_post_meta($post_id, '_pscore_nl_next_run', $next);
        update_post_meta($post_id, '_pscore_nl_custom_css', self::sanitize_css(wp_unslash($_POST['pscore_nl_custom_css'] ?? '')));
    }

    public static function preview_link(string $link, WP_Post $post): string {
        if ($post->post_type !== self::POST_TYPE) {
            return $link;
        }
        return wp_nonce_url(
            admin_url('admin-post.php?action=pscore_nl_preview&post_id=' . $post->ID),
            'pscore_nl_preview_' . $post->ID
        );
    }

    public static function preview(): void {
        $post_id = absint($_GET['post_id'] ?? 0);
        check_admin_referer('pscore_nl_preview_' . $post_id);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('You are not allowed to preview this newsletter.', 403);
        }
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die('Newsletter not found.', 404);
        }
        $subject = get_post_meta($post_id, '_pscore_nl_subject', true) ?: $post->post_title;
        $custom_css = self::sanitize_css((string) get_post_meta($post_id, '_pscore_nl_custom_css', true));
        $content = apply_filters('the_content', $post->post_content);
        $content = PSCORE_NL_CSS_Inliner::inline('<main class="email pscore-newsletter-email">' . $content . '</main>', $custom_css);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html('Preview: ' . $subject); ?></title>
            <style>
                body{margin:0;background:#f0f0f1;color:#1d2327;font-family:Arial,sans-serif}
                .preview-bar{position:sticky;top:0;z-index:2;background:#1d2327;color:#fff;padding:10px 18px;font-size:14px}
                .preview-bar strong{margin-right:10px}.email{box-sizing:border-box;max-width:680px;margin:30px auto;background:#fff;padding:36px;line-height:1.6;box-shadow:0 2px 12px rgba(0,0,0,.12)}
                .email img{max-width:100%;height:auto}.email a{color:#082b68}.email .wp-block-button__link{display:inline-block;background:#082b68;color:#fff;text-decoration:none;padding:10px 18px;border-radius:3px}
                @media(max-width:720px){.email{margin:0;padding:22px;box-shadow:none}}
                <?php echo $custom_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </style>
        </head>
        <body><div class="preview-bar"><strong>Newsletter preview</strong><?php echo esc_html($subject); ?> — no email was sent</div><?php echo wp_kses_post($content); ?></body>
        </html><?php
        exit;
    }

    private static function sanitize_css(string $css): string {
        $css = wp_strip_all_tags($css);
        $css = preg_replace('/(?:expression\s*\(|javascript\s*:|@import)/i', '', $css);
        $css = str_ireplace(['</style', '<style'], '', $css);
        return trim($css);
    }

    public static function columns(array $columns): array {
        $columns['pscore_schedule'] = 'Schedule';
        return $columns;
    }

    public static function column_content(string $column, int $post_id): void {
        if ($column !== 'pscore_schedule') return;
        $status = get_post_meta($post_id, '_pscore_nl_schedule_status', true) ?: 'draft';
        $frequency = get_post_meta($post_id, '_pscore_nl_frequency', true) ?: 'once';
        $next = (int) get_post_meta($post_id, '_pscore_nl_next_run', true);
        echo esc_html(ucfirst($status) . ' · ' . ucfirst($frequency));
        if ($next) echo '<br><small>' . esc_html(wp_date('Y-m-d H:i', $next, wp_timezone())) . '</small>';
    }

    private static function available_content_types(): array {
        $types = [['value' => 'any', 'label' => 'All supported post types']];
        foreach (self::content_type_slugs() as $slug) {
            $object = get_post_type_object($slug);
            if ($object) {
                $label = $object->labels->singular_name ?: $slug;
                $types[] = ['value' => $slug, 'label' => $label . ' (' . $slug . ')'];
            }
        }
        return $types;
    }

    private static function content_type_slugs(): array {
        $slugs = self::BASE_CONTENT_TYPES;
        foreach (get_post_types(['show_ui' => true], 'objects') as $object) {
            if (!$object->_builtin && $object->name !== self::POST_TYPE && ($object->public || $object->publicly_queryable)) {
                $slugs[] = $object->name;
            }
        }
        $slugs = array_values(array_unique(array_filter($slugs, 'post_type_exists')));
        return apply_filters('pscore_nl_content_post_types', $slugs);
    }
}

