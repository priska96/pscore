from pathlib import Path
import re

# ============================================================
# CONFIG
# ============================================================

DUMP_FILE = "backup_2023-05-07-1924_MUNSCR_20e48e76e600-db"

# Base slug without language suffix
BASE_SLUG = "welcome-to-munscr"

# According to your MUNSCR setup:
# Korean  -> welcome-to-munscr
# English -> welcome-to-munscr-en
KOREAN_SLUG = BASE_SLUG
ENGLISH_SLUG = f"{BASE_SLUG}-en"

TARGET_SLUGS = {
    KOREAN_SLUG: "🇰🇷 KOREAN",
    ENGLISH_SLUG: "🇬🇧 ENGLISH",
}


# ============================================================
# STANDARD WORDPRESS wp_posts COLUMNS
# ============================================================

COLUMNS = [
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


# ============================================================
# SQL PARSER
# ============================================================

def split_sql_tuple(content):
    """
    Splits a SQL tuple into individual values while respecting
    quoted strings, escaped characters, HTML and WPBakery content.
    """

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
    """
    Extracts individual (...) tuples from an INSERT statement
    without being confused by parentheses inside SQL strings.
    """

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

            # SQL escaped quote: ''
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
    """Convert SQL string representation into readable text."""

    value = value.strip()

    if value.upper() == "NULL":
        return None

    if (
        len(value) >= 2
        and value[0] == "'"
        and value[-1] == "'"
    ):
        value = value[1:-1]

        value = value.replace("\\'", "'")
        value = value.replace('\\"', '"')
        value = value.replace("\\\\", "\\")
        value = value.replace("\\n", "\n")
        value = value.replace("\\r", "\r")

        # SQL doubled apostrophe
        value = value.replace("''", "'")

    return value


# ============================================================
# READ DATABASE DUMP
# ============================================================

path = Path(DUMP_FILE)

if not path.exists():
    raise FileNotFoundError(
        f"\n❌ File not found:\n{path.resolve()}\n"
    )

print()
print("Reading database:")
print(path.resolve())
print()

sql = path.read_text(
    encoding="utf-8",
    errors="replace"
)


# ============================================================
# FIND wp_posts INSERT STATEMENTS
# ============================================================

insert_pattern = re.compile(
    r"INSERT\s+INTO\s+`?wp_posts`?"
    r"(?:\s*\([^;]*?\))?"
    r"\s+VALUES\s*",
    re.IGNORECASE,
)

all_records = []

for match in insert_pattern.finditer(sql):

    values_start = match.end()

    # Find end of INSERT statement.
    # Ignore semicolons inside SQL strings.

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

    values_block = sql[values_start:i]

    tuples = extract_tuples(values_block)

    for tuple_content in tuples:

        values = split_sql_tuple(tuple_content)

        if len(values) != len(COLUMNS):
            continue

        record = {}

        for column, value in zip(COLUMNS, values):
            record[column] = clean_sql_value(value)

        all_records.append(record)


# ============================================================
# FIND THE ACTUAL PAGES
# ============================================================

target_pages = []

for record in all_records:

    # We only want actual WordPress pages here.
    if record["post_type"] != "page":
        continue

    slug = (record["post_name"] or "").strip().lower()

    if slug in TARGET_SLUGS:
        target_pages.append(record)


# Sort by ID
def id_number(record):
    try:
        return int(record["ID"])
    except (TypeError, ValueError):
        return 0


target_pages.sort(key=id_number)


# ============================================================
# DISPLAY PAGE INFORMATION
# ============================================================

print("=" * 100)
print("FOUND TARGET PAGES")
print("=" * 100)

if not target_pages:
    print()
    print("❌ No page found with either slug:")
    print(f"   {KOREAN_SLUG}")
    print(f"   {ENGLISH_SLUG}")
    print()

else:

    for page in target_pages:

        slug = (page["post_name"] or "").lower()
        language = TARGET_SLUGS.get(slug, "UNKNOWN")

        content = page["post_content"] or ""

        print()
        print(language)
        print("-" * 100)

        print(f"ID:           {page['ID']}")
        print(f"Title:        {page['post_title']}")
        print(f"Slug:         {page['post_name']}")
        print(f"Status:       {page['post_status']}")
        print(f"Parent ID:    {page['post_parent']}")
        print(f"Author ID:    {page['post_author']}")
        print(f"Created:      {page['post_date']}")
        print(f"Modified:     {page['post_modified']}")
        print(f"GUID:         {page['guid']}")
        print(f"Menu order:   {page['menu_order']}")

        print(
            f"Content size: {len(content):,} characters"
        )

        if "[vc_" in content.lower():
            print("Visual Composer/WPBakery: YES")
        else:
            print("Visual Composer/WPBakery: not detected")

        print()


# ============================================================
# FIND REVISIONS BELONGING TO THESE PAGES
# ============================================================

print()
print("=" * 100)
print("RELATED REVISIONS")
print("=" * 100)


for page in target_pages:

    page_id = str(page["ID"])

    revisions = [
        record
        for record in all_records
        if record["post_type"] == "revision"
        and str(record["post_parent"]) == page_id
    ]

    revisions.sort(
        key=lambda r: r["post_modified"] or ""
    )

    slug = (page["post_name"] or "").lower()
    language = TARGET_SLUGS.get(slug, "UNKNOWN")

    print()
    print(
        f"{language} — "
        f"{page['post_title']} "
        f"(Page ID {page_id})"
    )

    print("-" * 100)

    if not revisions:
        print("No revisions found.")
        continue

    for revision in revisions:

        content = revision["post_content"] or ""

        print(
            f"Revision ID {revision['ID']} | "
            f"Modified {revision['post_modified']} | "
            f"{len(content):,} chars"
        )


# ============================================================
# FINAL SUMMARY
# ============================================================

print()
print("=" * 100)
print("SUMMARY")
print("=" * 100)


for slug, language in TARGET_SLUGS.items():

    matches = [
        page
        for page in target_pages
        if (page["post_name"] or "").lower() == slug
    ]

    print()
    print(f"{language}")
    print(f"Expected slug: {slug}")

    if not matches:

        print("❌ NOT FOUND")

    elif len(matches) == 1:

        page = matches[0]

        print("✅ FOUND")
        print(f"Page ID:  {page['ID']}")
        print(f"Title:    {page['post_title']}")
        print(f"Status:   {page['post_status']}")
        print(f"Modified: {page['post_modified']}")

    else:

        print(
            f"⚠️ {len(matches)} pages found "
            "with exactly this slug:"
        )

        for page in matches:

            print(
                f"   ID {page['ID']} | "
                f"{page['post_status']} | "
                f"{page['post_modified']}"
            )


print()
print("=" * 100)