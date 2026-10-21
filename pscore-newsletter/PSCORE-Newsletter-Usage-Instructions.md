# PSCORE Newsletter – Usage Instructions

This guide explains how to create, preview, and schedule a newsletter correctly.

## Before creating a newsletter

Make sure the initial setup has already been completed:

- At least one recipient list exists under **PSCORE Newsletter → Lists**.
- Subscribers have joined that list and have a **confirmed** status.
- The sender information under **PSCORE Newsletter → Settings** is correct.
- WP Mail SMTP is working.
- WordPress Cron or the external cron service is running.

## Create a newsletter

1. Open **PSCORE Newsletter → Newsletters**.
2. Click **Add newsletter**.
3. Enter a clear WordPress title. This identifies the newsletter in the administration area.
4. Build the email in the Gutenberg editor.
5. Open the **Newsletter delivery** panel on the right.
6. Enter the subject recipients should see. If this field is empty, the WordPress title is used.
7. Select every recipient list that should receive the email.
8. Keep **Automatic sending** inactive until the content and schedule have been checked.
9. Click **Publish** or **Update** to save the newsletter.

## Build email content

Use simple Gutenberg blocks such as:

- Heading
- Paragraph
- Image
- Button
- Columns when the email remains readable without advanced browser CSS
- Recent Posts
- Social Icons as Images

Avoid interactive or website-only elements. Email clients do not reliably support JavaScript, sliders, animation, hover effects, forms, video players, or inline SVG.

## Add dynamic recent posts

Insert the **Recent Posts** block when the newsletter should display the newest published content automatically.

1. Select the required post type, such as Posts, Newsletters, Reports, Memoirs, or another available custom post type.
2. Select **All supported post types** only when different content types may be mixed by publication date.
3. Choose the number of posts to display.
4. Decide whether to show the featured image, date, and excerpt.
5. Set the button label.
6. Adjust the image width, spacing, colors, font sizes, and title weight.

Recent Posts is dynamic. The plugin fetches the newest matching published posts when the preview or email is generated. A recurring newsletter therefore updates automatically when newer posts are published.

Add separate Recent Posts blocks when each content type should have its own section.

## Add social profile links

Use **Social Icons as Images**, not the standard Gutenberg **Social Icons** block. The standard block uses SVG icons and Gutenberg website styles that may disappear in email clients.

1. Insert **Social Icons as Images**.
2. Check the Instagram, Facebook, X, YouTube, and LinkedIn URLs.
3. Remove a URL to hide that icon.
4. Choose the icon size, spacing, and alignment.

## Personalize the email

These placeholders can be added to the subject or content:

- `{name}`
- `{first_name}`
- `{last_name}`
- `{email}`
- `{site_name}`
- `{site_url}`
- `{unsubscribe_url}`

The plugin replaces them separately for every recipient.

An unsubscribe link is added automatically when `{unsubscribe_url}` is not already included in the newsletter.

## Preview the newsletter

Always save the newsletter before opening the preview.

1. Click **Preview newsletter** in the **Newsletter delivery** panel.
2. Check the subject shown in the preview bar.
3. Check all headings, paragraphs, images, buttons, and links.
4. Confirm that Recent Posts displays the expected content.
5. Confirm that every social icon opens the correct PSCORE profile.
6. Check the unsubscribe section.
7. Check the newsletter at a narrow browser width as well as desktop width.

The browser preview is a useful approximation. Gmail, Outlook, Apple Mail, and mobile applications can still display some details differently.

## Schedule the newsletter

Only activate sending after the preview has been checked.

Available frequencies are:

- **Once** sends one campaign.
- **Weekly** repeats every week.
- **Monthly** repeats every month.
- **Quarterly** repeats every three months.

1. Select the correct recipient lists.
2. Choose the required frequency.
3. Set **First/next send** to the required date and time.
4. Check the WordPress timezone shown below the date field.
5. Set **Automatic sending** to **Active**.
6. Click **Publish** or **Update**.

The newsletter must be both published and active. A draft newsletter is never sent, even when a date is entered.

## What happens at send time

When the newsletter becomes due:

1. The plugin finds confirmed subscribers in the selected lists.
2. It creates the delivery queue.
3. Dynamic Recent Posts content is fetched from the database.
4. Personalization placeholders are replaced for each subscriber.
5. Emails are sent in batches through WordPress and WP Mail SMTP.

A one-time newsletter changes to completed after its queue is created. A recurring newsletter receives its next scheduled date automatically.

Large recipient lists can require several cron runs. Do not delete or deactivate the newsletter while its queue is being processed.

## Pause or change a scheduled newsletter

- Set **Automatic sending** to **Paused** to prevent the next campaign from being created.
- Set it to **Inactive** when the newsletter should no longer send automatically.
- After changing content, lists, frequency, or date, click **Update**.
- Preview the newsletter again after important content or design changes.

Pausing a newsletter does not recall emails that have already been sent.

## Use custom email CSS

Use the **Custom email CSS** field only when the block settings are not sufficient.

Enter CSS rules without `<style>` tags, for example:

`.pscore-recent-post-title a { color: #082b68; }`

Prefer simple tag and class selectors. Email clients remove many advanced CSS features. Styling configured directly in Recent Posts and Social Icons as Images is more reliable.

## Final checklist

Before setting **Automatic sending** to Active, confirm:

- The newsletter is published.
- The email subject is correct.
- The correct recipient lists are selected.
- The frequency is correct.
- The send date, time, and timezone are correct.
- Recent Posts shows the intended post type and number of posts.
- All images load.
- All buttons and social links use the correct URLs.
- Personalization placeholders are spelled correctly.
- The unsubscribe link is present.
- WP Mail SMTP and Cron are working.

## Common problems

- **Nothing is sent:** Check publication status, Automatic sending, scheduled time, selected lists, confirmed subscribers, Cron, and WP Mail SMTP.
- **Recent Posts is empty:** Check that the selected post type contains published posts.
- **Unexpected posts appear:** Check whether **All supported post types** is selected.
- **Social icons appear as text or bullets:** Remove the standard Gutenberg Social Icons block and use Social Icons as Images.
- **An image does not appear:** Check that it is publicly available over HTTPS.
- **The design changes in Gmail:** Use simple layouts and the provided block styling controls. Website CSS is not fully supported in email.
- **A recipient does not receive the newsletter:** Confirm that the address and its selected list membership are confirmed and not unsubscribed.
