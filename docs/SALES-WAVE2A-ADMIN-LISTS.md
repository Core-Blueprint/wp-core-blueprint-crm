# CRM Sales Wave 2A — Native WordPress Admin Lists & Read-Only Detail

**Date:** 2026-10-11
**Status:** IMPLEMENTATION CANDIDATE; PHP / REAL WP-MARIADB / I18N CATALOG / ZIP ACCEPTANCE PENDING
**Branch:** feature/crm-sales-wave2a-admin-list-v1
**C3 parent Golden:** 9639696ab4755bc8e4964f3f7252855513431c7e
**C3 tested Base:** caa8daf1e677ed688d2d253108b6021895e1df33
**Schema:** 1.6; no new tables or schema migrations

## Audit of canonical Admin/UI infrastructure

- Existing CRM Admin::init() installs Menu::init() and Assets::init().
- Menu::TOP_LEVEL_SLUG (core-blueprint-crm) owns the canonical CRM workspace and Overview/Contacts/Users/Organizations/Tags submenus. Do **not** register a new top-level Sales menu.
- Existing Base AdminTheme::register_screen($hook) is used for dedicated CRM and Users pages. W2A registers both new native Sales screen IDs through the same API.
- Assets::enqueue() supplies native CRM CSS on known contexts. Existing record-editor scripts and FormComposition are not loaded for Sales read-only pages.
- Contacts/Organizations use native WordPress post editors; Leads and Opportunities use relational schema 1.6, not CPT/Metabox copies.
- C1 StaffProjections provides capability-gated bounded lists/details/history; C2 StaffActions is reserved for later explicit UI writes.
- Existing Bootstrap/Base gates, six-locale i18n and canonical release tooling remain authoritative.

## W2A functionality

- CRM submenu Leads (page core-blueprint-crm-leads) and Opportunities (page core-blueprint-crm-opportunities), registered only via Menu::register().
- Active parent/submenu follows Menu::screen_context() and Menu::submenu_file().
- Consistent native WP Admin wrap, widefat striped, table headers, accessible GET form labels and Base AdminTheme. Scoped responsive .cb-crm-sales-* CSS only.
- Lists are **staff-only** and **read-only**. C1::page() fetches 20 records at a time with descending-ID keyset pagination, optional status filter (validated per entity type) and optional Assigned to me owner filter.
- No OFFSET, SQL joins, raw Records::get() calls, full-table counts, exports, public dynamic data or AJAX.
- Record titles link to a dedicated read-only detail state within the same submenu. C1::detail() returns the allowlisted scalar fields and numeric participant references. C1::history() supplies bounded append-only audit entries with their own event-ID cursor.
- All record titles, IDs, labels, dynamic query parameters and URLs are escaped at the HTML sink.
- Outer handler checks Access::can_view() AND a positive current user ID before any projection call; C1 repeats the full canonical CRM/Sales/schema gate.
- Menu uses VIEW_SALES for viewers and falls back to MANAGE_SALES for managers lacking the separate viewer grant. The actual callback remains gated by Access::can_view() and canonical CRM access; menu capabilities are **not** authorization.
- Malformed or oversized numeric cursors, array-shaped query arguments, invalid state/mine selections return a generic admin error without rendering a list.
- Current record title is user-supplied and may itself be sensitive; no public caching or frontend exposure.

### Deliberately deferred to W2B and W2C

- No Create, Qualify, Convert, Stage, Won/Lost/Reopen buttons/forms.
- No server-side POST handler, nonce or AJAX path; W2B must call C2 StaffActions with a verified CSRF/nonce and capability/intent checks, not a repository directly.
- Pipeline/stage lookup names, advanced cross-owner selectors, enhanced accessibility, browser layout polish and full privilege scoping may be addressed in later UI increments.
- No row-level confidentiality policy: staff-wide C1 read remains the explicit Wave 1C contract. P2/P3 internal/synthetic restrictions remain in effect.
- No REST, builder binding, Work integration, new identity disclosure or production permission.

## Acceptance

- tests/sales-admin-w2a-smoke.php: real Menu, SalesPage and Assets classes with deterministic WordPress stubs; menu placement and Base theme, viewer-vs-manager capability, no editor JS, HTML escaping, strict typed GET filters, link/cursor preservation, read-only detail/history and fail-closed invalid/unauthorized calls.
- tests/integration/sales-admin-w2a.php: safe disposable real WP 7.0/7.1 + MariaDB 10.11.19 with synthetic Contact/Lead/Opportunity, staff-only render, status/owner filtering, injected synthetic untrusted title escaping, no mutations and historical C1 audit detail; B1–C3 existing checks continue.
- tools/check and tools/check-sales-integration include W2A alongside all Golden regression tests, including the two-process B2 replay race.
- Six-locale translations are supplied as reviewed mappings in tools/i18n/catalog.py. **The generated POT/PO/MO files are not yet committed**: the local operator must regenerate them using canonical ./tools/i18n/update and record the results before running ./tools/check. No partial or fake i18n PASS is allowed.

### Operator commands: safe checkout with narrow remote refspec

~~~bash
cd ~/Downloads/wp-core-blueprint-crm

git fetch origin \
  refs/heads/feature/crm-sales-wave2a-admin-list-v1:refs/remotes/origin/feature/crm-sales-wave2a-admin-list-v1

git switch -c feature/crm-sales-wave2a-admin-list-v1 \
  refs/remotes/origin/feature/crm-sales-wave2a-admin-list-v1

git rev-parse HEAD
git branch --show-current
git status --short
git diff --check 9639696ab4755bc8e4964f3f7252855513431c7e HEAD
git diff --name-status 9639696ab4755bc8e4964f3f7252855513431c7e HEAD

php -l src/Admin/SalesPage.php
php -l tests/sales-admin-w2a-smoke.php
php -l tests/integration/sales-admin-w2a.php
python3 -m py_compile tools/i18n/catalog.py
bash -n tools/check
bash -n tools/check-sales-integration
php tests/sales-admin-w2a-smoke.php

# REQUIRED ONCE FOR THIS NEW UI STRING SET: do not skip or call check first.
./tools/i18n/update
git status --short

# These now include the generated six-locale gettext assets.
./tools/check
./tools/i18n/check
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected new markers:
- CRM Sales W2A native read-only Admin screens and permission boundary smoke passed.
- PASS: real WordPress/MariaDB W2A native Leads/Opportunities staff list, filters, detail and escaped read-only history.
- Core Blueprint CRM checks passed.
- CRM Sales real WordPress/MariaDB integration: PASS.
- Canonical six-locale PO/MO check and ZIP checksum PASS.

**Artifact governance:** after successful acceptance, the generated translation files need to be committed to the **W2A feature branch** (not main) before W2A is Golden. Review the git diff including translations and share HEAD/Base SHA/ZIP SHA. Do not merge to main or run Actions without a separate explicit GO. The untracked dist/ from prior patches must remain untouched.
