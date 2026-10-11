#!/usr/bin/env python3
"""Preseed reviewed W2A gettext strings without modifying canonical i18n tooling.

Usage from repository root:
    python3 tools/i18n/seed-sales-w2a.py
    ./tools/i18n/update

The canonical update step is still required to generate the POT, normalize
all six PO catalogs and compile the committed MO artifacts. This script only
adds/replaces explicit translations within the product's PO files.
"""
from __future__ import annotations

import json
from pathlib import Path

import catalog

ROOT = Path(__file__).resolve().parents[2]
DATA = Path(__file__).resolve().with_name("sales-w2a-translations.json")


def seed_dataset(data_path: Path) -> None:
    payload = json.loads(data_path.read_text(encoding="utf-8"))
    if payload.get("version") != "1.0.0" or payload.get("domain") != "core-blueprint-crm":
        catalog.fail("unexpected W2A translation dataset identity")
    if payload.get("locales") != list(catalog.LOCALES):
        catalog.fail("W2A translation dataset must contain the six canonical locales")

    messages = payload.get("messages")
    if not isinstance(messages, dict) or not messages:
        catalog.fail("missing reviewed W2A translation messages")

    for msgid, translations in messages.items():
        if not isinstance(msgid, str) or not msgid.strip() or "\n" in msgid:
            catalog.fail("invalid W2A msgid")
        if not isinstance(translations, dict) or set(translations) != set(catalog.LOCALES):
            catalog.fail(f"incomplete locale set for {msgid}")
        if any(
            not isinstance(translations[locale], str)
            or not translations[locale].strip()
            or "\n" in translations[locale]
            for locale in catalog.LOCALES
        ):
            catalog.fail(f"missing reviewed W2A locale translation for {msgid}")

    for locale in catalog.LOCALES:
        po_path = ROOT / "languages" / f"core-blueprint-crm-{locale}.po"
        if not po_path.is_file():
            catalog.fail(f"cannot seed missing reviewed PO {locale}")
        current = catalog.blocks(po_path)
        by_msgid = {}
        for index, block in enumerate(current):
            lines = block.splitlines()
            msgid = catalog.directive(lines, "msgid")
            if msgid and catalog.directive(lines, "msgctxt") is None and catalog.directive(lines, "msgid_plural") is None:
                by_msgid[msgid] = index

        additions = 0
        for msgid in sorted(messages):
            translation = messages[msgid][locale]
            if msgid in by_msgid:
                current[by_msgid[msgid]] = catalog.replace_singular_translation(
                    current[by_msgid[msgid]], translation
                )
            else:
                # msgmerge will replace this temporary source-reference during
                # the canonical WP-CLI POT and PO regeneration.
                entry = (
                    "#: src/Admin/SalesPage.php\n"
                    "msgid " + json.dumps(msgid, ensure_ascii=False) + "\n"
                    "msgstr " + json.dumps(translation, ensure_ascii=False)
                )
                current.append(entry)
                additions += 1

        po_path.write_text("\n\n".join(current).rstrip() + "\n", encoding="utf-8")
        print(f"Seeded {locale}: {additions} new W2A gettext entries")

    print("CRM W2A reviewed translations seeded. Run ./tools/i18n/update next.")


def main() -> None:
    seed_dataset(DATA)
    seed_dataset(DATA.with_name("sales-w2b1-translations.json"))
    print("CRM Sales reviewed Admin translations seeded. Run ./tools/i18n/update next.")



if __name__ == "__main__":
    main()
