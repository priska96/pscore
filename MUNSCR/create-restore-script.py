from pathlib import Path
from collections import defaultdict
import re
import json
import base64
import hashlib

# ============================================================
# CONFIG
# ============================================================

DUMP_FILE = "backup_2023-05-07-1924_MUNSCR_20e48e76e600-db"
OUTPUT_FILE = "restore-munscr-pages.php"

TARGET_PAGES = {
    "249": {
        "label": "ENGLISH",
        "new_slug": "welcome-to-munscr-en-restored",
    },
    "1838": {
        "label": "KOREAN",
        "new_slug": "welcome-to-munscr-restored",
    },
}

# These should NOT be copied to the restored page.
SKIP_META_KEYS = {
    "_edit_lock",
    "_edit_last",
}

POST_COLUMNS = [
    "ID",
    "post_author",
    "post_date",
    "post_date_gmt",
    "post_content",
    "post_title",
    "post_excerpt",
    "post_status",
    "comment_status",
    "ping_status",
    "post_password",
    "post_name",
    "to_ping",
    "pinged",
    "post_modified",
    "post_modified_gmt",
    "post_content_filtered",
    "post_parent",
    "guid",
    "menu_order",
    "post_type",
    "post_mime_type",
    "comment_count",
]

POSTMETA_COLUMNS = [
    "meta_id",
    "post_id",
    "meta_key",
    "meta_value",
]


# ============================================================
# SQL PARSER
# ============================================================

def split_sql_tuple(content):
    values = []
    current = []

    in_string = False
    escaped = False

    i = 0

    while i < len(content):
        char = content[i]

        if escaped:
            current.append(char)
            escaped = False

        elif char == "\\" and in_string:
            current.append(char)
            escaped = True

        elif char == "'":
            current.append(char)

            # SQL escaped quote: ''
            if (
                in_string
                and i + 1 < len(content)
                and content[i + 1] == "'"
            ):
                current.append("'")
                i += 1
            else:
                in_string = not in_string

        elif char == "," and not in_string:
            values.append("".join(current).strip())
            current = []

        else:
            current.append(char)

        i += 1

    values.append("".join(current).strip())

    return values


def extract_tuples(values_block):
    tuples = []

    in_string = False
    escaped = False
    depth = 0
    start = None

    i = 0

    while i < len(values_block):
        char = values_block[i]

        if escaped:
            escaped = False

        elif char == "\\" and in_string:
            escaped = True

        elif char == "'":

            if (
                in_string
                and i + 1 < len(values_block)
                and values_block[i + 1] == "'"
            ):
                i += 1
            else:
                in_string = not in_string

        elif not in_string:

            if char == "(":
                if depth == 0:
                    start = i + 1
                depth += 1

            elif char == ")":
                depth -= 1

                if depth == 0 and start is not None:
                    tuples.append(values_block[start:i])
                    start = None

        i += 1

    return tuples


def clean_sql_value(value):
    value = value.strip()

    if value.upper() == "NULL":
        return None

    if (
        len(value) >= 2
        and value[0] == "'"
        and value[-1] == "'"
    ):
        value = value[1:-1]

        # MySQL dump escaping
        replacements = {
            "\\0": "\0",
            "\\n": "\n",
            "\\r": "\r",
            "\\t": "\t",
            "\\b": "\b",
            "\\Z": "\x1a",
        }

        result = []
        i = 0

        while i < len(value):

            if value[i] == "\\" and i + 1 < len(value):
                pair = value[i:i+2]

                if pair in replacements:
                    result.append(replacements[pair])
                    i += 2
                    continue

                next_char = value[i + 1]

                if next_char in ("'", '"', "\\"):
                    result.append(next_char)
                    i += 2
                    continue

            # SQL doubled quote
            if (
                value[i] == "'"
                and i + 1 < len(value)
                and value[i + 1] == "'"
            ):
                result.append("'")
                i += 2
                continue

            result.append(value[i])
            i += 1

        value = "".join(result)

    return value


def find_insert_blocks(sql, table):
    """
    Find INSERT INTO table ... VALUES (...) statements.
    """

    pattern = re.compile(
        rf"INSERT\s+INTO\s+`?{re.escape(table)}`?"
        rf"(?:\s*\([^;]*?\))?"
        rf"\s+VALUES\s*",
        re.IGNORECASE,
    )

    for match in pattern.finditer(sql):

        values_start = match.end()

        in_string = False
        escaped = False

        i = values_start

        while i < len(sql):
            char = sql[i]

            if escaped:
                escaped = False

            elif char == "\\" and in_string:
                escaped = True

            elif char == "'":

                if (
                    in_string
                    and i + 1 < len(sql)
                    and sql[i + 1] == "'"
                ):
                    i += 1
                else:
                    in_string = not in_string

            elif char == ";" and not in_string:
                break

            i += 1

        yield sql[values_start:i]


# ============================================================
# LOAD DUMP
# ============================================================

dump_path = Path(DUMP_FILE)

if not dump_path.exists():
    raise FileNotFoundError(
        f"\n❌ Database dump not found:\n"
        f"{dump_path.resolve()}\n"
    )

print()
print("Reading:")
print(dump_path.resolve())
print()

sql = dump_path.read_text(
    encoding="utf-8",
    errors="replace",
)


# ============================================================
# EXTRACT POSTS
# ============================================================

posts = {}

for block in find_insert_blocks(sql, "wp_posts"):

    for tuple_content in extract_tuples(block):

        values = split_sql_tuple(tuple_content)

        if len(values) != len(POST_COLUMNS):
            continue

        record = {
            column: clean_sql_value(value)
            for column, value in zip(POST_COLUMNS, values)
        }

        post_id = str(record["ID"])

        if post_id not in TARGET_PAGES:
            continue

        if record["post_type"] != "page":
            continue

        posts[post_id] = record


# ============================================================
# VALIDATE POSTS
# ============================================================

missing_posts = [
    page_id
    for page_id in TARGET_PAGES
    if page_id not in posts
]

if missing_posts:
    raise RuntimeError(
        "\n❌ Could not find target page(s): "
        + ", ".join(missing_posts)
    )

print("Found source pages:")

for page_id, post in posts.items():
    print(
        f"  {page_id}: "
        f"{post['post_title']} "
        f"({post['post_name']})"
    )


# ============================================================
# EXTRACT POSTMETA
# ============================================================

all_meta = defaultdict(list)

for block in find_insert_blocks(sql, "wp_postmeta"):

    for tuple_content in extract_tuples(block):

        values = split_sql_tuple(tuple_content)

        if len(values) != len(POSTMETA_COLUMNS):
            continue

        record = {
            column: clean_sql_value(value)
            for column, value in zip(
                POSTMETA_COLUMNS,
                values,
            )
        }

        post_id = str(record["post_id"])

        if post_id in TARGET_PAGES:
            all_meta[post_id].append(record)


# ============================================================
# DEDUPLICATE META
# ============================================================

deduplicated_meta = {}

for page_id in TARGET_PAGES:

    grouped = defaultdict(list)

    for record in all_meta[page_id]:

        key = record["meta_key"]

        if key in SKIP_META_KEYS:
            continue

        grouped[key].append(record)

    final_meta = {}

    for key, records in grouped.items():

        unique_values = {}

        for record in records:

            value = record["meta_value"]

            if value is None:
                value = ""

            digest = hashlib.sha256(
                value.encode("utf-8")
            ).hexdigest()

            unique_values[digest] = value

        # IMPORTANT:
        # Never guess if values differ.
        if len(unique_values) > 1:
            print()
            print(
                f"❌ DIFFERENT VALUES FOUND"
            )
            print(
                f"Page ID: {page_id}"
            )
            print(
                f"Meta key: {key}"
            )
            print(
                f"Unique values: {len(unique_values)}"
            )

            raise RuntimeError(
                "Restore generator aborted because "
                "a duplicated meta key contains "
                "different values."
            )

        final_meta[key] = next(
            iter(unique_values.values())
        )

    deduplicated_meta[page_id] = final_meta


# ============================================================
# SHOW WHAT WILL BE EXPORTED
# ============================================================

print()
print("=" * 80)
print("RESTORE DATA")
print("=" * 80)

for page_id, config in TARGET_PAGES.items():

    post = posts[page_id]
    meta = deduplicated_meta[page_id]

    print()
    print(
        f"{config['label']} "
        f"(old ID {page_id})"
    )

    print(
        f"  Title: {post['post_title']}"
    )

    print(
        f"  Old slug: {post['post_name']}"
    )

    print(
        f"  New slug: {config['new_slug']}"
    )

    print(
        f"  post_content: "
        f"{len(post['post_content'] or ''):,} chars"
    )

    print(
        f"  unique meta keys: {len(meta)}"
    )

    if "vcv-pageContent" in meta:
        print(
            f"  vcv-pageContent: "
            f"{len(meta['vcv-pageContent']):,} chars"
        )
    else:
        print(
            "  ⚠️ vcv-pageContent NOT FOUND"
        )


# ============================================================
# PREPARE PAYLOAD
# ============================================================

payload = {}

for page_id, config in TARGET_PAGES.items():

    post = posts[page_id]

    payload[page_id] = {
        "source_id": int(page_id),
        "label": config["label"],
        "new_slug": config["new_slug"],

        "post": {
            "post_title": post["post_title"] or "",
            "post_content": post["post_content"] or "",
            "post_excerpt": post["post_excerpt"] or "",
            "comment_status": post["comment_status"] or "closed",
            "ping_status": post["ping_status"] or "closed",
            "menu_order": int(post["menu_order"] or 0),
        },

        "meta": deduplicated_meta[page_id],
    }


# ============================================================
# ENCODE PAYLOAD
# ============================================================

# JSON + Base64 avoids PHP quoting problems with:
# - HTML
# - quotes
# - newlines
# - Visual Composer JSON/data
# - Unicode/Korean
# - serialized-looking strings

payload_json = json.dumps(
    payload,
    ensure_ascii=False,
    separators=(",", ":"),
)

payload_base64 = base64.b64encode(
    payload_json.encode("utf-8")
).decode("ascii")


# ============================================================
# GENERATE PHP
# ============================================================

php = f"""<?php

/**
 * MUNSCR page restore
 *
 * GENERATED LOCALLY.
 *
 * Source pages:
 *   249  = English
 *   1838 = Korean
 *
 * IMPORTANT:
 * - Existing pages are NOT modified.
 * - Existing postmeta is NOT modified.
 * - New posts receive new WordPress IDs.
 * - New metadata receives new MySQL meta_ids.
 * - Restored pages are created as DRAFT.
 * - The script can only perform the restore once.
 *
 * DELETE THIS FILE FROM THE SERVER AFTER USE.
 */

declare(strict_types=1);


// ------------------------------------------------------------
// LOAD WORDPRESS
// ------------------------------------------------------------

require_once __DIR__ . '/wp-load.php';

if (!function_exists('wp_insert_post')) {{
    exit('ERROR: WordPress could not be loaded.');
}}


// ------------------------------------------------------------
// SECURITY
// ------------------------------------------------------------

if (!is_user_logged_in()) {{
    auth_redirect();
}}

if (!current_user_can('manage_options')) {{
    wp_die(
        'You must be logged in as an administrator.'
    );
}}


// ------------------------------------------------------------
// OUTPUT HELPERS
// ------------------------------------------------------------

header('Content-Type: text/html; charset=utf-8');

function out($text = '') {{
    echo htmlspecialchars(
        (string) $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    echo "<br>\\n";
}}

function heading($text) {{
    echo '<h2>' .
        htmlspecialchars(
            (string) $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ) .
        "</h2>\\n";
}}

function dump_meta_rows($post_id) {{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT meta_id, post_id, meta_key
            FROM {{$wpdb->postmeta}}
            WHERE post_id = %d
            ORDER BY meta_id ASC
            ",
            $post_id
        )
    );

    if (!$rows) {{
        out('No postmeta rows found.');
        return;
    }}

    foreach ($rows as $row) {{
        out(
            'meta_id=' . $row->meta_id .
            ' | post_id=' . $row->post_id .
            ' | key=' . $row->meta_key
        );
    }}
}}


// ------------------------------------------------------------
// RESTORE LOCK
// ------------------------------------------------------------

$restore_lock_key = 'munscr_restore_welcome_pages_2023_done';

if (get_option($restore_lock_key)) {{
    heading('RESTORE BLOCKED');

    out(
        'This restore script has already been executed.'
    );

    out(
        'No database changes were made during this request.'
    );

    out(
        'Delete this PHP file from the server.'
    );

    exit;
}}


// ------------------------------------------------------------
// PAYLOAD
// ------------------------------------------------------------

$payload_base64 = <<<'PAYLOAD'
{payload_base64}
PAYLOAD;

$json = base64_decode(
    preg_replace('/\\s+/', '', $payload_base64),
    true
);

if ($json === false) {{
    wp_die('Could not decode restore payload.');
}}

$data = json_decode($json, true);

if (!is_array($data)) {{
    wp_die(
        'Could not parse restore payload: ' .
        json_last_error_msg()
    );
}}


// ------------------------------------------------------------
// EXISTING SOURCE PAGES
// ------------------------------------------------------------

heading('EXISTING SOURCE PAGES — UNCHANGED');

$source_ids = [249, 1838];

foreach ($source_ids as $source_id) {{

    $source = get_post($source_id);

    echo '<hr>';

    if (!$source) {{
        heading(
            'Old page ID ' . $source_id .
            ' — NOT FOUND'
        );

        continue;
    }}

    heading(
        'Old page ID ' .
        $source_id .
        ' — ' .
        $source->post_title
    );

    out('Status: ' . $source->post_status);
    out('Slug: ' . $source->post_name);

    heading(
        'Existing postmeta IDs for old page ' .
        $source_id
    );

    dump_meta_rows($source_id);
}}


// ------------------------------------------------------------
// RESTORE
// ------------------------------------------------------------

heading('CREATING NEW RESTORED PAGES');

$created_posts = [];

foreach ($data as $source_id => $page) {{

    echo '<hr>';

    $label = $page['label'];
    $post_data = $page['post'];
    $meta = $page['meta'];

    heading(
        $label .
        ' — source ID ' .
        $source_id
    );


    // --------------------------------------------------------
    // Make sure the RESTORED slug does not already exist.
    // --------------------------------------------------------

    $existing = get_page_by_path(
        $page['new_slug'],
        OBJECT,
        'page'
    );

    if ($existing) {{
        wp_die(
            'ABORTED: A page with restored slug "' .
            esc_html($page['new_slug']) .
            '" already exists. Existing page ID: ' .
            intval($existing->ID) .
            '. Nothing else should be restored until this is reviewed.'
        );
    }}


    // --------------------------------------------------------
    // CREATE NEW PAGE
    // --------------------------------------------------------

    $new_post_id = wp_insert_post(
        [
            'post_type'      => 'page',
            'post_status'    => 'draft',

            'post_title'     =>
                $post_data['post_title'],

            'post_name'      =>
                $page['new_slug'],

            'post_content'   =>
                $post_data['post_content'],

            'post_excerpt'   =>
                $post_data['post_excerpt'],

            'comment_status' =>
                $post_data['comment_status'],

            'ping_status'    =>
                $post_data['ping_status'],

            'menu_order'     =>
                intval($post_data['menu_order']),

            'post_parent'    => 0,
        ],
        true
    );

    if (is_wp_error($new_post_id)) {{
        wp_die(
            'Could not create ' .
            esc_html($label) .
            ' page: ' .
            esc_html(
                $new_post_id->get_error_message()
            )
        );
    }}

    out(
        'NEW PAGE ID: ' .
        $new_post_id
    );

    out(
        'New slug: ' .
        $page['new_slug']
    );


    // --------------------------------------------------------
    // IMPORT META
    //
    // IMPORTANT:
    // add_post_meta() is used instead of update_post_meta().
    //
    // This guarantees that we are creating NEW metadata
    // instead of updating existing rows.
    //
    // $unique=true additionally prevents duplicate keys on
    // the newly created page.
    // --------------------------------------------------------

    foreach ($meta as $meta_key => $raw_value) {{

        // Old editor/session metadata should never be restored.
        if (
            $meta_key === '_edit_lock' ||
            $meta_key === '_edit_last'
        ) {{
            continue;
        }}

        /*
         * WordPress metadata API serializes arrays/objects.
         *
         * The dump value may itself contain serialized PHP.
         * maybe_unserialize() reconstructs that value first,
         * preventing an unnecessary second serialization layer.
         */
        $value = maybe_unserialize($raw_value);

        $added = add_post_meta(
            $new_post_id,
            $meta_key,
            $value,
            true
        );

        if ($added === false) {{
            out(
                'WARNING: Could not add meta key: ' .
                $meta_key
            );
        }}
    }}


    // --------------------------------------------------------
    // COLLECT NEW META IDS
    // --------------------------------------------------------

    global $wpdb;

    $new_meta_rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT meta_id, post_id, meta_key
            FROM {{$wpdb->postmeta}}
            WHERE post_id = %d
            ORDER BY meta_id ASC
            ",
            $new_post_id
        )
    );

    $created_posts[] = [
        'label' => $label,
        'source_id' => intval($source_id),
        'new_post_id' => intval($new_post_id),
        'slug' => $page['new_slug'],
        'meta_rows' => $new_meta_rows,
    ];

    heading(
        'New postmeta IDs for page ' .
        $new_post_id
    );

    if ($new_meta_rows) {{
        foreach ($new_meta_rows as $row) {{
            out(
                'meta_id=' .
                $row->meta_id .
                ' | post_id=' .
                $row->post_id .
                ' | key=' .
                $row->meta_key
            );
        }}
    }}
}}


// ------------------------------------------------------------
// ONLY SET LOCK AFTER BOTH PAGES WERE CREATED
// ------------------------------------------------------------

if (count($created_posts) === count($data)) {{

    add_option(
        $restore_lock_key,
        [
            'completed_at' => current_time('mysql'),
            'created_posts' => array_map(
                function ($item) {{
                    return [
                        'label' => $item['label'],
                        'source_id' => $item['source_id'],
                        'new_post_id' => $item['new_post_id'],
                        'slug' => $item['slug'],
                    ];
                }},
                $created_posts
            ),
        ],
        '',
        false
    );
}}


// ------------------------------------------------------------
// FINAL SUMMARY
// ------------------------------------------------------------

echo '<hr>';

heading('RESTORE COMPLETE');

foreach ($created_posts as $item) {{

    echo '<p>';

    echo '<strong>' .
        esc_html($item['label']) .
        '</strong><br>';

    echo 'Old page ID: ' .
        intval($item['source_id']) .
        '<br>';

    echo 'New page ID: <strong>' .
        intval($item['new_post_id']) .
        '</strong><br>';

    echo 'New slug: ' .
        esc_html($item['slug']) .
        '<br>';

    echo 'New postmeta rows: ' .
        count($item['meta_rows']);

    echo '</p>';
}}

echo '<hr>';

heading('NEXT STEPS');

out(
    '1. Do NOT delete the old pages yet.'
);

out(
    '2. Open the new restored pages in WordPress.'
);

out(
    '3. Try opening both pages with Visual Composer.'
);

out(
    '4. Check the frontend layout, images and links.'
);

out(
    '5. Keep a copy of the output above. It contains the old and new IDs.'
);

out(
    '6. Delete restore-munscr-pages.php from the server immediately after this test.'
);

out(
    '7. Only after confirming the restored pages work should the old pages/meta be cleaned up.'
);

?>
"""


# ============================================================
# WRITE PHP
# ============================================================

output_path = Path(OUTPUT_FILE)

output_path.write_text(
    php,
    encoding="utf-8",
)


# ============================================================
# FINAL LOCAL OUTPUT
# ============================================================

print()
print("=" * 80)
print("SUCCESS")
print("=" * 80)

print()
print("Created:")
print(output_path.resolve())

print()
print(
    f"File size: "
    f"{output_path.stat().st_size / 1024 / 1024:.2f} MB"
)

print()
print("The PHP restore will:")
print("  ✓ leave old pages 249 and 1838 untouched")
print("  ✓ print their existing postmeta IDs")
print("  ✓ create two NEW draft pages")
print("  ✓ use new WordPress post IDs")
print("  ✓ create new MySQL meta IDs")
print("  ✓ import one copy of each meta key")
print("  ✓ skip _edit_lock and _edit_last")
print("  ✓ print all newly created IDs")
print("  ✓ refuse to run a second time")

print()
print(
    "Next: upload restore-munscr-pages.php "
    "to the same directory as wp-load.php."
)