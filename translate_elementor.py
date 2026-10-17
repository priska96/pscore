import json
import os
import re
import sys
from pathlib import Path


TRANSLATABLE_FIELDS = {
    "title",
    "heading",
    "text",
    "editor",
    "description",
    "button_text",
    "testimonial_content",
    "testimonial_name",
    "testimonial_job",
    "placeholder",
    "prefix",
    "suffix",
    "before",
    "after",
    "caption",
    "html",
    "content",
    "sub_title",
    "subtitle",
    "name",
    "job",
    "item_title"
}


def should_translate(key, value):
    if key not in TRANSLATABLE_FIELDS:
        return False

    if not isinstance(value, str):
        return False

    value = value.strip()

    if not value:
        return False

    if re.match(r"^https?://", value, re.I):
        return False

    if re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", value):
        return False

    # No English text
    if not re.search(r"[A-Za-z]", value):
        return False

    return True


def find_fields(data, path=()):
    found = []

    if isinstance(data, dict):

        for key, value in data.items():

            current_path = path + (key,)

            if should_translate(key, value):

                found.append({
                    "path": list(current_path),
                    "field": key,
                    "english": value,
                    "korean": ""
                })

            elif isinstance(value, (dict, list)):

                found.extend(
                    find_fields(
                        value,
                        current_path
                    )
                )

    elif isinstance(data, list):

        for index, value in enumerate(data):

            found.extend(
                find_fields(
                    value,
                    path + (index,)
                )
            )

    return found


def set_by_path(data, path, value):
    current = data

    for part in path[:-1]:
        current = current[part]

    current[path[-1]] = value


def load_json(path):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def save_json(data, path):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(
            data,
            f,
            ensure_ascii=False,
            indent=2
        )


def extract_mode(input_path):
    data = load_json(input_path)

    fields = find_fields(data)

    if not fields:
        print("\nNo translatable fields found.")
        return

    output = {
        "source_file": input_path.name,
        "target_language": "Korean",
        "instructions": {
            "preserve_html": True,
            "preserve_urls": True,
            "preserve_meaning": True
        },
        "translations": fields
    }

    output_path = (
        input_path.parent /
        f"{input_path.stem}-translation.json"
    )

    save_json(output, output_path)

    print()
    print("=" * 60)
    print(f"Found {len(fields)} text fields")
    print("=" * 60)

    for i, item in enumerate(fields, 1):
        preview = re.sub(
            r"\s+",
            " ",
            item["english"]
        )

        if len(preview) > 100:
            preview = preview[:100] + "..."

        print(f"{i}. {preview}")

    print()
    print("Translation file created:")
    print(output_path)
    print()
    print(
        "Upload this *-translation.json file to ChatGPT "
        "and ask it to translate the Korean fields."
    )


def import_mode(elementor_path, translation_path):
    data = load_json(elementor_path)

    translations = load_json(translation_path)

    items = translations.get(
        "translations",
        []
    )

    if not items:
        raise RuntimeError(
            "Translation file contains no translations."
        )

    missing = [
        item
        for item in items
        if not str(item.get("korean", "")).strip()
    ]

    if missing:
        raise RuntimeError(
            f"{len(missing)} translations are still empty."
        )

    for item in items:

        set_by_path(
            data,
            item["path"],
            item["korean"]
        )

    output_path = (
        elementor_path.parent /
        f"{elementor_path.stem}-ko.json"
    )

    counter = 2

    while output_path.exists():
        output_path = (
            elementor_path.parent /
            f"{elementor_path.stem}-ko-{counter}.json"
        )
        counter += 1

    save_json(
        data,
        output_path
    )

    print()
    print("=" * 60)
    print("Korean Elementor file created")
    print("=" * 60)
    print()
    print(output_path)
    print()
    print(
        f"{len(items)} translations inserted."
    )
    print()
    print(
        "The original Elementor file was not modified."
    )


def clean_path(value):
    return Path(
        os.path.expanduser(
            value.strip().strip("\"'")
        )
    ).resolve()


def main():
    print()
    print("=" * 60)
    print("PSCORE Elementor Translator")
    print("=" * 60)
    print()
    print("1 - Extract English text for ChatGPT")
    print("2 - Insert Korean translations")
    print()

    mode = input("> ").strip()

    if mode == "1":

        print()
        print("Elementor JSON file:")

        path = clean_path(
            input("> ")
        )

        if not path.exists():
            print("File not found.")
            sys.exit(1)

        extract_mode(path)

    elif mode == "2":

        print()
        print("Original Elementor JSON:")

        elementor_path = clean_path(
            input("> ")
        )

        print()
        print("Translated JSON from ChatGPT:")

        translation_path = clean_path(
            input("> ")
        )

        if not elementor_path.exists():
            print("Original file not found.")
            sys.exit(1)

        if not translation_path.exists():
            print("Translation file not found.")
            sys.exit(1)

        try:
            import_mode(
                elementor_path,
                translation_path
            )

        except Exception as e:
            print()
            print("ERROR:")
            print(e)
            sys.exit(1)

    else:
        print("Invalid option.")


if __name__ == "__main__":
    main()