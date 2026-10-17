# PSCORE SEO Manager – Usage Instructions

The PSCORE SEO Manager lets administrators edit published English and Korean pages and posts from one screen.

## Available fields

- WordPress page or post title
- URL slug
- Rank Math SEO title
- Rank Math meta description
- Rank Math focus keyword

## Filter the list

Use the **Language** and **Content type** filters to display:

- All languages, English, or Korean
- Pages and posts, pages only, or posts only

The CSV export always contains the entries shown by the current filters.

## Edit entries manually

1. Open **Dashboard → PSCORE SEO**.
2. Select the required filters.
3. Edit one or more fields directly in the table.
4. Review the changes.
5. Click **Save Page & SEO Changes**.

Manual changes are not stored until the save button is clicked.

## Edit many entries with CSV and AI

1. Select the required language and content type.
2. Click **Export current filter as CSV**.
3. Keep an unchanged backup copy of the exported file.
4. Edit the CSV in a spreadsheet application, or provide it to an AI tool for help with SEO titles, meta descriptions, and focus keywords.
5. Keep all column headers and IDs unchanged. Do not delete required columns.
6. Return to **Dashboard → PSCORE SEO**.
7. Open **Import CSV and overwrite data**.
8. Select the edited CSV and confirm the overwrite warning.
9. Click **Import and overwrite**.

The CSV upload saves all included entries automatically. Importing can take some time, especially for a large CSV. Wait for the result message and do not close or reload the page while the import is running.

Published pages and posts that are not included in the imported CSV are not changed.

## Important notes

- Importing overwrites the editable values for every included ID.
- Do not change IDs or the `post_type` value.
- Keep required CSV columns and their header names unchanged.
- Changing a URL slug changes the public address of the page or post. Confirm that Rank Math creates a suitable redirect, or add a 301 redirect manually.
- The WordPress title is separate from a heading entered manually in Elementor. The SEO Manager does not rewrite Elementor heading widgets.
- Empty Rank Math fields remove the corresponding custom override and allow Rank Math's configured fallback where applicable.
