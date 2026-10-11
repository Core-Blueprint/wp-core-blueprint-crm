# CRM Sales Wave 1B — B1 Revision-Checked Transitions

**Prepared:** 2026-10-11
**Status:** SOURCE CANDIDATE. LOCAL PHP, WORDPRESS/MARIADB AND RELEASE ACCEPTANCE PENDING.
**Branch:** feature/crm-sales-wave1b-transitions-v1
**G0 parent:** 3c79ea88956de6aab213a6514e28864c2d35387c
**Base pinned in prior Wave 1A real integration:** 8544d1097d98c3a241a6c46b8c6fd0dcc27372e4

## In scope

Internal class CB\CRM\Repository\Sales\Transitions provides two methods:

- move_stage(type, id, target_stage_id, expected_revision): moves a nonterminal Lead or open Opportunity to another active stage within its existing active pipeline.
- set_lead_status(id, target_status, expected_revision): qualifies or disqualifies a Lead using existing domain Rules; tracks applicable UTC timestamps.

Both return a new integer revision after success or a typed WP_Error. Both enforce ready CRM runtime, matching CRM and Sales management capabilities, logged-in actor and schema 1.6; lock the Sales record FOR UPDATE; reject stale revisions; update only permitted fields; write minimal Sales event metadata; and commit the update plus event in one transaction. A failed database step causes an attempted rollback.

Lead status and stage remain separate concepts: qualifying a Lead does **not** silently change its visible pipeline stage.

## Error contract

| Situation | Error |
| --- | --- |
| Runtime, user, permissions or schema unavailable | crm_sales_forbidden |
| Invalid input or unsupported B1 target status | crm_sales_invalid_input |
| Missing Sales record | crm_sales_not_found |
| Old expected revision / update-time zero rows | crm_sales_conflict |
| Same-stage action | crm_sales_no_change |
| Terminal record / illegal Lead transition | crm_sales_invalid_transition |
| Wrong or disabled pipeline / stage | crm_sales_invalid_pipeline / crm_sales_invalid_stage |
| Database read/update/insert/commit failure | crm_sales_storage_failed |

Stage moves preserve record pipeline, status/outcome, owner, participants and financial estimate. Status transitions preserve current stage. Events store typed action, actor, revision and optionally from/to stage, not freeform private notes.

## Explicitly out of scope

Qualified Lead conversion (B2), Won/Lost/Reopen (B3), owner or participant edits, pipeline switching, REST/PublicApi, Admin UI, cross-extension automation, P2 privacy exporter/eraser, P3 retention, real-person intake and multisite approval. Neither the original Wave 1A Records class nor schema tables were changed.

## B1 acceptance

- Deterministic standalone PHP smoke is permanently registered in tools/check and covers allowed/denied transitions, permission/runtime/schema guards, stale revisions, invalid/disabled stage, terminal records and injected transaction failures.
- tools/check-sales-integration unconditionally runs a new B1 real WordPress/MariaDB fixture after the baseline Sales fixture. This runs only inside the runner's newly created disposable site/database, using synthetic CRM records. It checks persisted stages/revisions/events and an event-insert trigger failure that must roll back the preceding record update. The trigger is removed in a finally block.
- The real integration runner retains test databases for operator inspection. It does not reuse or drop prior databases.
- No UI change means no visual screenshot requirement. Provide complete terminal output, HEAD, isolated DB name, and the fresh ZIP checksum.

### Verified project commands to run locally

~~~bash
cd ~/Downloads/wp-core-blueprint-crm
git fetch origin
git switch feature/crm-sales-wave1b-transitions-v1
git pull --ff-only origin feature/crm-sales-wave1b-transitions-v1
git rev-parse HEAD
git status --short
git diff --check origin/feature/crm-sales-wave1-foundation-v1...HEAD
git diff --name-status origin/feature/crm-sales-wave1-foundation-v1...HEAD

php -l src/Repository/Sales/Transitions.php
php -l tests/sales-transitions-smoke.php
php -l tests/integration/sales-transitions.php
bash -n tools/check-sales-integration
./tools/check
./tools/i18n/check

# Creates one newly named isolated WordPress + MariaDB test environment.
# No production/staging writes. Test database is retained for inspection.
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected: PHP syntax/CRM regression/i18n/integration/ZIP checks PASS. Existing nonfatal six-locale PO header warnings may appear. The B1 ZIP **must have a new checksum** because the runtime PHP file is new; the Wave 1A ZIP hash is not an appropriate expected value.

**Gate:** B1 is not Golden until the user executes and accepts the local test scope. No merge, Actions, staging, production or release is authorized. **Next step after B1 acceptance:** separately scoped and authorized B2 replay-safe Qualified Lead → Opportunity conversion.
