from pathlib import Path
from collections import defaultdict
import hashlib
import re

# ============================================================
# CONFIG
# ============================================================

DUMP_FILE = "backup_2023-05-07-1924_MUNSCR_20e48e76e600-db"

TARGET_PAGES = {
    "249": {
        "language": "🇬🇧 ENGLISH",
        "slug": "welcome-to-munscr-en",
    },
    "1838": {
        "language": "🇰🇷 KOREAN",
        "slug": "welcome-to-munscr",
    },
}

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
    """
    Split a SQL tuple into individual values.

    Handles:
    - commas inside strings
    - escaped quotes
    - HTML
    - JSON
    - serialized PHP data
    - Visual Composer data
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
    Extract (...) tuples without being confused by
    parentheses inside SQL strings.
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
    """
    Convert SQL string representation into actual text.
    """

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

        value = value.replace("''", "'")

    return value


# ============================================================
# HASHING
# ============================================================

def get_hash(value):
    """
    SHA-256 hash of a meta value.

    Identical hashes = identical content.
    """

    if value is None:
        value = ""

    return hashlib.sha256(
        value.encode("utf-8")
    ).hexdigest()


# ============================================================
# READ DATABASE
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
# FIND wp_postmeta INSERT STATEMENTS
# ============================================================

insert_pattern = re.compile(
    r"INSERT\s+INTO\s+`?wp_postmeta`?"
    r"(?:\s*\([^;]*?\))?"
    r"\s+VALUES\s*",
    re.IGNORECASE,
)

records = []


for match in insert_pattern.finditer(sql):

    values_start = match.end()

    in_string = False
    escaped = False

    i = values_start

    # Find the end of the INSERT statement
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

        if len(values) != len(POSTMETA_COLUMNS):
            continue

        record = {}

        for column, value in zip(POSTMETA_COLUMNS, values):
            record[column] = clean_sql_value(value)

        post_id = str(record["post_id"])

        if post_id in TARGET_PAGES:
            records.append(record)


# ============================================================
# GROUP BY PAGE + META KEY
# ============================================================

grouped = defaultdict(lambda: defaultdict(list))

for record in records:

    post_id = str(record["post_id"])
    meta_key = record["meta_key"]

    grouped[post_id][meta_key].append(record)


# ============================================================
# FULL COMPARISON
# ============================================================

print("=" * 110)
print("META VALUE COMPARISON")
print("=" * 110)


for page_id, page_info in TARGET_PAGES.items():

    print()
    print(
        f"{page_info['language']} — "
        f"{page_info['slug']} "
        f"(Page ID {page_id})"
    )

    print("=" * 110)

    page_groups = grouped[page_id]

    for meta_key in sorted(page_groups):

        entries = page_groups[meta_key]

        print()
        print(f"KEY: {meta_key}")
        print("-" * 110)

        hashes = []

        for record in entries:

            value = record["meta_value"]

            if value is None:
                value_length = 0
            else:
                value_length = len(value)

            value_hash = get_hash(value)
            hashes.append(value_hash)

            print(
                f"Meta ID {record['meta_id']:>8} | "
                f"{value_length:>9,} chars | "
                f"SHA256 {value_hash}"
            )

        unique_hashes = set(hashes)

        if len(entries) == 1:
            print("→ SINGLE ENTRY")

        elif len(unique_hashes) == 1:
            print(
                f"→ ✅ IDENTICAL "
                f"({len(entries)} duplicate entries)"
            )

        else:
            print(
                f"→ ⚠️ DIFFERENT "
                f"({len(unique_hashes)} unique values "
                f"across {len(entries)} entries)"
            )


# ============================================================
# DUPLICATE SUMMARY
# ============================================================

print()
print()
print("=" * 110)
print("DUPLICATE SUMMARY")
print("=" * 110)


for page_id, page_info in TARGET_PAGES.items():

    print()
    print(
        f"{page_info['language']} "
        f"(Page ID {page_id})"
    )

    print("-" * 110)

    page_groups = grouped[page_id]

    identical = []
    different = []
    single = []

    for meta_key, entries in page_groups.items():

        hashes = [
            get_hash(record["meta_value"])
            for record in entries
        ]

        unique_hashes = set(hashes)

        if len(entries) == 1:
            single.append(meta_key)

        elif len(unique_hashes) == 1:
            identical.append(
                (meta_key, len(entries))
            )

        else:
            different.append(
                (
                    meta_key,
                    len(entries),
                    len(unique_hashes),
                )
            )

    print()
    print("✅ IDENTICAL DUPLICATES:")

    if identical:
        for key, count in sorted(identical):
            print(
                f"  {key:<45} "
                f"{count} entries → safe to reduce to 1"
            )
    else:
        print("  none")

    print()
    print("⚠️ DIFFERENT DUPLICATES:")

    if different:
        for key, count, unique_count in sorted(different):
            print(
                f"  {key:<45} "
                f"{count} entries / "
                f"{unique_count} different values"
            )
    else:
        print("  none")

    print()
    print("SINGLE ENTRIES:")

    if single:
        for key in sorted(single):
            print(f"  {key}")
    else:
        print("  none")


# ============================================================
# IMPORTANT VISUAL COMPOSER KEYS
# ============================================================

IMPORTANT_KEYS = [
    "vcv-pageContent",
    "vcv-globalElementsCssData",
    "vcvSourceAssetsFiles",
    "vcvSourceCss",
    "vcvSourceCssFileUrl",
    "vcvSettingsSourceCustomCss",
    "vcv-settingsLocalJsHead",
    "vcv-settingsLocalJsFooter",
    "_vcv-sourceChecksum",
    "_vcv-globalCssMigrated",
    "_vcv-page-template",
    "_vcv-page-template-type",
    "_vcv-page-template-stretch",
    "_vcv-pageTitleDisabled",
    "_vcv-pageDesignOptionsData",
    "_vcv-pageDesignOptionsCompiledCss",
    "_vcv-elements",
    "_vcv-allElements",
    "_vcv-editorUsage",
]


print()
print()
print("=" * 110)
print("IMPORTANT VISUAL COMPOSER DATA")
print("=" * 110)


for page_id, page_info in TARGET_PAGES.items():

    print()
    print(
        f"{page_info['language']} "
        f"(Page ID {page_id})"
    )

    print("-" * 110)

    page_groups = grouped[page_id]

    for key in IMPORTANT_KEYS:

        entries = page_groups.get(key, [])

        if not entries:
            print(
                f"❌ {key:<45} NOT FOUND"
            )
            continue

        hashes = {
            get_hash(record["meta_value"])
            for record in entries
        }

        lengths = {
            len(record["meta_value"] or "")
            for record in entries
        }

        if len(hashes) == 1:
            status = "✅ IDENTICAL"
        else:
            status = "⚠️ DIFFERENT"

        print(
            f"{status:<15} "
            f"{key:<45} "
            f"{len(entries)} entries | "
            f"{len(hashes)} unique | "
            f"lengths {sorted(lengths)}"
        )


# ============================================================
# FINAL RESULT
# ============================================================

print()
print()
print("=" * 110)
print("FINAL RESULT")
print("=" * 110)


has_different_duplicates = False


for page_id, page_info in TARGET_PAGES.items():

    print()
    print(
        f"{page_info['language']} "
        f"(Page ID {page_id})"
    )

    page_groups = grouped[page_id]

    different_keys = []

    for meta_key, entries in page_groups.items():

        if len(entries) <= 1:
            continue

        hashes = {
            get_hash(record["meta_value"])
            for record in entries
        }

        if len(hashes) > 1:
            different_keys.append(meta_key)

    if different_keys:

        has_different_duplicates = True

        print(
            "⚠️ Different duplicate values exist."
        )

        print("Keys:")

        for key in sorted(different_keys):
            print(f"  {key}")

    else:

        print(
            "✅ All duplicate meta values are identical."
        )


print()
print("=" * 110)

if has_different_duplicates:

    print(
        "⚠️ Do NOT automatically deduplicate yet."
    )
    print(
        "We need to inspect the keys marked DIFFERENT."
    )

else:

    print(
        "✅ Duplicate values can be reduced to one "
        "entry per meta key for the restore."
    )

print("=" * 110)