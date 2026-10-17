<?php

/**
 * Plugin Name: PSCORE SEO Manager
 * Description: Internal SEO management tool for PSCORE pages and posts.
 * Version: 1.2.1
 * Author: Priska - PSCORE
 */

/**
 * PSCORE Page & SEO Manager
 *
 * Adds Dashboard → PSCORE SEO and lets administrators edit all pages:
 * - WordPress page title (post_title)
 * - URL slug (post_name)
 * - Rank Math SEO title / browser tab title (rank_math_title)
 * - Rank Math meta description (rank_math_description)
 * - Rank Math focus keyword (rank_math_focus_keyword)
 *
 * Leave the Rank Math title empty to use Rank Math's global page-title
 * template. That template can include the WordPress title via %title%.
 */

if (!defined('ABSPATH')) {
    exit;
}

function pscore_seo_languages() {
    if (!function_exists('pll_languages_list')) {
        return array();
    }

    $slugs = pll_languages_list(array('fields' => 'slug'));
    $names = pll_languages_list(array('fields' => 'name'));
    $languages = array();

    foreach ($slugs as $index => $slug) {
        $languages[$slug] = isset($names[$index]) ? $names[$index] : strtoupper($slug);
    }

    return $languages;
}

function pscore_seo_post_language($post_id) {
    return function_exists('pll_get_post_language')
        ? (string) pll_get_post_language($post_id, 'slug')
        : '';
}

function pscore_seo_get_content($language = '', $content_type = '') {
    $post_types = in_array($content_type, array('page', 'post'), true)
        ? $content_type
        : array('page', 'post');

    return get_posts(array(
        'post_type'        => $post_types,
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'orderby'          => array('post_type' => 'ASC', 'menu_order' => 'ASC', 'title' => 'ASC'),
        'order'            => 'ASC',
        'suppress_filters' => false,
        'lang'             => $language,
    ));
}

function pscore_seo_csv_value($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

function pscore_seo_csv_restore($value) {
    $value = (string) $value;
    return preg_match('/^\'[=+\-@]/', $value) ? substr($value, 1) : $value;
}

add_action('admin_menu', function () {
    add_menu_page(
        'PSCORE Page & SEO Manager',
        'PSCORE SEO',
        'manage_options',
        'pscore-seo-manager',
        'pscore_render_seo_manager',
        'dashicons-admin-links',
        80
    );
});

add_action('admin_post_pscore_export_seo', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.'));
    }

    check_admin_referer('pscore_export_seo');

    $languages = pscore_seo_languages();
    $language = isset($_GET['language']) ? sanitize_key(wp_unslash($_GET['language'])) : '';
    if ($language && !isset($languages[$language])) {
        $language = '';
    }

    $content_type = isset($_GET['content_type']) ? sanitize_key(wp_unslash($_GET['content_type'])) : '';
    if (!in_array($content_type, array('page', 'post'), true)) {
        $content_type = '';
    }

    $filename = 'pscore-' . ($content_type ?: 'page-post') . '-seo-' . ($language ?: 'all-languages') . '-' . gmdate('Y-m-d') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array(
        'id',
        'post_type',
        'language',
        'page_title',
        'slug',
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
    ));

    foreach (pscore_seo_get_content($language, $content_type) as $post) {
        fputcsv($output, array(
            $post->ID,
            $post->post_type,
            pscore_seo_post_language($post->ID),
            pscore_seo_csv_value($post->post_title),
            $post->post_name,
            pscore_seo_csv_value(get_post_meta($post->ID, 'rank_math_title', true)),
            pscore_seo_csv_value(get_post_meta($post->ID, 'rank_math_description', true)),
            pscore_seo_csv_value(get_post_meta($post->ID, 'rank_math_focus_keyword', true)),
        ));
    }

    fclose($output);
    exit;
});

add_action('admin_post_pscore_import_seo', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.'));
    }

    check_admin_referer('pscore_import_seo_action', 'pscore_import_nonce');

    $errors = array();
    $changed = 0;

    if (empty($_POST['confirm_import'])) {
        $errors[] = 'Import was not confirmed.';
    } elseif (empty($_FILES['seo_csv']) || UPLOAD_ERR_OK !== (int) $_FILES['seo_csv']['error']) {
        $errors[] = 'Please select a valid CSV file.';
    } elseif ((int) $_FILES['seo_csv']['size'] > 2 * MB_IN_BYTES) {
        $errors[] = 'The CSV file is larger than 2 MB.';
    } else {
        $handle = fopen($_FILES['seo_csv']['tmp_name'], 'r');

        if (!$handle) {
            $errors[] = 'The uploaded CSV file could not be read.';
        } else {
            $header = fgetcsv($handle);
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }

            $required = array('id', 'post_type', 'language', 'page_title', 'slug', 'rank_math_title');
            $columns = is_array($header) ? array_flip($header) : array();

            if (array_diff($required, array_keys($columns))) {
                $errors[] = 'The CSV header is invalid. Export a fresh template from this tool.';
            } else {
                $languages = pscore_seo_languages();
                $line = 1;

                while (($row = fgetcsv($handle)) !== false) {
                    $line++;
                    if (!array_filter($row, 'strlen')) {
                        continue;
                    }

                    $post_id = absint($row[$columns['id']] ?? 0);
                    $post = $post_id ? get_post($post_id) : null;
                    $csv_type = sanitize_key($row[$columns['post_type']] ?? '');

                    if (!$post || 'publish' !== $post->post_status || !in_array($post->post_type, array('page', 'post'), true)) {
                        $errors[] = sprintf('Line %d: Published page/post ID %d was not found.', $line, $post_id);
                        continue;
                    }
                    if ($csv_type !== $post->post_type || !current_user_can('edit_post', $post_id)) {
                        $errors[] = sprintf('Line %d: Post type or permission mismatch for ID %d.', $line, $post_id);
                        continue;
                    }

                    $title = sanitize_text_field(pscore_seo_csv_restore($row[$columns['page_title']] ?? ''));
                    $slug = sanitize_title($row[$columns['slug']] ?? '');
                    $seo_title = sanitize_text_field(pscore_seo_csv_restore($row[$columns['rank_math_title']] ?? ''));
                    $seo_description = isset($columns['rank_math_description'])
                        ? sanitize_textarea_field(pscore_seo_csv_restore($row[$columns['rank_math_description']] ?? ''))
                        : null;
                    $focus_keyword = isset($columns['rank_math_focus_keyword'])
                        ? sanitize_text_field(pscore_seo_csv_restore($row[$columns['rank_math_focus_keyword']] ?? ''))
                        : null;
                    $language = sanitize_key($row[$columns['language']] ?? '');

                    if ('' === $title || '' === $slug) {
                        $errors[] = sprintf('Line %d: Title and slug are required.', $line);
                        continue;
                    }
                    if ($language && $languages && !isset($languages[$language])) {
                        $errors[] = sprintf('Line %d: Unknown Polylang language “%s”.', $line, $language);
                        continue;
                    }

                    $result = wp_update_post(wp_slash(array(
                        'ID'         => $post_id,
                        'post_title' => $title,
                        'post_name'  => $slug,
                    )), true);

                    if (is_wp_error($result)) {
                        $errors[] = sprintf('Line %d: %s', $line, $result->get_error_message());
                        continue;
                    }

                    if ('' === $seo_title) {
                        delete_post_meta($post_id, 'rank_math_title');
                    } else {
                        update_post_meta($post_id, 'rank_math_title', $seo_title);
                    }

                    if (null !== $seo_description) {
                        if ('' === $seo_description) {
                            delete_post_meta($post_id, 'rank_math_description');
                        } else {
                            update_post_meta($post_id, 'rank_math_description', $seo_description);
                        }
                    }

                    if (null !== $focus_keyword) {
                        if ('' === $focus_keyword) {
                            delete_post_meta($post_id, 'rank_math_focus_keyword');
                        } else {
                            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
                        }
                    }

                    if ($language && function_exists('pll_set_post_language')) {
                        pll_set_post_language($post_id, $language);
                    }

                    $changed++;
                }
            }

            fclose($handle);
        }
    }

    set_transient(
        'pscore_seo_notice_' . get_current_user_id(),
        array('changed' => $changed, 'errors' => $errors, 'import' => true),
        MINUTE_IN_SECONDS
    );

    wp_safe_redirect(admin_url('admin.php?page=pscore-seo-manager'));
    exit;
});

add_action('admin_post_pscore_save_seo', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.'));
    }

    check_admin_referer('pscore_save_seo_action', 'pscore_seo_nonce');

    $submitted_pages = isset($_POST['pages']) && is_array($_POST['pages'])
        ? wp_unslash($_POST['pages'])
        : array();

    $changed_pages = 0;
    $errors = array();

    foreach ($submitted_pages as $post_id => $data) {
        if (!is_array($data)) {
            continue;
        }

        $post_id = absint($post_id);
        $page = $post_id ? get_post($post_id) : null;

        if (
            !$page ||
            'publish' !== $page->post_status ||
            !in_array($page->post_type, array('page', 'post'), true) ||
            !current_user_can('edit_post', $post_id)
        ) {
            continue;
        }

        $page_changed = false;
        $post_update = array('ID' => $post_id);

        if (isset($data['page_title'])) {
            $new_title = sanitize_text_field($data['page_title']);

            if ('' === $new_title) {
                $errors[] = sprintf('Page #%d: The WordPress page title cannot be empty.', $post_id);
            } elseif ($new_title !== $page->post_title) {
                $post_update['post_title'] = $new_title;
                $page_changed = true;
            }
        }

        if (isset($data['slug'])) {
            $new_slug = sanitize_title($data['slug']);

            if ('' === $new_slug) {
                $errors[] = sprintf('Page #%d: The URL slug cannot be empty.', $post_id);
            } elseif ($new_slug !== $page->post_name) {
                $post_update['post_name'] = $new_slug;
                $page_changed = true;
            }
        }

        if (count($post_update) > 1) {
            $result = wp_update_post(wp_slash($post_update), true);

            if (is_wp_error($result)) {
                $errors[] = sprintf(
                    'Page #%d could not be updated: %s',
                    $post_id,
                    $result->get_error_message()
                );
                continue;
            }
        }

        if (isset($data['seo_title'])) {
            $seo_title = sanitize_text_field($data['seo_title']);
            $old_seo_title = (string) get_post_meta($post_id, 'rank_math_title', true);

            if ($seo_title !== $old_seo_title) {
                if ('' === $seo_title) {
                    delete_post_meta($post_id, 'rank_math_title');
                } else {
                    update_post_meta($post_id, 'rank_math_title', $seo_title);
                }
                $page_changed = true;
            }
        }

        if (isset($data['seo_description'])) {
            $seo_description = sanitize_textarea_field($data['seo_description']);
            $old_seo_description = (string) get_post_meta($post_id, 'rank_math_description', true);

            if ($seo_description !== $old_seo_description) {
                if ('' === $seo_description) {
                    delete_post_meta($post_id, 'rank_math_description');
                } else {
                    update_post_meta($post_id, 'rank_math_description', $seo_description);
                }
                $page_changed = true;
            }
        }

        if (isset($data['focus_keyword'])) {
            $focus_keyword = sanitize_text_field($data['focus_keyword']);
            $old_focus_keyword = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);

            if ($focus_keyword !== $old_focus_keyword) {
                if ('' === $focus_keyword) {
                    delete_post_meta($post_id, 'rank_math_focus_keyword');
                } else {
                    update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
                }
                $page_changed = true;
            }
        }

        if ($page_changed) {
            $changed_pages++;
        }
    }

    set_transient(
        'pscore_seo_notice_' . get_current_user_id(),
        array(
            'changed' => $changed_pages,
            'errors'  => $errors,
        ),
        MINUTE_IN_SECONDS
    );

    $redirect_args = array('page' => 'pscore-seo-manager');
    $return_language = isset($_POST['current_language'])
        ? sanitize_key(wp_unslash($_POST['current_language']))
        : '';
    if ($return_language && isset(pscore_seo_languages()[$return_language])) {
        $redirect_args['language'] = $return_language;
    }
    $return_content_type = isset($_POST['current_content_type'])
        ? sanitize_key(wp_unslash($_POST['current_content_type']))
        : '';
    if (in_array($return_content_type, array('page', 'post'), true)) {
        $redirect_args['content_type'] = $return_content_type;
    }

    wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
    exit;
});

function pscore_render_seo_manager() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
    if ('usage' === $view) {
        pscore_render_seo_usage_instructions();
        return;
    }

    $languages = pscore_seo_languages();
    $selected_language = isset($_GET['language']) ? sanitize_key(wp_unslash($_GET['language'])) : '';
    if ($selected_language && !isset($languages[$selected_language])) {
        $selected_language = '';
    }
    $selected_content_type = isset($_GET['content_type']) ? sanitize_key(wp_unslash($_GET['content_type'])) : '';
    if (!in_array($selected_content_type, array('page', 'post'), true)) {
        $selected_content_type = '';
    }
    $pages = pscore_seo_get_content($selected_language, $selected_content_type);

    $notice_key = 'pscore_seo_notice_' . get_current_user_id();
    $notice = get_transient($notice_key);
    delete_transient($notice_key);
    ?>

    <div class="wrap pscore-seo-manager">
        <h1>PSCORE Page &amp; SEO Manager</h1>

        <p>
            Edit published WordPress page/post titles, URL slugs, Rank Math SEO titles,
            meta descriptions and focus keywords in one place.
        </p>

        <?php if (is_array($notice)) : ?>
            <?php if (!empty($notice['changed'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html((int) $notice['changed']); ?> item(s) updated successfully.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($notice['errors'])) : ?>
                <div class="notice notice-error">
                    <?php foreach ($notice['errors'] as $error) : ?>
                        <p><?php echo esc_html($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="notice notice-warning inline">
            <p>
                <strong>URL warning:</strong> Changing a slug changes the public URL. Verify that Rank Math's
                <em>Auto Post Redirect</em> option is enabled or create a 301 redirect from the old URL.
            </p>
        </div>

        <div class="pscore-toolbar">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="pscore-seo-manager">
                <label for="pscore-language-filter"><strong>Language:</strong></label>
                <select id="pscore-language-filter" name="language">
                    <option value="">All languages</option>
                    <?php foreach ($languages as $slug => $name) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_language, $slug); ?>>
                            <?php echo esc_html($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="pscore-content-type-filter"><strong>Content type:</strong></label>
                <select id="pscore-content-type-filter" name="content_type">
                    <option value="">Pages and posts</option>
                    <option value="page" <?php selected($selected_content_type, 'page'); ?>>Pages only</option>
                    <option value="post" <?php selected($selected_content_type, 'post'); ?>>Posts only</option>
                </select>
                <button type="submit" class="button">Filter</button>
            </form>

            <a class="button" href="<?php echo esc_url(wp_nonce_url(
                add_query_arg(
                    array(
                        'action'       => 'pscore_export_seo',
                        'language'     => $selected_language,
                        'content_type' => $selected_content_type,
                    ),
                    admin_url('admin-post.php')
                ),
                'pscore_export_seo'
            )); ?>">Export current filter as CSV</a>

            <a class="button" href="<?php echo esc_url(add_query_arg(
                array('page' => 'pscore-seo-manager', 'view' => 'usage'),
                admin_url('admin.php')
            )); ?>">Usage Instructions</a>
        </div>

        <details class="pscore-import-panel">
            <summary><strong>Import CSV and overwrite data</strong></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="pscore_import_seo">
                <?php wp_nonce_field('pscore_import_seo_action', 'pscore_import_nonce'); ?>
                <p>
                    Import an exported CSV. For every included ID, the WordPress title, slug, language and
                    Rank Math title, meta description and focus keyword are overwritten. Older exports that
                    contain a permalink column remain importable; that column is ignored.
                    Published pages/posts not included in the CSV are not changed.
                </p>
                <p><input type="file" name="seo_csv" accept=".csv,text/csv" required></p>
                <p>
                    <label>
                        <input type="checkbox" name="confirm_import" value="1" required>
                        I understand that the CSV values will overwrite the existing data for every included row.
                    </label>
                </p>
                <button type="submit" class="button button-secondary">Import and overwrite</button>
            </form>
        </details>

        <div class="notice notice-info inline">
            <p>
                <strong>Elementor note:</strong> “WordPress Page Title” changes the native page title.
                A manually typed Elementor Heading widget is separate and will not be rewritten by this tool.
            </p>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pscore_save_seo">
            <input type="hidden" name="current_language" value="<?php echo esc_attr($selected_language); ?>">
            <input type="hidden" name="current_content_type" value="<?php echo esc_attr($selected_content_type); ?>">
            <?php wp_nonce_field('pscore_save_seo_action', 'pscore_seo_nonce'); ?>

            <div class="pscore-table-scroll">
                <table class="widefat striped pscore-seo-table">
                    <thead>
                        <tr>
                            <th class="column-id">ID</th>
                            <th class="column-title">WordPress Page Title</th>
                            <th class="column-type">Type / Language</th>
                            <th class="column-status">Status</th>
                            <th class="column-slug">URL Slug</th>
                            <th class="column-seo">Rank Math Tab/SEO Title</th>
                            <th class="column-description">Rank Math Meta Description</th>
                            <th class="column-keyword">Rank Math Focus Keyword</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page) :
                            $seo_title = (string) get_post_meta($page->ID, 'rank_math_title', true);
                            $seo_description = (string) get_post_meta($page->ID, 'rank_math_description', true);
                            $focus_keyword = (string) get_post_meta($page->ID, 'rank_math_focus_keyword', true);
                            $status = get_post_status_object($page->post_status);
                            ?>
                            <tr data-page-id="<?php echo esc_attr($page->ID); ?>">
                                <td><?php echo esc_html($page->ID); ?></td>

                                <td>
                                    <input
                                        type="text"
                                        class="regular-text pscore-page-title"
                                        name="pages[<?php echo esc_attr($page->ID); ?>][page_title]"
                                        value="<?php echo esc_attr($page->post_title); ?>"
                                        required
                                    >
                                    <div class="row-actions visible">
                                        <a href="<?php echo esc_url(get_edit_post_link($page->ID)); ?>" target="_blank" rel="noopener">Edit page</a>
                                        |
                                        <a href="<?php echo esc_url(get_permalink($page->ID)); ?>" target="_blank" rel="noopener">View</a>
                                    </div>
                                </td>

                                <td>
                                    <strong><?php echo esc_html(ucfirst($page->post_type)); ?></strong><br>
                                    <?php
                                    $page_language = pscore_seo_post_language($page->ID);
                                    echo esc_html($page_language && isset($languages[$page_language])
                                        ? $languages[$page_language] . ' (' . $page_language . ')'
                                        : ($page_language ?: 'Not assigned'));
                                    ?>
                                </td>

                                <td><?php echo $status ? esc_html($status->label) : ''; ?></td>

                                <td>
                                    <input
                                        type="text"
                                        class="regular-text pscore-slug"
                                        name="pages[<?php echo esc_attr($page->ID); ?>][slug]"
                                        value="<?php echo esc_attr($page->post_name); ?>"
                                        required
                                    >
                                    <?php if ($page->post_parent) : ?>
                                        <p class="description">Parent path: /<?php echo esc_html(trim(get_page_uri($page->post_parent), '/')); ?>/</p>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <input
                                        type="text"
                                        class="large-text pscore-seo-title"
                                        name="pages[<?php echo esc_attr($page->ID); ?>][seo_title]"
                                        value="<?php echo esc_attr($seo_title); ?>"
                                        placeholder="Blank = Rank Math global page-title template"
                                        maxlength="200"
                                    >
                                    <div class="pscore-title-meta">
                                        <span class="pscore-title-counter"><?php echo esc_html(mb_strlen($seo_title)); ?> characters</span>
                                        <span class="pscore-title-source">
                                            <?php echo '' === $seo_title
                                                ? 'Using Rank Math fallback (normally based on %title%).'
                                                : 'Custom Rank Math title override.'; ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <textarea
                                        class="large-text pscore-seo-description"
                                        name="pages[<?php echo esc_attr($page->ID); ?>][seo_description]"
                                        rows="4"
                                        placeholder="Blank = Rank Math global description template"
                                        maxlength="500"
                                    ><?php echo esc_textarea($seo_description); ?></textarea>
                                    <div class="pscore-field-meta">
                                        <span class="pscore-description-counter"><?php echo esc_html(mb_strlen($seo_description)); ?> characters</span>
                                    </div>
                                </td>

                                <td>
                                    <input
                                        type="text"
                                        class="large-text pscore-focus-keyword"
                                        name="pages[<?php echo esc_attr($page->ID); ?>][focus_keyword]"
                                        value="<?php echo esc_attr($focus_keyword); ?>"
                                        placeholder="e.g. North Korean human rights"
                                        maxlength="500"
                                    >
                                    <p class="description">Separate multiple keywords with commas.</p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="submit pscore-sticky-save">
                <button type="submit" class="button button-primary button-large">Save Page &amp; SEO Changes</button>
            </p>
        </form>
    </div>

    <style>
        .pscore-table-scroll { margin-top: 20px; overflow-x: auto; }
        .pscore-seo-table { min-width: 1750px; table-layout: fixed; }
        .pscore-seo-table th, .pscore-seo-table td { padding: 12px 10px; vertical-align: top; }
        .pscore-seo-table input, .pscore-seo-table textarea { box-sizing: border-box; width: 100%; }
        .pscore-seo-table .column-id { width: 55px; }
        .pscore-seo-table .column-title { width: 250px; }
        .pscore-seo-table .column-type { width: 135px; }
        .pscore-seo-table .column-status { width: 90px; }
        .pscore-seo-table .column-slug { width: 210px; }
        .pscore-seo-table .column-seo { width: 400px; }
        .pscore-seo-table .column-description { width: 420px; }
        .pscore-seo-table .column-keyword { width: 260px; }
        .pscore-title-meta { display: flex; justify-content: space-between; gap: 12px; margin-top: 6px; color: #646970; }
        .pscore-field-meta { margin-top: 6px; color: #646970; }
        .pscore-title-source { text-align: right; }
        .pscore-toolbar { display: flex; align-items: center; gap: 12px; margin: 20px 0 12px; }
        .pscore-toolbar form { display: flex; align-items: center; gap: 8px; margin: 0; }
        .pscore-import-panel { max-width: 900px; margin: 12px 0 20px; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; }
        .pscore-import-panel summary { cursor: pointer; }
        .pscore-sticky-save { position: sticky; bottom: 0; z-index: 10; margin: 0; padding: 15px; background: #fff; border-top: 1px solid #dcdcde; box-shadow: 0 -2px 8px rgba(0,0,0,.06); }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function updateCounter(input) {
                const counter = input.closest('td').querySelector('.pscore-title-counter');
                const source = input.closest('td').querySelector('.pscore-title-source');
                const length = input.value.length;

                counter.textContent = length + ' characters';
                counter.style.color = length > 60 ? '#b32d2e' : (length >= 45 ? '#008a20' : '#646970');
                source.textContent = length
                    ? 'Custom Rank Math title override.'
                    : 'Using Rank Math fallback (normally based on %title%).';
            }

            function updateDescriptionCounter(input) {
                const counter = input.closest('td').querySelector('.pscore-description-counter');
                const length = input.value.length;

                counter.textContent = length + ' characters';
                counter.style.color = length > 160 ? '#b32d2e' : (length >= 120 ? '#008a20' : '#646970');
            }

            document.querySelectorAll('.pscore-seo-title').forEach(function (input) {
                updateCounter(input);
                input.addEventListener('input', function () { updateCounter(input); });
            });

            document.querySelectorAll('.pscore-seo-description').forEach(function (input) {
                updateDescriptionCounter(input);
                input.addEventListener('input', function () { updateDescriptionCounter(input); });
            });

        });
    </script>
    <?php
}

function pscore_seo_markdown_inline($text) {
    $text = esc_html($text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
}

function pscore_seo_markdown_to_html($markdown) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $markdown);
    $html = '';
    $open_list = '';

    foreach ($lines as $line) {
        $line = trim($line);

        if ('' === $line) {
            if ($open_list) {
                $html .= '</' . $open_list . '>';
                $open_list = '';
            }
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $matches)) {
            if ($open_list) {
                $html .= '</' . $open_list . '>';
                $open_list = '';
            }
            $level = strlen($matches[1]);
            $html .= '<h' . $level . '>' . pscore_seo_markdown_inline($matches[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
            if ('ul' !== $open_list) {
                if ($open_list) {
                    $html .= '</' . $open_list . '>';
                }
                $html .= '<ul>';
                $open_list = 'ul';
            }
            $html .= '<li>' . pscore_seo_markdown_inline($matches[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
            if ('ol' !== $open_list) {
                if ($open_list) {
                    $html .= '</' . $open_list . '>';
                }
                $html .= '<ol>';
                $open_list = 'ol';
            }
            $html .= '<li>' . pscore_seo_markdown_inline($matches[1]) . '</li>';
            continue;
        }

        if ($open_list) {
            $html .= '</' . $open_list . '>';
            $open_list = '';
        }
        $html .= '<p>' . pscore_seo_markdown_inline($line) . '</p>';
    }

    if ($open_list) {
        $html .= '</' . $open_list . '>';
    }

    return wp_kses_post($html);
}

function pscore_render_seo_usage_instructions() {
    $manual_path = plugin_dir_path(__FILE__) . 'PSCORE-SEO-Manager-Usage-Instructions.md';
    $manual = is_readable($manual_path) ? file_get_contents($manual_path) : false;
    ?>
    <div class="wrap pscore-seo-manager pscore-seo-help">
        <p>
            <a class="button" href="<?php echo esc_url(add_query_arg(
                array('page' => 'pscore-seo-manager'),
                admin_url('admin.php')
            )); ?>">← Back to SEO Manager</a>
        </p>

        <?php if (false === $manual) : ?>
            <div class="notice notice-error inline">
                <p>The usage instructions file is missing. Upload <code>PSCORE-SEO-Manager-Usage-Instructions.md</code> to the plugin directory.</p>
            </div>
        <?php else : ?>
            <div class="card" style="max-width: 900px; padding: 20px 24px;">
                <?php echo pscore_seo_markdown_to_html($manual); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
