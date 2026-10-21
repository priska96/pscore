<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_Admin {
    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'handle_actions']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void {
        add_menu_page('PSCORE Newsletter', 'PSCORE Newsletter', 'manage_options', 'pscore-newsletter', [self::class, 'dashboard'], 'dashicons-email-alt', 40);
        add_submenu_page('pscore-newsletter', 'Overview', 'Overview', 'manage_options', 'pscore-newsletter', [self::class, 'dashboard']);
        add_submenu_page('pscore-newsletter', 'Lists', 'Lists', 'manage_options', 'pscore-newsletter-lists', [self::class, 'lists']);
        add_submenu_page('pscore-newsletter', 'Forms', 'Forms', 'manage_options', 'pscore-newsletter-forms', [self::class, 'forms']);
        add_submenu_page('pscore-newsletter', 'Subscribers', 'Subscribers', 'manage_options', 'pscore-newsletter-subscribers', [self::class, 'subscribers']);
        add_submenu_page('pscore-newsletter', 'Settings', 'Settings', 'manage_options', 'pscore-newsletter-settings', [self::class, 'settings_page']);
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'pscore-newsletter') === false) {
            return;
        }
        wp_enqueue_style('pscore-nl-admin', plugins_url('../assets/admin.css', __FILE__), [], PSCORE_NL_VERSION);
    }

    public static function handle_actions(): void {
        if (!current_user_can('manage_options') || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['pscore_nl_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['pscore_nl_action']));
        check_admin_referer('pscore_nl_' . $action);
        if ($action === 'save_list') self::save_list();
        if ($action === 'save_form') self::save_form();
        if ($action === 'save_settings') self::save_settings();
    }

    public static function dashboard(): void {
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        if ($view === 'usage') {
            self::usage_instructions();
            return;
        }
        global $wpdb;
        self::header('Overview');
        $counts = [
            'Confirmed subscribers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . PSCORE_NL_Plugin::table('subscribers') . " WHERE status='confirmed'"),
            'Lists' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . PSCORE_NL_Plugin::table('lists')),
            'Forms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . PSCORE_NL_Plugin::table('forms')),
            'Active newsletters' => (int) count(get_posts(['post_type' => PSCORE_NL_Editor::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_pscore_nl_schedule_status', 'meta_value' => 'active'])),
            'Queued emails' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . PSCORE_NL_Plugin::table('queue') . " WHERE status IN ('pending','processing')"),
        ];
        echo '<div class="pscore-nl-cards">';
        foreach ($counts as $label => $count) echo '<div class="pscore-nl-card"><strong>' . esc_html((string) $count) . '</strong><span>' . esc_html($label) . '</span></div>';
        echo '</div><p><a class="button button-secondary" href="' . esc_url(add_query_arg(['page' => 'pscore-newsletter', 'view' => 'usage'], admin_url('admin.php'))) . '">Usage Instructions</a></p>';
        self::footer();
    }

    private static function usage_instructions(): void {
        $path = PSCORE_NL_DIR . '/PSCORE-Newsletter-Usage-Instructions.md';
        $manual = is_readable($path) ? file_get_contents($path) : false;
        self::header('Usage Instructions');
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=pscore-newsletter')) . '">← Back to Overview</a></p>';
        if ($manual === false) {
            echo '<div class="notice notice-error inline"><p>The usage instructions file is missing. Upload <code>PSCORE-Newsletter-Usage-Instructions.md</code> to the plugin directory.</p></div>';
        } else {
            echo '<div class="pscore-nl-manual">' . self::markdown_to_html($manual) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        self::footer();
    }

    private static function markdown_inline(string $text): string {
        $text = esc_html($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    }

    private static function markdown_to_html(string $markdown): string {
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $html = '';
        $open_list = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($open_list) $html .= '</' . $open_list . '>';
                $open_list = '';
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $matches)) {
                if ($open_list) $html .= '</' . $open_list . '>';
                $open_list = '';
                $level = strlen($matches[1]);
                $html .= '<h' . $level . '>' . self::markdown_inline($matches[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                if ($open_list !== 'ul') {
                    if ($open_list) $html .= '</' . $open_list . '>';
                    $html .= '<ul>';
                    $open_list = 'ul';
                }
                $html .= '<li>' . self::markdown_inline($matches[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
                if ($open_list !== 'ol') {
                    if ($open_list) $html .= '</' . $open_list . '>';
                    $html .= '<ol>';
                    $open_list = 'ol';
                }
                $html .= '<li>' . self::markdown_inline($matches[1]) . '</li>';
                continue;
            }
            if ($open_list) $html .= '</' . $open_list . '>';
            $open_list = '';
            $html .= '<p>' . self::markdown_inline($line) . '</p>';
        }
        if ($open_list) $html .= '</' . $open_list . '>';
        return wp_kses_post($html);
    }

    public static function lists(): void {
        global $wpdb;
        $table = PSCORE_NL_Plugin::table('lists');
        $edit = isset($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", absint($_GET['edit']))) : null;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY name");
        self::header('Lists');
        ?>
        <div class="pscore-nl-grid"><div><h2><?php echo $edit ? 'Edit list' : 'Add list'; ?></h2><form method="post">
        <?php wp_nonce_field('pscore_nl_save_list'); ?><input type="hidden" name="pscore_nl_action" value="save_list"><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <p><label>Name<br><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></label></p>
        <p><label>Slug<br><input class="regular-text" name="slug" value="<?php echo esc_attr($edit->slug ?? ''); ?>"></label></p>
        <p><label>Description<br><textarea class="large-text" name="description" rows="4"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></label></p>
        <?php submit_button($edit ? 'Update list' : 'Add list'); ?></form></div><div><h2>Existing lists</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Slug</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $row) echo '<tr><td>' . esc_html($row->name) . '</td><td><code>' . esc_html($row->slug) . '</code></td><td><a href="' . esc_url(admin_url('admin.php?page=pscore-newsletter-lists&edit=' . $row->id)) . '">Edit</a></td></tr>'; ?>
        </tbody></table></div></div><?php self::footer();
    }

    public static function forms(): void {
        global $wpdb;
        $table = PSCORE_NL_Plugin::table('forms');
        $edit = isset($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", absint($_GET['edit']))) : null;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        $lists = self::all_lists();
        $selected = $edit ? (array) json_decode($edit->list_ids, true) : [];
        $name_mode = $edit->name_mode ?? (($edit && !$edit->show_name) ? 'none' : 'full');
        self::header('Subscription forms');
        ?>
        <div class="pscore-nl-grid"><div><h2><?php echo $edit ? 'Edit form' : 'Add form'; ?></h2><form method="post">
        <?php wp_nonce_field('pscore_nl_save_form'); ?><input type="hidden" name="pscore_nl_action" value="save_form"><input type="hidden" name="id" value="<?php echo esc_attr($edit->id ?? 0); ?>">
        <p><label>Internal name<br><input class="regular-text" name="name" required value="<?php echo esc_attr($edit->name ?? ''); ?>"></label></p>
        <p><label>Heading<br><input class="regular-text" name="title" value="<?php echo esc_attr($edit->title ?? ''); ?>"></label></p>
        <p><label>Description<br><textarea class="large-text" name="description" rows="4"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></label></p>
        <p><label>Button label<br><input name="button_label" value="<?php echo esc_attr($edit->button_label ?? 'Subscribe'); ?>"></label></p>
        <p><label>Success message<br><textarea class="large-text" name="success_message" rows="3"><?php echo esc_textarea($edit->success_message ?? 'Please check your inbox.'); ?></textarea></label></p>
        <p><label>Name fields<br><select name="name_mode"><option value="none" <?php selected($name_mode, 'none'); ?>>Email only</option><option value="full" <?php selected($name_mode, 'full'); ?>>Full name</option><option value="separate" <?php selected($name_mode, 'separate'); ?>>First and last name</option></select></label></p>
        <p><label>Email label<br><input class="regular-text" name="email_label" value="<?php echo esc_attr($edit->email_label ?? 'Email'); ?>"></label></p>
        <p><label>Full-name label<br><input class="regular-text" name="full_name_label" value="<?php echo esc_attr($edit->full_name_label ?? 'Full name'); ?>"></label></p>
        <p><label>First-name label<br><input class="regular-text" name="first_name_label" value="<?php echo esc_attr($edit->first_name_label ?? 'First name'); ?>"></label></p>
        <p><label>Last-name label<br><input class="regular-text" name="last_name_label" value="<?php echo esc_attr($edit->last_name_label ?? 'Last name'); ?>"></label></p>
        <fieldset><legend><strong>Subscribe to lists</strong></legend><?php self::list_checkboxes($lists, $selected); ?></fieldset>
        <?php submit_button($edit ? 'Update form' : 'Add form'); ?></form></div><div><h2>Existing forms</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Shortcode</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $row) echo '<tr><td>' . esc_html($row->name) . '</td><td><code>[pscore_newsletter_form id=&quot;' . esc_html($row->id) . '&quot;]</code></td><td><a href="' . esc_url(admin_url('admin.php?page=pscore-newsletter-forms&edit=' . $row->id)) . '">Edit</a></td></tr>'; ?>
        </tbody></table></div></div><?php self::footer();
    }

    public static function subscribers(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . PSCORE_NL_Plugin::table('subscribers') . ' ORDER BY id DESC LIMIT 500');
        self::header('Subscribers');
        echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $row) echo '<tr><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->status) . '</td><td>' . esc_html($row->created_at) . '</td></tr>';
        echo '</tbody></table>'; self::footer();
    }

    public static function settings_page(): void {
        $s = PSCORE_NL_Plugin::settings(); self::header('Settings'); ?>
        <form method="post"><?php wp_nonce_field('pscore_nl_save_settings'); ?><input type="hidden" name="pscore_nl_action" value="save_settings"><table class="form-table">
        <tr><th>Sender name</th><td><input class="regular-text" name="from_name" value="<?php echo esc_attr($s['from_name']); ?>"></td></tr>
        <tr><th>Sender email</th><td><input class="regular-text" type="email" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"><p class="description">Use an address permitted by WP Mail SMTP.</p></td></tr>
        <tr><th>Batch size</th><td><input type="number" min="1" max="100" name="batch_size" value="<?php echo esc_attr($s['batch_size']); ?>"><p class="description">Emails sent per cron run.</p></td></tr>
        <tr><th>Double opt-in</th><td><label><input type="checkbox" name="double_opt_in" value="1" <?php checked($s['double_opt_in'], 1); ?>> Require email confirmation</label></td></tr>
        <tr><th>Confirmation subject</th><td><input class="large-text" name="confirmation_subject" value="<?php echo esc_attr($s['confirmation_subject']); ?>"></td></tr>
        <tr><th>Confirmation text</th><td><textarea class="large-text" rows="8" name="confirmation_text"><?php echo esc_textarea($s['confirmation_text']); ?></textarea><p class="description">Use <code>{confirmation_url}</code>, <code>{name}</code> and <code>{site_name}</code>.</p></td></tr>
        </table><?php submit_button('Save settings'); ?></form><?php self::footer();
    }

    private static function save_list(): void { global $wpdb; $id=absint($_POST['id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $data=['name'=>$name,'slug'=>sanitize_title(wp_unslash($_POST['slug']??''))?:sanitize_title($name),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??''))]; if($id)$wpdb->update(PSCORE_NL_Plugin::table('lists'),$data,['id'=>$id]);else{$data['created_at']=current_time('mysql');$wpdb->insert(PSCORE_NL_Plugin::table('lists'),$data);} self::redirect('pscore-newsletter-lists'); }
    private static function save_form(): void { global $wpdb; $id=absint($_POST['id']??0); $mode=sanitize_key(wp_unslash($_POST['name_mode']??'full')); if(!in_array($mode,['none','full','separate'],true))$mode='full'; $data=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'button_label'=>sanitize_text_field(wp_unslash($_POST['button_label']??'')),'success_message'=>sanitize_textarea_field(wp_unslash($_POST['success_message']??'')),'show_name'=>$mode==='none'?0:1,'name_mode'=>$mode,'email_label'=>sanitize_text_field(wp_unslash($_POST['email_label']??'Email')),'full_name_label'=>sanitize_text_field(wp_unslash($_POST['full_name_label']??'Full name')),'first_name_label'=>sanitize_text_field(wp_unslash($_POST['first_name_label']??'First name')),'last_name_label'=>sanitize_text_field(wp_unslash($_POST['last_name_label']??'Last name')),'list_ids'=>wp_json_encode(array_values(array_filter(array_map('absint',(array)($_POST['list_ids']??[]))))),'updated_at'=>current_time('mysql')]; if($id)$wpdb->update(PSCORE_NL_Plugin::table('forms'),$data,['id'=>$id]);else{$data['created_at']=current_time('mysql');$wpdb->insert(PSCORE_NL_Plugin::table('forms'),$data);} self::redirect('pscore-newsletter-forms'); }
    private static function save_settings(): void { update_option('pscore_nl_settings',['from_name'=>sanitize_text_field(wp_unslash($_POST['from_name']??'')),'from_email'=>sanitize_email(wp_unslash($_POST['from_email']??'')),'batch_size'=>min(100,max(1,absint($_POST['batch_size']??20))),'double_opt_in'=>empty($_POST['double_opt_in'])?0:1,'confirmation_subject'=>sanitize_text_field(wp_unslash($_POST['confirmation_subject']??'')),'confirmation_text'=>sanitize_textarea_field(wp_unslash($_POST['confirmation_text']??''))],false); self::redirect('pscore-newsletter-settings'); }
    private static function all_lists(): array { global $wpdb; return $wpdb->get_results('SELECT * FROM '.PSCORE_NL_Plugin::table('lists').' ORDER BY name'); }
    private static function list_checkboxes(array $lists,array $selected): void { foreach($lists as $list) echo '<p><label><input type="checkbox" name="list_ids[]" value="'.esc_attr($list->id).'" '.checked(in_array((int)$list->id,array_map('intval',$selected),true),true,false).'> '.esc_html($list->name).'</label></p>'; }
    private static function redirect(string $page): void { wp_safe_redirect(admin_url('admin.php?page='.$page.'&updated=1')); exit; }
    private static function header(string $title): void { echo '<div class="wrap pscore-nl-admin"><h1>'.esc_html($title).'</h1>'; if(isset($_GET['updated']))echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>'; }
    private static function footer(): void { echo '</div>'; }
}

