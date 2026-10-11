# CRM Sales Foundation v1 — Phased Delivery & Acceptance Plan

**Recorded:** 2026-10-11

**Status:** PROPOSED ROADMAP, NOT APPROVED FOR SOURCE IMPLEMENTATION

**Parent design:** [Sales Foundation v1 RFC](SALES-FOUNDATION-V1-RFC.md)

**Code baseline:** CRM `main` `4e487145b49b396341732b5da9b16d2a608e0502`; Work `main` `0bf78e540bb4db8d366c20835c1b5625c281f319`

**Boundary:** This plan adds **no** CRM implementation or Work feature yet. Work is a consumer/integration partner through verified public contracts only.

## Delivery principle

Use small, separate feature branches/PRs and explicit acceptance gates. Complete product ownership, storage and security first; build a usable text/keyboard-first workflow before optional board polish. **A completed RFC is not a production-ready feature**. A green source check is not a live-site acceptance.

### Gate 0 — Baseline and release safety (required before code)

- [ ] Verify live coreblueprint.io CRM/Work plugin versions and exact deployed artifact identities; capture non-sensitive admin navigation and relevant current UX screenshots.
- [ ] Create tested database backup and a staging install at matching supported WP/PHP/MariaDB versions. Do not use production for first schema migration.
- [ ] Capture baseline `./tools/check`, CRM `./tools/i18n/check`, and `./tools/build-release` output; identify any pre-existing failures.
- [ ] Confirm the latest CRM schema version/migration/rollback path and Base `SchemaRegistry` ordering; do not skip legacy upgrade tests.
- [ ] Obtain explicit acceptance of [the RFC](SALES-FOUNDATION-V1-RFC.md): dual entity vs one, canonical ID creation policy, stage model, v1 configuration scope, privacy permissions and storage rationale.
- [ ] Resolve the open product question: do we need a person-free organisation-only Lead, or require a primary Contact? **Recommended:** either Contact OR Organization, at least one canonical link, so B2B prospects without a named buyer are valid.
- [ ] Verify if existing contacts/organisations have any sales-related tags or manually recorded lead data in use. Migration must be explicit and opt-in, never inferred.
- [ ] Decide if sales-user role separation must ship in v1 before creating public sales records; do not rely on a user-visible owner field for row-level access.

**Blocker:** No runtime implementation starts without Gate 0 approved; this plan is not an implicit implementation GO.

### Wave 1 — Domain, schema, security contracts (foundation)

**Scope**

- Canonical Lead, Opportunity, Stage, Pipeline, Participant and SalesEvent model.
- Confirmed stage-type vs record-state transitions, Won/Lost/Disqualified/Converted semantics.
- Reviewed indexed schema and versioned upgrade, fresh install and retry repair.
- Authorized repository queries and write actions, stable typed PHP projections.
- Integrity rules: existing CRM identity refs, many-to-many participants, unique conversion source, revision-checked updates, non-destructive delete/archive policy.
- No Admin screens beyond essential test fixtures.

**Minimum acceptance**

- [ ] Schema fresh install and upgrade from `1.5` preserve existing identity, notes, agreements, activities and other tables.
- [ ] Failed/interrupted migration retries safely; observed schema/index checks fail closed on partial state.
- [ ] Invalid/trashed foreign refs, cross-pipeline stages, stale revisions and duplicate conversion fail deterministically.
- [ ] Stable pipeline/stage identifiers and seeded defaults do not change after re-install/upgrade or translation switch.
- [ ] No Contact/Organization status changed as a side effect of qualification or closure.
- [ ] Permission matrix: unauthenticated, unrelated WP user, CRM staff, disabled CRM runtime, malformed nonce/actor, multi-user ownership and future role expectations documented and tested.
- [ ] Old CRM integration smoke tests remain green, especially Bookings/WooCommerce/Work pricing and public Contact API.
- [ ] Privacy data-handling inventory for Sales fields, retention/export/erasure behaviour and restricted audit metadata reviewed.

**Stop if:** any migration damages existing records, can expose deal fields to unauthorized users or returns a success on half-completed conversion.

### Wave 2 — Minimal usable CRM Admin workflow

**Scope**

- CRM → Leads list/details with quick create, linkage and qualification/convert actions.
- CRM → Opportunities list/details with stage transition, owner, amount/currency and Won/Lost actions.
- Search, stable sorting, bounded pagination, stage/owner/outcome filtering, empty/error states.
- Participants and linked identity navigation, useful call-to-action and recent Sales events.
- Keyboard-first accessible controls and server-side validation. No mandatory Kanban.

**Minimum acceptance**

- [ ] New Contact + Lead from explicit quick-entry flow avoids duplicate identity; ambiguity routes to operator review.
- [ ] Existing organisation-only Lead is accepted; existing customer can open a direct Opportunity.
- [ ] Different Deals with same Contact/Organization remain distinct; multiple Contact participant roles behave correctly.
- [ ] Stage transitions, conversion, close/reopen, loss reason and audited history visible and correct after reload.
- [ ] Board-independent workflow works on mobile/keyboard and with screen reader.
- [ ] No silent mail or Work task creation; no reliance on Bricks.
- [ ] Pagination/filter/permission tests cover realistic number of records, not only trivial fixtures.
- [ ] Test deletion and late opt-out/deletion against linked sales events and linked parties.

### Wave 3 — Optional Work linkage, daily follow-up and polish

**Scope**

- Clearly mark CRM next-action intent and Work completion as separate ownership.
- User-initiated linkage to existing Work Item using the public relations contract.
- Only if independently approved: add a user-initiated `Create Work follow-up` action via public `WorkItemActions::create_from_source()`, with per-action source identity.
- Graceful read-only/unavailable UX if Work disabled or actor lacks `cb_manage_work`.
- Optional compact board as secondary view, accessible fallbacks and saved filters only if justified.

**Minimum acceptance**

- [ ] No duplicate Work task after replay or double-click; no CRM writes into Work private tables.
- [ ] CRM Lead/Opportunity remains usable with Work deactivated and reactivated.
- [ ] Disallowed Work action cannot be performed through CRM by a user with only CRM rights.
- [ ] Work task status change never mutates sales stages automatically.
- [ ] Related Work actions are navigable but do not leak customer data across different staff permissions.
- [ ] Sales UI remains fast and usable without animation or drag and drop.

### Wave 4 — First-party Core Blueprint operational validation

**Scope**

Use the new generic Sales Foundation on coreblueprint.io as an ordinary customer would, not as special business logic hardcoded for Operator Pack.

**Minimum acceptance**

- [ ] Operator imports/creates a legitimate first small prospect batch with purpose/provenance and no automatic outreach assumptions.
- [ ] Move a real Lead through qualification; create one real Opportunity and its next action.
- [ ] Capture follow-up and Work link appropriately; check overdue views and actor ownership.
- [ ] Test Won and Lost flows on authorised non-production fixtures before using real commercial records.
- [ ] Verify pricing estimate does not invent paid revenue or paid customer state.
- [ ] Verify backups, restore, release rollback/recovery and no effect on existing Contacts/Organizations/Agreements.
- [ ] Collect actual non-sensitive usage evidence, performance observations and visual screenshots from the user; mark a feature accepted only with their approval.

## Test commands from repository source

The following commands were **read from the current CRM repository's actual scripts**, not executed in this design-only change:

```bash
cd ~/Downloads/wp-core-blueprint-crm
./tools/check
./tools/i18n/check
./tools/build-release
unzip -t dist/core-blueprint-crm.zip
sha256sum dist/core-blueprint-crm.zip
cat dist/core-blueprint-crm.zip.sha256
```

`./tools/check` already runs PHP syntax on all PHP files, CRM source/contract/smoke tests, i18n checks, and a deterministic double-release-package smoke. The release builder writes **`dist/core-blueprint-crm.zip`** and **`dist/core-blueprint-crm.zip.sha256`**, excluding `docs/`, `tools/` and `tests/` from the distributable ZIP. A documentation-only branch can be checked without claiming the ZIP contains RFC changes.

**New required regression tests for the implementation, not yet written:** fresh-install/upgrade/retry; legal state transitions; one identity-to-many Leads/Deals; duplicate/replay/concurrency conversion; cross-role permission; data deletion/export; input/XSS/SQL safety; USD/EUR mixed-currency aggregates; Work absent/disabled/permission denied; Work source relation idempotency; accessible list actions; and WordPress single/multisite compatibility where applicable.

## Branch, packaging and deployment governance

- This design branch is documentation-only. It is not an active implementation feature branch for runtime code until explicit acceptance.
- Each Wave should be a separately reviewable patch with its own exact repository, branch, SHA and changed-files audit.
- Do not modify Work in this RFC branch. A necessary Work enhancement gets its own specification, source review and approval.
- Build `dist/core-blueprint-crm.zip` from the reviewed source for a **runtime implementation** after all tests pass; record actual SHA-256 and package content. Do not distribute an untested sales build.
- Validate locally/staging, then present a non-destructive installation and functional/visual acceptance checklist. Do not merge, trigger CI/CD, deploy, publish or change live data without explicit action-specific approval.
- No numeric sales target or financial forecast may be labelled verified before actual operation.

## Recommended next action

**Review and accept the RFC’s foundational domain/storage/permission decisions.** Then begin Wave 1 with a narrow, controlled schema + API + regression-test patch. Do not start with drag-and-drop Kanban, forecasting, email campaigns or auto-importing existing customer data.

## History

- 2026-10-11 — Plan drafted from audited CRM/Work main source and verified CRM release scripts. No code, runtime tests, migration or deployment executed.
