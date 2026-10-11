# CRM Sales Wave 1C — C1 Staff Read Projections

**Date:** 2026-10-11
**Status:** SOURCE CANDIDATE, PENDING LOCAL PHP AND DISPOSABLE WORDPRESS/MARIADB ACCEPTANCE
**Baseline B3 local Golden:** 14ce2d03af19b1d5446f81134b0ff7a09aef3463
**Branch:** feature/crm-sales-wave1c-staff-projections-v1
**Schema:** 1.6, unchanged

## Intent and audience

C1 introduces **internal**, staff-wide, server-side Sales read projections. These are **not** REST endpoints, user-facing WordPress Admin screens, exports, public shortcodes or multi-tenant/per-owner access rules. The owner_user_id field is data, **not** permission. The canonical CRM manage capability AND a Sales view/manage capability, current logged-in actor, ready Base/CRM runtime, and exact Sales schema version are mandatory before SQL access.

The raw Records::get() repository from Wave 1A remains as-is for its existing internal contract, but **must not be used as the UI projection**. Wave 1C and Wave 2 should build on these explicit, minimal staff reads and a separately governed C2 action facade.

The Sales record title is free text and can contain personal or confidential data entered by staff. It is therefore **not anonymous**. These reads must remain private to authorized staff and must not be cached publicly or exposed through builder dynamic data. No identity name, email, address, relationship note, WP_Post fields, arbitrary source_reference, or participant title is joined or copied. The Opportunity amount/currency is available only on authorized *detail* views, not in list summaries. This contract is not a GDPR export or retention procedure; the distinct P2/P3 governance gates remain pending.

## Internal PHP surface

Namespace: CB\CRM\Repository\Sales\StaffProjections

### page(type, filters = [], after = 0, limit = 20)

- Valid type: lead or opportunity; caller-controlled SQL/table/column names are never accepted.
- Exact filter whitelist: pipeline_id (positive int), stage_id (positive int), owner_user_id (nonnegative int, including anonymized owner 0), state (validated Lead status or Opportunity outcome).
- Unknown filters, sorting options, type mismatches, negative cursors and limits outside 1..50 return crm_sales_invalid_query.
- ID keyset **descending**, id < after, ORDER BY id DESC LIMIT limit+1, returning:
  - rows: id, title, owner_user_id, pipeline_id, stage_id, normalized state, next_action_on, revision, created_at, updated_at.
  - cursor: last returned row ID (or the input cursor for an empty page).
  - has_more: boolean determined by the fetched extra row.
- No OFFSET, unlimited COUNT, free-text search, unscoped identity JOIN or arbitrary select fields. Newer IDs inserted during pagination cannot cause duplicate old rows.

### detail(type, id)

- Returns null for a missing record; WP_Error for forbidden, malformed or failed reads.
- Returns a record containing the list summary and narrowly whitelisted domain detail fields:
  - Lead: source (code), converted_opportunity_id, qualified_at, disqualified_at.
  - Opportunity: source_lead_id, amount_minor, currency, expected_close_on, won_at, lost_at.
- Returns participants as an ID-only list of (party_type, party_id, role) from the Sales participant table, with a hard fetch bound of 51 and an acceptance limit of 50. No canonical identity record content or user-supplied participant fields are joined.
- This projection is for staff UI detail views, **not** customer data-subject export.

### history(type, id, after = 0, limit = 20)

- Requires the record to exist; a missing subject returns crm_sales_not_found.
- Event cursor is a monotonically increasing **event id** (id > after, ascending); page limit 1..50.
- Fields only: id, event_type, from_stage_id, to_stage_id, from_outcome, to_outcome, actor_user_id, revision, created_at.
- No mutation or audit payload/free-text enrichment. Caller cannot request arbitrary events or other record types.

### Error contract

| Code | Meaning |
| --- | --- |
| crm_sales_forbidden | Inactive runtime/schema, no actor or insufficient CRM + Sales capabilities |
| crm_sales_invalid_query | Invalid record type, ID, filter, cursor or limit |
| crm_sales_not_found | History requested for a non-existing Sales record |
| crm_sales_read_failed | SQL read error, including failed participant/history lookup |
| crm_sales_invalid_row | Unexpected malformed projection row or >50 participant links |

## Implementation and acceptance notes

- C1 adds src/Repository/Sales/StaffProjections.php; it does not alter SQL schema, create Sales records or change B1–B3 actions.
- tests/sales-staff-projections-smoke.php covers capability/runtime/actor/schema fail-closed behavior, invalid and injection-shaped input rejection without SQL, list/order/cursors/filters, detail field whitelists, limited participants, audit history cursor, SQL read errors and malformed/oversized rows.
- tests/integration/sales-staff-projections.php creates synthetic Contact, Organization, five Leads and an Opportunity only within a **fresh randomly named disposable MariaDB database**. It validates actual WordPress roles, state filters, multi-page reads, scalar detail fields, participant references, B1/B3 audit chronology and read-only behavior.
- Tests are always included after B1–B3 acceptance in tools/check and tools/check-sales-integration. The B2 two-worker replay race remains enabled.
- No code here authorizes C2 or Wave 2. Existing P2/P3 internal-only/synthetic-data restrictions remain in force.

## Operator acceptance

~~~bash
cd ~/Downloads/wp-core-blueprint-crm
git fetch origin
git switch feature/crm-sales-wave1c-staff-projections-v1
git pull --ff-only origin feature/crm-sales-wave1c-staff-projections-v1

git rev-parse HEAD
git status --short
git diff --check 14ce2d03af19b1d5446f81134b0ff7a09aef3463 HEAD
git diff --name-status 14ce2d03af19b1d5446f81134b0ff7a09aef3463 HEAD

php -l src/Repository/Sales/StaffProjections.php
php -l tests/sales-staff-projections-smoke.php
php -l tests/integration/sales-staff-projections.php
bash -n tools/check-sales-integration

php tests/sales-staff-projections-smoke.php
./tools/check
./tools/i18n/check

# New disposable isolated WordPress/MariaDB, no impact on prior DBs/sites.
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected C1 results:
- CRM Sales C1 bounded staff list/detail/history projections smoke passed.
- PASS: real MariaDB C1 bounded staff Lead/Opportunity pages, filters, details and keyset audit history.
- CRM Sales real WordPress/MariaDB integration: PASS.
- CRM full suite and canonical i18n 6 locales PASS, ZIP and fresh checksum PASS.

The source includes a new runtime class; the B3 ZIP checksum is **not** applicable. The known PO-header warnings and absent sendmail in local disposable tests are separate nonfatal housekeeping issues.

**Golden scope:** Only local acceptance of internal staff projections. No merge, Actions, staging, deployment, external API, data-subject rights automation or production-safety claim without separate explicit GO.
