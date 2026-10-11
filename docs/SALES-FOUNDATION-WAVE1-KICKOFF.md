# CRM Sales Foundation v1 — Wave 1 implementation kickoff

**Date:** 2026-10-11

**Status:** WAVE 1A SCHEMA / REPOSITORY / LIFECYCLE / DEFAULT PIPELINES / TWO-WAY CONCURRENCY GOLDEN (LOCAL); PRIVACY AND RECOVERY GATES OPEN

**Parent decision:** Design Golden merged by PR #38, into CRM main `42e6915263694210698511735e9b9cd80dd01a40`.

**Architecture:** [Sales Foundation RFC](SALES-FOUNDATION-V1-RFC.md). **Acceptance roadmap:** [Wave plan](SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md).

**Branch:** `feature/crm-sales-wave1-foundation-v1`. This branch now contains the **first Wave 1A code patch** following explicit user GO. The earlier kickoff document itself did not authorise source changes. The code patch is for local test/review only and must not be installed or merged before acceptance.

**Implementation status 2026-10-11:** Initial storage-neutral `Rules` and `Access` classes; six Sales tables in versioned schema `1.6`; source, schema and dedicated domain regression smoke. Local baseline `5b7106d28c6ed8b20c05891ad9e1be164c14639b` passed the entire `./tools/check`, i18n, archive and SHA-256 validation (archive `fc7a4337f6c49cc00c6d84b236f36f9856e9305d809a239a7dc8440dce9cc5bd`). The subsequent repository baseline `c9930f9c2c1ceae2883f688520c1ac80252db760` also passed the full local suite, i18n, archive and SHA-256 (`0e52c0d4e2234dd727bfd0a0fbbf71fc768e9183fcbfebf222c736f42172e906`).

**Second 1A patch (locally validated):** Internal `CB\\CRM\\Repository\\Sales\\Records` now offers capability-gated `create_lead`, `create_opportunity` and staff-only `get`. Creates require version `1.6` readiness, caller actor identity, strictly allowlisted fields, real live CRM Contact/Organization participants, type-matched active pipeline/stage and a database transaction spanning the record, links and creation event. No public endpoints, UI, automation or Work calls are exposed. Isolated `sales-records-persistence-smoke.php` exercises rejected input and rollback and is wired into `tools/check`.

**Third 1A patch (locally validated):** `Repository\\Sales\\LifecycleCleanup` is called inside existing CRM post-deletion transactions, detaching deleted Contacts/Organizations, removing orphaned Sales records plus their events, and anonymising deleted WordPress-user owner/actor IDs. Its isolated regression covers multi-party preservation, deletion of last party, rollback, user anonymisation, failed SQL and pre-1.6 no-op. This cleanup does not replace a full GDPR data-subject export/erasure review, retention schedule or operational recovery for cleanup failure.

**Fourth 1A patch (not yet locally validated):** `Application\\Sales\\DefaultPipelines` supplies typed `default_leads` (New, Attempting Contact, Connected, Qualified) and `default_opportunities` (Discovery, Proposal, Decision), using stable stage keys, transactional inserts, and a separate `cb_crm_sales_defaults_version` one-time marker. `Plugin::boot()` calls `maybe_install()` after Base schema migration readiness; neither existing stage names nor active/order settings are overwritten. A retry after failure preserves existing seeded rows, and reserved-key/type collision fails closed. The new `tests/sales-default-pipelines-smoke.php` is a fixture smoke, not a real MariaDB transaction proof. Seed labels are stored as English user-configurable data; future UI presentation needs translation-by-default-key handling without overriding user labels.

**Still blocked for real data / production:** independent Sales capabilities with tested multi-user scope, full data-subject export/erasure and retention policy, revision-checked updates, transactional conversion, real MariaDB/InnoDB verification and staged schema-upgrade tests. Also inspect WordPress `deleted_post` failure recovery: the post deletion occurs outside the CRM relational transaction, so a rollback cannot restore the already-deleted WordPress post. No live installation or merge until these are accepted, even if source smokes pass.

## Deliverable and scope

Establish the **smallest independently testable canonical Sales domain foundation**. Do not attempt all of CRM Sales v1, board UI, integrations and reporting in one patch.

### Wave 1A — contract and storage boundary (first candidate implementation)

**Wave 1A source areas (implemented in increments):**

- `src/Domain/Sales/*`: value rules, valid Lead/Opportunity lifecycle and pipeline-type checks; stable, deterministic identifiers; explicit record-outcome semantics.
- `src/Database/Schema.php`: contains **versioned** Sales DDL under schema `1.6`, migrated from the preserved original `1.5` baseline through Base SchemaRegistry orchestration.
- `src/Repository/Sales/*`: initial internal prepared creation/read boundary with strict validation and transaction rollback. Revision-checked update support is not implemented yet.
- `tests/sales-foundation-*.php`: dedicated `tests/sales-domain-foundation-smoke.php` and `tests/sales-records-persistence-smoke.php` permanently wired into the existing `tools/check` sequence.

**Initial entity fields to settle before migration:**

| Entity | Required foundations | Explicit separation |
| --- | --- | --- |
| Pipeline | Stable key, `lead/opportunity` type, title, order/active state | Never a WordPress post status. |
| Stage | Stable key, parent pipeline, order, active state | Labels may be localized; IDs must remain stable. |
| Lead | Stable ID, owner, stage/pipeline, lifecycle/result, created/updated/revision, provenance | Not a Contact and not a Work Item. |
| Opportunity | Stable ID, owner, pipeline/stage, `open/won/lost`, estimated amount minor units + currency, timestamps/revision | Not an invoice, quote or paid amount. |
| Participant | Entity type+ID, CRM Contact/Organization type+ID, role, uniqueness | Reference a real canonical CRM identity; do not clone PII. |
| Sales event | Entity/ref, type, previous/new stage/outcome, actor, UTC time, revision, provenance | Never place full messages, secrets or private notes into public audit context. |

**Migration review before any real installation:** six Sales tables already use CRM-style unsigned bigint IDs and canonical unique/query indexes. Independently verify Base SchemaRegistry retries, real MariaDB/InnoDB transactional engine, backup/restore, partial upgrade handling, integrity after deleting Contacts/Organizations and site portability. Isolated dbDelta fixtures do not prove real database transactional behaviour.

**Non-negotiable invariants:** each Lead or Opportunity has at least one valid Contact/Organization participant; one Contact can have many Sales records; no implicit marketing consent; no updates to CRM Contact/Organization `active/inactive/archived`; no Work task status reuse; no silent owner/actor impersonation; no auto-conversion, customer/account or Service Agreement creation.

### Wave 1B — governed lifecycle and conversion

Only after Wave 1A persistence and security tests pass:

- explicit stage transitions and allowed terminal outcomes;
- qualified Lead → Opportunity conversion **on an authorised action only**;
- revision-checked stale-update handling; replay-safe conversion with a unique canonical source relation;
- recovery from failure between creating the Opportunity, participant links and marking the Lead converted;
- clear, typed `WP_Error` outcomes, minimal, sanitised Sales activity/audit events;
- no automatic Work Item creation or customer lifecycle changes.

### Wave 1C — supported PHP boundary

Only after Wave 1B tests pass:

- audience-specific, bounded read-only projections;
- separately governed server-side Sales actions, exposing no arbitrary SQL/WordPress objects;
- public API documentation and capability tests; non-staff read access must fail closed by default;
- no REST endpoint merely because a PHP PublicApi method exists;
- no Bricks dependency and no customer-visible marketing permissions inferred.

## Authorisation gate

The current CRM `cb_manage_crm` capability is broad. Before Wave 1A, define whether Sales v1:

1. initially grants read/write only to existing authorised CRM managers **and advertises that limitation**; or
2. introduces separate `cb_view_crm_sales` and `cb_manage_crm_sales` with a versioned role/capability migration and explicit record-scope rules.

**Recommended initial implementation:** preserve the existing fail-closed staff boundary for the first internal foundation, but design a dedicated Sales capability boundary before multi-user production acceptance. Owner assignment is not itself an access control and no feature may claim team-private deal visibility absent a tested per-record policy.

## Integration boundary, verified source

Work `main` at `0bf78e540bb4db8d366c20835c1b5625c281f319` already offers public operational contracts via `CB\Work\PublicApi\WorkItems` and `WorkItemActions`, documented in `docs/PUBLIC-OPERATIONAL-API.md`. Work's mutation API checks `cb_manage_work`. **Wave 1 does not call it.** CRM's existing Public Contact API is a read-only server-side projection, not a general actor-authorised write layer. Sales mutations need their own governed contracts.

CRM's existing `Repository\Activity::record()` calls `Entity::valid_owner()` for Contact/Organization; it does not accept Leads or Opportunities. Do not bypass it with spoofed owner IDs; implement an explicit Sales event persistence and projection seam, with a decision on how Sales events appear in linked Contact/Organization history later.

## Regression/test acceptance for 1A first patch

- [ ] Baseline tests remain green with CRM core, Base contract, WooCommerce, Bookings, Work pricing, Docs and Bricks adapter intact.
- [ ] New tables install on an empty supported WordPress site and on an upgrade from CRM schema `1.5` without data loss.
- [ ] Interrupted/partially applied schema migrations fail closed and can safely retry; installation/upgrade state is observable.
- [ ] Missing or ambiguous CRM references, wrong pipeline type, duplicate participant, stale revision and malformed currency/date fail deterministically.
- [ ] Conversion source uniqueness and multiple separate opportunities for the same CRM Contact are supported at storage-model level.
- [ ] Unauthorized users, logged-out users and missing Base readiness can neither read nor mutate Sales records.
- [ ] Privacy and record erasure/retention are defined before any real sales data is stored.
- [ ] Performance and indexed-query smoke for owner/stage/participant filters, with bounded pages.
- [ ] No additional outbound service, tracking pixel or network dependency.

## Technical validation commands after an implementation patch

Read from `tools/check` and `tools/build-release` in the source baseline. These commands are **not** reported as executed for this preparation document.

```bash
cd ~/Downloads/wp-core-blueprint-crm
git rev-parse HEAD
git status --short
git diff --check origin/main...HEAD

./tools/check
./tools/i18n/check
./tools/build-release
unzip -t dist/core-blueprint-crm.zip
sha256sum dist/core-blueprint-crm.zip
cat dist/core-blueprint-crm.zip.sha256
```

`tools/check` includes deterministic package smoke; a separate actual build to `dist/` is still required before runtime acceptance. The builder excludes `docs/` from its release package, so no user deployment is justified by this prep document alone.

## Governance/next step

1. The user has authorised the initial Wave 1A code patch. Subsequent waves, installation and merge remain separately gated.
2. Confirm domain/storage/access decisions from the RFC before Wave 1A.
3. Implement **only Wave 1A** as a separately reviewed patch after explicit GO.
4. Show exact Git branch/SHA and actual tests, release artifact SHA-256 and non-destructive staging acceptance; no merge, CI manual rerun, production install or public release without separate explicit authorisation.

**Fifth 1A patch (Sales authorisation, pending local acceptance):** The versioned v2 role policy gives administrator and cb_operator explicit `cb_view_crm_sales` and `cb_manage_crm_sales` grants on activation/upgrade, retaining their existing `cb_manage_crm`. Other CRM roles receive no implicit Sales rights; Base User Roles may delegate them. Internal Sales read/write always also requires `cb_manage_crm` and live CRM runtime. Sales manage implies view; `owner_user_id` remains non-authoritative and there is no per-record/team scoping. The new Base capability catalog's two Sales labels/descriptions are English-only pending the six-locale UI localisation pass. PHP-only versioned migration, role and repository read/write regressions were added to `tools/check`. Lifecycle recovery and real MariaDB validation remain open.

**Sixth small validation correction (pending local acceptance):** Restored the explicit `'cb_manage_crm' === $cap` branch inside the existing runtime fail-closed filter while retaining both Sales permissions in the same guarded condition. Updated `tests/bootstrap-v1-smoke.php` so its existing CRM assertion and a new Sales runtime guard assertion both scope to the `map_meta_cap` filter. This addresses a previously unexercised string-based Bootstrap test that failed after the permission refactor. No policy, database, schema or permissions behavior is intentionally changed. Full local `./tools/check` remains the acceptance gate.


**Seventh Wave 1A patch (post-delete recovery, pending local acceptance):** A typed, per-identity non-autoloaded WordPress option records the minimal deletion intent *before* WordPress permanently deletes Contact/Organization posts. Missing intent or failed WP-Cron schedule vetoes the deletion via pre_delete_post, preserving the post. WordPress Trash alone never triggers cleanup or a deletion intent. deleted_post retains the existing transactional relational cleanup; failed cleanup leaves its intent for per-identity WP-Cron retry. Retry requires BOTH a matching saved intent and direct uncached wp_posts absence (never get_post cache); repeats are idempotent for relation storage. An admin-only bounded 20-item index scan re-schedules lost cron events, but does not mutate CRM records. A vetoed deletion may leave an inert intent; it is never automatically discarded while a post exists. Events are at-least-once if intent deletion fails after SQL commit. Cron execution is traffic/hosting dependent, and a large stalled queue may require future operator tooling; MariaDB concurrency, recovery with disabled WP-Cron, and controlled operator review remain unverified. No schema changes, external services or WordPress record resurrection are introduced.

**2026-10-11 tested Wave 1A Golden checkpoint:** source `a217e08577a9e225fde2e81dad43cb99be3cf5ba` (Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`) passed the full local regression suite, i18n, independent real WordPress/MariaDB schema 1.6 install, real 1.5→1.6 upgrade, lock-interrupted schema migration and retry, real post-deletion failure/recovery, and two controlled concurrent delete/create orderings. Canonical `dist/core-blueprint-crm.zip` SHA-256 `53c0c06b0b404ba2067865156158ccb9613de4106ddd9e4d4f38f249234a55c2`. See `SALES-WAVE1A-CONCURRENCY.md` for evidence. This checkpoint does not cover privacy erasure/export, failed deleted-user anonymization recovery, abandoned intent/cron queue, mixed participant write races or non-InnoDB hosts. No merge authorized.
