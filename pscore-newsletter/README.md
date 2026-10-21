# PSCORE Newsletter

PSCORE Newsletter is a standalone WordPress plugin for subscriber lists, configurable subscription forms, Gutenberg-based HTML newsletters, recurring schedules, queue processing, double opt-in, and unsubscribe handling.

This README documents installation, architecture, storage, rendering, and maintenance. End-user instructions are stored separately in `PSCORE-Newsletter-Usage-Instructions.md` and are available from the WordPress administration screen.

## Requirements

- WordPress with the Gutenberg block editor
- PHP with the DOM extension enabled
- A working WordPress mail transport, preferably WP Mail SMTP
- WordPress Cron or an external request to `wp-cron.php`

## Installation

Upload the complete `pscore-newsletter` directory to:

`wp-content/plugins/pscore-newsletter`

Activate **PSCORE Newsletter** in WordPress. Activation creates the custom database tables, a default list and form, and the recurring cron event.

When updating, replace the complete plugin directory. Keep the Markdown manual and `assets/social-icons` directory with the PHP files.

## Main components

- `pscore-newsletter.php`: plugin bootstrap, constants, class loading, and activation hooks
- `includes/class-pscore-nl-install.php`: schema installation, upgrades, and defaults
- `includes/class-pscore-nl-plugin.php`: shared settings, table names, placeholders, and initialization
- `includes/class-pscore-nl-admin.php`: administrative screens and Markdown manual rendering
- `includes/class-pscore-nl-public.php`: public subscription form, confirmation, and unsubscribe endpoints
- `includes/class-pscore-nl-editor.php`: newsletter post type, Gutenberg blocks, REST preview data, and email-safe block rendering
- `includes/class-pscore-nl-scheduler.php`: schedules, queue creation, batching, retries, and email delivery
- `includes/class-pscore-nl-css-inliner.php`: conversion of supported CSS selectors to inline styles
- `assets/posts-block.js`: Recent Posts and Social Icons as Images editor blocks

## Database tables

Tables use the active WordPress table prefix:

- `{prefix}pscore_nl_lists`
- `{prefix}pscore_nl_subscribers`
- `{prefix}pscore_nl_subscriber_lists`
- `{prefix}pscore_nl_forms`
- `{prefix}pscore_nl_queue`

The database schema is managed with `dbDelta()`. The installed schema version is stored in the `pscore_nl_db_version` option. Plugin deactivation does not delete newsletter data.

## Newsletter data model

Campaigns use the private post type `pscore_email` with Gutenberg content and revisions.

Delivery settings are stored as post meta:

- `_pscore_nl_subject`
- `_pscore_nl_list_ids`
- `_pscore_nl_frequency`
- `_pscore_nl_schedule_status`
- `_pscore_nl_next_run`
- `_pscore_nl_last_run`
- `_pscore_nl_custom_css`

Supported frequencies are `once`, `weekly`, `monthly`, and `quarterly`. Schedule states are `draft`, `active`, `paused`, and the generated `completed` state for finished one-time campaigns.

## Queue and cron

The plugin registers `pscore_nl_process` on a five-minute WordPress Cron interval.

Each due campaign creates one unique queue item per confirmed subscriber and scheduled run. Queue uniqueness is enforced with the campaign ID, subscriber ID, and run key. Each cron execution processes up to the configured batch size.

Failed `wp_mail()` calls are retried after 15 minutes, up to three attempts. Pending queue entries are cancelled when the subscriber is no longer confirmed.

For an external cron service, request:

`https://YOUR-DOMAIN/wp-cron.php?doing_wp_cron`

The plugin sends through `wp_mail()`; SMTP delivery is delegated to the active WordPress mail plugin.

## Subscription flow

Forms are embedded with:

`[pscore_newsletter_form id="1"]`

The public handler validates a nonce, email address, consent checkbox, form ID, and honeypot field. With double opt-in enabled, the subscriber and list memberships remain pending until the confirmation token is opened. Confirmation and unsubscribe tokens are unique per subscriber.

Unsubscribing updates both the subscriber and subscriber-list membership states. The scheduler also excludes or cancels unconfirmed recipients.

## Dynamic Gutenberg blocks

### Recent Posts

The registered block name remains `pscore-newsletter/posts` for compatibility. It queries published posts during server-side rendering rather than storing selected posts in the campaign.

The post-type list includes the configured PSCORE types and public, UI-visible custom post types. Developers can alter it with:

`pscore_nl_content_post_types`

Queries use `suppress_filters => false`, allowing language and other WordPress query filters to participate.

Output is a presentation table with inline styles for broad email-client support.

### Social Icons as Images

The block name is `pscore-newsletter/social-icons`. It renders local PNG assets as linked table cells. This avoids the inline SVG and frontend stylesheet dependencies of the standard Gutenberg Social Icons block.

Icon assets are located in:

`assets/social-icons`

## Email rendering

Campaign content passes through WordPress `the_content`, then subscriber placeholders are replaced. If the original campaign does not contain `{unsubscribe_url}`, an unsubscribe section is appended automatically.

The email is wrapped in an HTML document and sent with a UTF-8 HTML content-type header.

Supported custom CSS rules are converted to inline styles with `DOMDocument` and `DOMXPath`. The inliner supports simple tag, class, ID, and descendant selectors. Pseudo-classes, attribute selectors, combinators, JavaScript behavior, and advanced browser layouts are not reliable for email.

## Placeholders

The replacement system supports:

- `{name}`
- `{first_name}`
- `{last_name}`
- `{email}`
- `{site_name}`
- `{site_url}`
- `{unsubscribe_url}`

The confirmation message additionally supports `{confirmation_url}`.

## REST API

`GET /wp-json/pscore-newsletter/v1/posts`

The endpoint requires permission to edit posts and is used only for Recent Posts previews in the block editor. Parameters:

- `type`: registered supported post type or `any`
- `search`: optional title/content search
- `per_page`: 1–20 preview results

## Security

- Administrative changes require `manage_options`.
- Form and administrative submissions use WordPress nonces.
- User input is sanitized before storage.
- URLs and HTML attributes are escaped during rendering.
- The subscription form includes a honeypot and mandatory consent checkbox.
- The Markdown manual is converted by a limited renderer and passed through `wp_kses_post()`.

## Development checks

Before deploying:

1. Run PHP syntax checks on every PHP file.
2. Run `node --check assets/posts-block.js`.
3. Confirm that all PNG files in `assets/social-icons` are deployed.
4. Test subscription, confirmation, unsubscribe, preview, a one-time campaign, and a recurring campaign.
5. Verify Cron and WP Mail SMTP on the production environment.

## User documentation

Administrators can open **PSCORE Newsletter → Overview → Usage Instructions**. The displayed content is loaded directly from `PSCORE-Newsletter-Usage-Instructions.md`; the instructions are not duplicated in PHP.
