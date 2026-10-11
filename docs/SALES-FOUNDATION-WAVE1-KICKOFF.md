# CRM Sales Foundation v1 — Wave 1 implementation kickoff

**Date:** 2026-10-11

**Status:** PREPARED / IMPLEMENTATION NOT YET STARTED

**Parent decision:** Design Golden merged by PR #38, into CRM main `42e6915263694210698511735e9b9cd80dd01a40`.

**Architecture:** [Sales Foundation RFC](SALES-FOUNDATION-V1-RFC.md). **Acceptance roadmap:** [Wave plan](SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md).

**Branch:** `feature/crm-sales-wave1-foundation-v1`. This branch is for implementation preparation and subsequently independently accepted code increments. No schema change, new runtime service, admin screen, release package or deployment has been authorised by creating this kickoff document.

## Deliverable and scope

Establish the **smallest independently testable canonical Sales domain foundation**. Do not attempt all of CRM Sales v1, board UI, integrations and reporting in one patch.

### Wave 1A — contract and storage boundary (first candidate implementation)

**Code candidates (not files already created):**

- `src/Domain/Sales/*`: value rules, valid Lead/Opportunity lifecycle and pipeline-type checks; stable, deterministic identifiers; explicit record-outcome semantics.
- `src/Database/Schema.php`: add **versioned** sales table definitions only after exact DDL/index and migration review, preserving the existing `CB_CRM_SCHEMA_VERSION = '1.5'` baseline and Base SchemaRegistry orchestration.
- `src/Repository/Sales/*`: bounded, prepared read/write primitives with integrity checks and revision handling, not public CRUD access.
- `tests/sales-foundation-*.php`: new test coverage within the repository's existing `tools/check` sequence when the implementation phase is approved.

**Initial entity fields to settle before migration:**

| Entity | Required foundations | Explicit separation |
| --- | --- | --- |
| Pipeline | Stable key, `lead/opportunity` type, title, order/active state | Never a WordPress post status. |
| Stage | Stable key, parent pipeline, order, active state | Labels may be localized; IDs must remain stable. |
| Lead | Stable ID, owner, stage/pipeline, lifecycle/result, created/updated/revision, provenance | Not a Contact and not a Work Item. |
| Opportunity | Stable ID, owner, pipeline/stage, `open/won/lost`, estimated amount minor units + currency, timestamps/revision | Not an invoice, quote or paid amount. |
| Participant | Entity type+ID, CRM Contact/Organization type+ID, role, uniqueness | Reference a real canonical CRM identity; do not clone PII. |
| Sales event | Entity/ref, type, previous/new stage/outcome, actor, UTC time, revision, provenance | Never place full messages, secrets or private notes into public audit context. |

**Critical decision before SQL:** choose physical ID type, UUID strategy and unique constraints; verify indexing for owner, pipeline/stage/outcome, created/updated, participant reverse lookup and conversion; define portability/restore treatment. No blanket `dbDelta` migration is acceptable without legacy upgrade, verification and interrupted-write tests.

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

1. This kickoff documents implementation order and test gates, not source implementation consent.
2. Confirm domain/storage/access decisions from the RFC before Wave 1A.
3. Implement **only Wave 1A** as a separately reviewed patch after explicit GO.
4. Show exact Git branch/SHA and actual tests, release artifact SHA-256 and non-destructive staging acceptance; no merge, CI manual rerun, production install or public release without separate explicit authorisation.
