from pathlib import Path
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


# ============================================================
# wp_postmeta COLUMNS
# ============================================================

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
    Split one SQL tuple into values while respecting:
    - commas inside strings
    - escaped quotes
    - HTML
    - serialized PHP data
    - JSON
    - newlines
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
    parentheses inside quoted SQL strings.
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
    """
    Convert SQL representation into readable text.
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

        # SQL doubled apostrophe
        value = value.replace("''", "'")

    return value


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

    # Find end of INSERT statement while respecting strings
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

        if len(values) != len(POSTMETA_COLUMNS):
            continue

        record = {}

        for column, value in zip(POSTMETA_COLUMNS, values):
            record[column] = clean_sql_value(value)

        post_id = str(record["post_id"])

        if post_id in TARGET_PAGES:
            records.append(record)


# ============================================================
# SORT
# ============================================================

def meta_id_number(record):
    try:
        return int(record["meta_id"])
    except (TypeError, ValueError):
        return 0


records.sort(key=meta_id_number)


# ============================================================
# OUTPUT
# ============================================================

print("=" * 100)
print("POST META FOR TARGET PAGES")
print("=" * 100)


for page_id, page_info in TARGET_PAGES.items():

    page_records = [
        record
        for record in records
        if str(record["post_id"]) == page_id
    ]

    print()
    print(
        f"{page_info['language']} — "
        f"{page_info['slug']} "
        f"(Page ID {page_id})"
    )

    print("-" * 100)

    if not page_records:
        print("❌ No postmeta found.")
        continue

    for record in page_records:

        value = record["meta_value"]

        if value is None:
            value_length = 0
        else:
            value_length = len(value)

        print(
            f"Meta ID {record['meta_id']:>8} | "
            f"{record['meta_key']:<45} | "
            f"{value_length:>8,} chars"
        )


# ============================================================
# SUMMARY BY META KEY
# ============================================================

print()
print("=" * 100)
print("SUMMARY")
print("=" * 100)


for page_id, page_info in TARGET_PAGES.items():

    page_records = [
        record
        for record in records
        if str(record["post_id"]) == page_id
    ]

    print()
    print(
        f"{page_info['language']} "
        f"(Page ID {page_id})"
    )

    print()

    if not page_records:
        print("No meta keys found.")
        continue

    keys = {}

    for record in page_records:
        key = record["meta_key"]

        if key not in keys:
            keys[key] = 0

        keys[key] += 1

    for key in sorted(keys):

        count = keys[key]

        if count == 1:
            print(f"  {key}")
        else:
            print(f"  {key}  ({count} entries)")


# ============================================================
# COMPARE ENGLISH / KOREAN META KEYS
# ============================================================

english_keys = {
    record["meta_key"]
    for record in records
    if str(record["post_id"]) == "249"
}

korean_keys = {
    record["meta_key"]
    for record in records
    if str(record["post_id"]) == "1838"
}


print()
print("=" * 100)
print("META KEY COMPARISON")
print("=" * 100)

print()
print("Present on BOTH pages:")
print()

for key in sorted(english_keys & korean_keys):
    print(f"  {key}")


print()
print("Only on ENGLISH page:")
print()

english_only = english_keys - korean_keys

if english_only:
    for key in sorted(english_only):
        print(f"  {key}")
else:
    print("  none")


print()
print("Only on KOREAN page:")
print()

korean_only = korean_keys - english_keys

if korean_only:
    for key in sorted(korean_only):
        print(f"  {key}")
else:
    print("  none")


print()
print("=" * 100)
print(f"Total matching postmeta rows: {len(records)}")
print("=" * 100)