# CRM Sales Wave 1B — B3 Opportunity Outcomes

**Recorded:** 2026-10-11
**Status:** SOURCE CANDIDATE; LOCAL PHP, REAL WORDPRESS/MARIADB AND RELEASE ACCEPTANCE PENDING
**Branch:** feature/crm-sales-wave1b-outcomes-v1
**B2 accepted parent:** 6ec75a5a1ce1032e59d2f1298aea9dfa117b6b84
**Previously tested Base baseline:** 8544d1097d98c3a241a6c46b8c6fd0dcc27372e4

## Scope

The internal staff-only CB\CRM\Repository\Sales\Outcomes class is a separate mutator from B1 stage moves and B2 qualified Lead conversion. It exposes:

- close(opportunity_id, outcome, expected_revision): outcome must be *won* or *lost*, and the record must currently be *open*.
- reopen(opportunity_id, expected_revision): explicit return to *open* from *won* or *lost* only.

Both return the next integer revision or WP_Error. There is no implicit re-opening or silent Won-to-Lost change. Reopening does not change the Opportunity's pipeline, stage, participants, source Lead, owner or estimate.

The class requires ready CRM schema version 1.6 and both the canonical cb_manage_crm and cb_manage_crm_sales capabilities with a logged-in actor. The Opportunity is read with SELECT ... FOR UPDATE in one InnoDB transaction. A stale expected revision is rejected, and the subsequent guarded UPDATE also checks id + revision + prior outcome.

Upon closing, either won_at or lost_at is set to UTC now and the other field cleared. Upon reopening, *both effective current-close timestamps* become null. Prior Won/Lost timing remains documented by immutable append-only Sales events. An already-inconsistent record with contradictory closure markers fails closed as crm_sales_outcome_inconsistent rather than silently overwriting it.

The same transaction inserts a Sales event with:
- entity_type=opportunity and entity_id;
- event_type=won/lost/reopened;
- from_outcome and to_outcome;
- trusted WordPress actor_user_id, new revision, UTC created_at.

A database read/update/event/commit error triggers a rollback attempt, returning crm_sales_storage_failed. No automatic retry or rewritten history. B3 uses existing 1.6 columns and indexes; no schema migration needed.

## Fail-closed errors

| Case | Code |
| --- | --- |
| Runtime, schema, actor or capabilities unavailable | crm_sales_forbidden |
| Unsupported outcome or non-positive ID/revision | crm_sales_invalid_input |
| Opportunity does not exist | crm_sales_not_found |
| Stale expected revision or guarded update changed zero rows | crm_sales_conflict |
| Close of a terminal record or reopen of open record | crm_sales_invalid_transition |
| Inconsistent current close date markers | crm_sales_outcome_inconsistent |
| SQL failure during locking read, update, event insert or commit | crm_sales_storage_failed |

## Explicit boundaries

B3 does not change Leads, Contacts/Organizations, existing Sales creation, conversion, default pipelines, Work items, invoices, payments, marketing permission, P2/P3 retention/extraction/erasure, or WordPress Admin screens. A won Deal represents only a CRM commercial status, **not** settled accounting or paid invoice. No public REST or unauthenticated transport is introduced.

No user-entered freeform reason is stored by B3. A future loss-reason contract requires its own governance/privacy review; outcome events already preserve the historical transition.

## Acceptance

- The canonical tools/check suite now includes tests/sales-outcomes-smoke.php, with capability/runtime/schema guards, invalid and stale transitions, contradictory timestamps, lifecycle paths, actor and revision audit, write-time conflicts, injected read/update/insert/commit errors and atomic rollback.
- The canonical tools/check-sales-integration runner now includes tests/integration/sales-outcomes.php, executed only in a fresh randomly named disposable WordPress/MariaDB database. It tests persisted Won/Lost/Reopen, stale replay, terminal stage protection, revision and event chronology, and an injected event INSERT failure following an Opportunity UPDATE. The fixture drops its own trigger in finally.
- The existing B1 and B2 tests, including the two-process B2 concurrency test, remain registered and must continue to pass.
- The runner retains isolated test databases. It does not reuse old installations or destroy existing DBs.

### Operator terminal verification

~~~bash
cd ~/Downloads/wp-core-blueprint-crm

git fetch origin
git switch feature/crm-sales-wave1b-outcomes-v1
git pull --ff-only origin feature/crm-sales-wave1b-outcomes-v1
git rev-parse HEAD
git status --short

# Compare against the pinned accepted B2 SHA; do not rely on a missing local
# remote-tracking branch as happened during B2.
git diff --check 6ec75a5a1ce1032e59d2f1298aea9dfa117b6b84 HEAD
git diff --name-status 6ec75a5a1ce1032e59d2f1298aea9dfa117b6b84 HEAD

php -l src/Repository/Sales/Outcomes.php
php -l tests/sales-outcomes-smoke.php
php -l tests/integration/sales-outcomes.php
bash -n tools/check-sales-integration

php tests/sales-outcomes-smoke.php
./tools/check
./tools/i18n/check

# Creates a new isolated temporary WordPress/MariaDB database; no staging,
# production, existing test databases or old dist content are modified.
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected new success markers:
- CRM Sales B3 Won/Lost/Reopen outcome audit smoke passed.
- PASS: real MariaDB B3 Won/Lost/Reopen, revision, immutable event chronology and rollback.
- CRM Sales real WordPress/MariaDB integration: PASS.
- Full CRM checks PASS, i18n six locale PASS, ZIP integrity/checksum OK.

The B3 build has a new runtime class, so its ZIP SHA-256 must be newly calculated, not assumed equal to B2.

## Wave 1B governance

B1 and B2 are accepted local Golden baselines. B3 remains a technical candidate pending operator test results and a separate acceptance decision. Once B3 is accepted, Wave 1B's *internal* Sales lifecycle is complete, but product interfaces are not: Wave 1C read/write PHP facade and staff projections and Wave 2 WP Admin UI remain separate workstreams.

No GitHub Actions, merge, release, staging/production deployment or policy-/data-subject-processing approval is implied.
