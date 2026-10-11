# CRM Sales Wave 1B — B2 Qualified Lead Conversion

**Recorded:** 2026-10-11
**Status:** SOURCE CANDIDATE; LOCAL PHP/WORDPRESS/MARIADB AND RELEASE ACCEPTANCE PENDING
**Branch:** feature/crm-sales-wave1b-conversion-v1
**Parent B1 locally accepted:** ee40bf0ea9a98503a0023932788c9623c92eaa2f
**Base from B1 real integration:** 8544d1097d98c3a241a6c46b8c6fd0dcc27372e4

## Scope

An **internal** CB\CRM\Repository\Sales\Conversion::convert(lead_id, expected_revision, opportunity_pipeline_id, opportunity_stage_id) method returns the newly created Opportunity ID, the existing Opportunity ID on an authorized verified replay, or WP_Error.

It checks ready Sales/CRM capabilities, schema 1.6, non-anonymous actor, qualified Lead and caller-observed revision. It locks the source Lead FOR UPDATE, validates the unique existing source link, locks and validates every canonical CRM Contact/Organization identity, checks the active Opportunity pipeline/stage, and performs all writes in **one InnoDB transaction**:
1. Insert open Opportunity with source_lead_id, source Lead title and owner, revision 1, and an unestimated amount; no cloned email or marketing data.
2. Copy the same canonical Sales participant IDs and roles to the Opportunity.
3. Update the original qualified Lead to converted, with converted_opportunity_id and revision +1.
4. Insert an Opportunity created event and a Lead converted event, with actor/revision and minimal metadata.
5. Commit, or roll back on any failure.

The original 1.6 schema already has UNIQUE source_lead_id on Opportunities, UNIQUE converted_opportunity_id on Leads, and revision/event constraints. No schema or migration change is necessary. Owner metadata is preserved, **not** an access control rule.

### Replay contract

A previously converted Lead is read under the same lock. B2 returns its existing linked Opportunity ID, even if the original caller has an old expected_revision, only if the Opportunity's source_lead_id points back to the Lead. Replays do **not** write another Opportunity, participant, revision or event. A missing/inconsistent reciprocal link is a hard error (crm_sales_conversion_inconsistent), not a request to repair or create a second Deal.

Before the first conversion, a stale revision gets crm_sales_conflict and a non-qualified Lead gets crm_sales_invalid_transition. Conversion has no separate request/idempotency token: the unique Lead ID is its domain idempotency key.

No implicit stage/status synchronization: the Lead's qualification status is separate from stage, and conversion does not automatically move it to a different Lead stage.

### Boundaries

No REST/PublicApi, admin UI, lead capture/import, Contact creation, marketing, Work item, quote, invoice, email, data-subject request processing or retention purge. B3 remains the separate Won/Lost/Reopen patch. The action is for a synthetic-data internal MVP until the full P2/P3 and operational gates have been approved.

## Error contract

| Case | Outcome |
| --- | --- |
| Successful first conversion | New Opportunity ID |
| Verified replay | Same linked Opportunity ID, without new writes |
| Missing runtime, CRM+Sales manage capability, actor or 1.6 schema | crm_sales_forbidden |
| Bad numeric input | crm_sales_invalid_input |
| Missing Lead | crm_sales_not_found |
| Stale pre-conversion revision or guarded update race | crm_sales_conflict |
| Not qualified | crm_sales_invalid_transition |
| Missing, over-limit or malformed participant relations | crm_sales_invalid_participants |
| Deleted, trashed or invalid canonical CRM identity | crm_sales_invalid_identity |
| Wrong/inactive Opportunity pipeline/stage | crm_sales_invalid_pipeline / crm_sales_invalid_stage |
| Damaged reciprocal links or unexpected existing source row | crm_sales_conversion_inconsistent |
| SQL, event, participant insert, commit or locked-read failure | crm_sales_storage_failed, with attempted rollback |

## Verification

- tools/check includes tests/sales-conversion-smoke.php: strict capabilities, bad input, statuses/revision, pipeline/stage, identity lookup, replay, dual links, participant copy and simulated SQL failures.
- tools/check-sales-integration always runs tests/integration/sales-conversion.php after Wave 1A/B1 real tests. It creates synthetic Contact/Organization records in the runner's new disposable MariaDB DB, checks actual links and events, and fault-injects a failed participant insert using a trigger dropped in finally.
- It also runs tests/integration/sales-conversion-concurrent.php: two independent WP-CLI processes are released from a barrier to convert the same qualified Lead simultaneously. Both must return the same Opportunity ID; the verified database must contain exactly one Opportunity and one conversion event. Results are retained in the isolated run directory.
- This is a concurrency **candidate** pending local execution, not a Golden claim.

## Operator acceptance commands

~~~bash
cd ~/Downloads/wp-core-blueprint-crm
git fetch origin
git switch feature/crm-sales-wave1b-conversion-v1
git pull --ff-only origin feature/crm-sales-wave1b-conversion-v1

git rev-parse HEAD
git status --short
git diff --check origin/feature/crm-sales-wave1b-transitions-v1...HEAD
git diff --name-status origin/feature/crm-sales-wave1b-transitions-v1...HEAD

php -l src/Repository/Sales/Conversion.php
php -l tests/sales-conversion-smoke.php
php -l tests/integration/sales-conversion.php
php -l tests/integration/sales-conversion-concurrent.php
bash -n tools/check-sales-integration

./tools/check
./tools/i18n/check

# A new randomly named, isolated WordPress + MariaDB database is created.
# The runner does not touch staging, production, other DBs or old dist.
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected final B2 lines:
- CRM Sales B2 qualified conversion/replay/rollback smoke passed.
- PASS: real MariaDB B2 qualified conversion, dual links, participants, replay and rollback.
- PASS: two WordPress processes produced exactly one linked Opportunity ... and one conversion event.
- CRM Sales real WordPress/MariaDB integration: PASS.
- ZIP integrity + generated SHA-256: PASS. B2 changes runtime code, so the B1 SHA-256 must **not** be reused.

Record HEAD, fresh disposable DB/root, Base SHA, all regression results and ZIP checksum. Known translation PO missing-header warnings are nonfatal until their separately governed cleanup.

**Golden/merge/deployment:** No claim of production safety, multisite or P2/P3 compliance. No merge, Actions, staging, deployment or distribution without separate explicit GO. Only after operator acceptance and separate GO may B3 Won/Lost/Reopen proceed.
