# CRM Sales P2/P3 — Minimum Governance Contract for the Internal MVP

**Recorded:** 2026-10-11
**Status:** DRAFT FOR OPERATOR ACCEPTANCE. Documentation-only implementation of Gate G0, not acceptance of controller/legal policy, P2/P3 runtime functionality, or a release.
**Scope:** Internal, single-site, staff-only Sales development and acceptance using synthetic identities and records.
**CRM branch:** feature/crm-sales-wave1-foundation-v1
**Reviewed Wave 1A HEAD:** 7f79e5a41f96388fa8717a97d9f53099973dcf30
**Tested Wave 1A runtime baseline:** 1d0a2745d18f738b0b838a2e67e4865a12443d8c
**Base reference for actual 1A integration tests:** 8544d1097d98c3a241a6c46b8c6fd0dcc27372e4

## 1. Purpose, evidence and non-authorisations

Gate G0 specifies the **minimum enforceable design boundaries** needed to begin Sales Wave 1B with synthetic data. It deliberately does not deliver data-subject rights handling, decide the controller's lawful bases, or make real-person sales intake lawful. A green foundation test or acceptance of this document is **not** approval for live processing, distribution, deployment, merge, multisite, or marketing claims.

Reuse rather than supersede:
- [SALES-WAVE1A-GOLDEN-AUDIT.md](SALES-WAVE1A-GOLDEN-AUDIT.md): technical Golden evidence and operational limitations; P0 deleted-user recovery, P1A oversight and P1B queue fairness are accepted within the tested single-site scope.
- [SALES-WAVE1A-PRIVACY-RETENTION-GATE.md](SALES-WAVE1A-PRIVACY-RETENTION-GATE.md): existing personal-data inventory, privacy risks and historical P0/P1 progression.
- [SALES-FOUNDATION-V1-RFC.md](SALES-FOUNDATION-V1-RFC.md): canonical identity, lifecycle, Work boundary, access and conversion invariants.
- [SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md](SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md) and [SALES-FOUNDATION-WAVE1-KICKOFF.md](SALES-FOUNDATION-WAVE1-KICKOFF.md): original sequencing: 1B governed lifecycle/conversion; 1C bounded PHP boundary; Wave 2 Admin experience.

No preexisting code, release artifact or evidence is changed by this document. Historical candidate descriptions in the privacy gate do not cancel its later Golden P0/P1A/P1B acceptance entries.

## 2. G0 decisions proposed for internal synthetic-only development

These are **proposed contractual guardrails**, not approved organisational legal policies.

| ID | Minimum rule for 1B/1C/internal Wave 2 | Verification / boundary |
| --- | --- | --- |
| G0-01 | Create/test Sales records only against explicitly synthetic CRM Contact/Organization identities on an isolated non-production WordPress site. | No copied leads, real customer/prospect exports, production restore, automatic import, real outreach, or live CRM datasets. |
| G0-02 | CRM Contact/Organization remains canonical identity; Leads/Opportunities are linked commercial records and require at least one valid party. | Preserve source-of-truth boundaries, active identity lifecycle and last-participant relational cleanup. |
| G0-03 | Internal access remains staff-wide and fail-closed: CRM runtime ready plus cb_manage_crm and the dedicated cb_view_crm_sales or cb_manage_crm_sales capability as appropriate. | Owner/assignment is metadata, **not** row-level access; no team-private or customer-facing data projections. |
| G0-04 | No marketing consent, enrichment, profiling, tracking, outbound integrations, background cross-extension mutations, or Work automation is inferred from Sales activity. | Explicit, authorized domain actions only; exclude new transport surfaces unless separately approved. |
| G0-05 | P2 data-subject access requires a verified person's relationship with the canonical CRM identity and a separately authorized data projection. | A bare email match, WP user account association, Sales participant link or staff permission alone is insufficient for full data-subject export. |
| G0-06 | Multi-party Opportunities, organisation-level relations, freeform titles, source references, estimates and staff audit fields are potentially mixed-party/confidential. | No automatic full-deal disclosure to one participant; field-level disclosure/redaction and human review remain pending. |
| G0-07 | Existing tested WordPress user deletion and CRM identity deletion recovery remain intact. These are **not** equivalent to an erasure request. | No new implicit cascade or general erasure action in 1B/1C; no disabling recovery/cleanup hooks. |
| G0-08 | No universal hard-coded Sales retention period, new automated destructive purge, inferred legal hold, or arbitrary claim that estimated Deals are accounting records. | P3 controller-approved purpose/category schedule, legal basis, holds, preview/review and fault recovery must precede production automation. |
| G0-09 | Retained audit and event metadata is minimized and governed by its actual data controller. | No names/emails/messages/tokens in Sales events; no deletion of Base Audit, WooCommerce, Bookings, Work or Service Agreement data by Sales-only actions. |
| G0-10 | The MVP's UI and documentation must label synthetic-only scope and unsupported privacy/team visibility behavior precisely. | No general production-readiness, AVG-compliance, WordPress.org or release claim from Gate G0. |

**Default for ambiguous choices:** fail closed or defer the feature; do not silently invent policy. G0 itself contains no code mechanism that enforces the synthetic-data restriction. Operator test-environment control remains mandatory.

## 3. P2 field and relationship inventory: minimum classification

A canonical ID can identify an individual when linked back to WordPress. Configuration labels may also carry identifying text if users customize them. The classifications below describe **review requirements**, not an automatic right to export each field.

| Sales table / source | Data categories requiring review | Tentative disclosure boundary |
| --- | --- | --- |
| sales_pipelines, sales_stages | Stable keys/IDs, custom name, ordering, active flags and timestamps | Site configuration, **not** automatically individual-specific export; check custom names for PII. |
| sales_leads | Title, owner_user_id, source/source_reference, participant-linked identity, stage/status, converted_opportunity_id, next_action_on, qualified/disqualified timestamps, revision and creation/update times | Potentially identifying, commercially shared; identify own facts before any export and redact unrelated parties. |
| sales_opportunities | Title, owner_user_id, source_lead_id, participant links, stage/outcome, amount_minor/currency (estimate), expected_close_on, next_action_on, won/lost timestamps, revision and record times | Potential personal and third-party commercial content; never assume entire Opportunity belongs to an individual requester. |
| sales_participants | Entity/party type and IDs, role, creation time | Linkable relationship data; one person's participation does not authorize disclosure of other participants. |
| sales_events | Entity reference, stage/outcome before/after, actor_user_id, revision, time, event type | Minimal operational history; audit/relevance, staff identity and any withholding/redaction require review. |
| Canonical CRM identities | Contact/Organization fields and relationships, including emails and user-account links | Identity source of truth owned by existing CRM; Sales only references, does not clone or independently release its complete data. |
| Governance / Base Audit | Actor/action references and compliance/operational logs in the appropriate owner domain | Separate retention and disclosure decision; no automatic cross-extension modification. |

P2 request flow **to design, not yet implemented**:
1. Intake, verification and authority check through a controller-approved process, including Contacts without WP accounts; do not trust unverified email-only matching.
2. Resolve verified Contact/Organization relationship with ambiguous matches, organisation representatives, and staff identity treated separately.
3. Bounded, capability-gated candidate lookup by canonical relation. Mark own data, shared business context and unrelated-person/staff data separately.
4. Human-authorised disclosure/redaction decision, minimally sufficient output, logged outcome and safe retry. A WP Privacy Tools adapter, if eventually used, must preserve this verification and field-level review boundary.

No new self-service portal, WordPress personal-data exporter/eraser registration, automated full-record export, or data-subject access endpoint is authorized by this document.

## 4. P3 deletion, retention and exceptions

**Distinct events, already present in Wave 1A (tested scope):**
- WordPress user deletion: durable retry minimizes Sales owner_user_id / actor_user_id after verified user disappearance; it does not by itself erase a CRM Contact or all Sales commercial history.
- CRM Contact/Organization permanent deletion: pre-delete intent and guarded relational cleanup detach that identity; a shared Sales record survives with valid remaining participants; the last-party case removes the orphaned Sales record and its events. Trash alone does not purge.
- Data-subject erasure request: **not implemented** and **not identical** to either event above.

**P3 workflow for later implementation, not Wave 1B:**
1. Controller classifies each category and purpose, records legal basis, retention trigger, chosen period (or a documented justification for another retention rule), review owner and applicable jurisdiction.
2. Evaluate legitimate retention obligations/claims, objections, shared participants and any documented legal hold with reviewable scope, authority and expiry/reassessment.
3. Generate a non-destructive, access-controlled dry-run showing which identity links, Sales records, history and unrelated extension records would be affected or explicitly preserved.
4. Require an authorized human approval for erase/anonymize/partial-retain/deny outcomes; preserve only minimum necessary decision/audit metadata and make partial decisions explainable.
5. Execute tested idempotent operations with concurrency checks, durable retry and evidence of completion. Never silently delete other extensions' data.

**No defaults inferred:** not a statutory deadline, no uniform retention duration for Leads versus Opportunities, no hold for all closed/won Deals, no claim of universal portability of shared commercial files. Base audit retention and any Woo/financial obligations are owned by their respective controllers and product boundaries.

### Controller decisions explicitly still OPEN (must not be treated as accepted defaults)

| Decision | Required responsible decision and evidence before real personal data |
| --- | --- |
| P2-D1 Verification/authority | Controller-approved identification route for people without WP accounts, organisation representatives and ambiguous Contact matches. |
| P2-D2 Disclosure/redaction | Field-by-field rule for own versus third-party information, multi-party Opportunities, financial estimates, source references, staff actors and excluded audit entries; redaction examples. |
| P2-D3 Request execution | Who may review/approve, how decisions/rejections/partial exports are recorded, and how requests/appeals/errors are resolved. |
| P3-D1 Purposes and legal bases | Controller/legal owner decides and documents lawful basis and distinct purposes for Leads, Opportunities, events and configuration. |
| P3-D2 Retention schedule | Category-specific period or case-based retention rule, start trigger, notice, review/expiry and testing; no durations embedded by developers. |
| P3-D3 Deletion and shared records | Participant dissociation versus record erase, residual commercial history, competing interests and retention exceptions, with documented lawful justification. |
| P3-D4 Holds and audit | Who may place/release a hold, scoped records, periodic review, minimal audit contents and lawful retention. |
| OPS-D1 Operational readiness | WP-Cron delivery and stalled-intent oversight, multisite semantics, InnoDB hosting policy, backup/restore, concurrency and performance evidence for supported deployment. |

No legal conclusion is made in this development document; the controller and, where necessary, qualified legal review own those determinations.

## 5. Implementation sequence and acceptance gates

**Do not re-scope the existing Wave names:**

| Gate / increment | Scoped output | Exit evidence |
| --- | --- | --- |
| G0 — this document | Accept synthetic-only guardrails and record P2/P3 unresolved decisions; no source changes | Explicit operator acceptance of **internal development boundary only**; legal/controller and real-data gates remain open. |
| B1 — Wave 1B, patch 1 | Guarded, revision-checked Lead/Opportunity stage and allowed Lead-state updates with minimal events | Role-denial, pipeline/stage mismatch, stale revision, duplicate action and SQL rollback tests; no implicit conversion. |
| B2 — Wave 1B, patch 2 | Explicit qualified Lead → Opportunity conversion, participant links, unique source and replay-safe transaction | Real InnoDB concurrent/retry/injected-failure tests; no half-commit or duplicate Opportunity; canonical IDs retained. |
| B3 — Wave 1B, patch 3 | Authorized Opportunity Won/Lost/Reopen transitions, minimal event/history contract and consolidation | Terminal-state rules, stale revision, audit minimization, failure/retry and full regression tests. |
| 1C — bounded PHP seam | Typed server-side projection/actions, bounded indexed searches and strict capability checks | Unauthorized users fail closed, fields minimized, stable pagination/query plans and no unapproved REST/customer surface. |
| Wave 2 — Admin MVP | Leads/Opportunities lists/details, existing identity links and manual workflow without mandatory board/Work | Keyboard/mobile/accessibility, XSS/CSRF tests, complete synthetic end-to-end operator review, screenshots and explicit acceptance. |
| G4 — separate real-person gate | Approved P2/P3 implementation, operational deployment and relevant controller/legal validation | Real export/erasure/retention, partial/shared cases, backing-store/hosting assumptions, multisite policy if supported, backups and operator release approval. |

Wave 1B should not invent a CRM identity/Contact creation API or extend Work in parallel. Quick-create for an existing Contact/Organization is sufficient initially; creating both a new canonical identity and Lead atomically is a later separately reviewed action. B1 must inspect the actual repository, existing Rule semantics, schema and tests before implementation; this document is **not** a GO to implement B1.

## 6. Minimum test and evidence matrix for G0 and future work

| Area | G0 review now | Required later |
| --- | --- | --- |
| Synthetic data separation | Check non-production fixture source; no real person identifiers or production dataset reuse | Audit test data, runtime permissions and any import paths before live release |
| Staff capabilities | Confirm existing cb_manage_crm + Sales capability contract in code | Reject logged-out, non-staff, capability-only without CRM and disabled runtime calls for each new action |
| Personal-data boundary | Compare the six-table inventory with Schema and Records | Field-level request projection, identity verification, redaction and mixed-party tests |
| Deletion integrity | Cite accepted P0/P1 and relational cleanup evidence | Partial erasure, shared/last participant, legal hold, failed cleanup/retry, audit and cross-extension isolation |
| Sales lifecycle | Confirm B1–B3 design remains separate from 1A and P2/P3 implementation | Real MariaDB transactions, revision/conflict, concurrency, replay, conversion and event minimality |
| Operation/release | Record explicit **HOLD** for real data, staging, release, merge and CI | WP-Cron, multisite decision, InnoDB, query/load, accessibility, backups/restores and approved release decision |

G0 acceptance is a **design review**, not a test PASS or runtime Golden. A docs-only build cannot substantiate P2/P3 compliance or runtime implementation.

## 7. Change control and immediate next action

**This change is docs-only:** add this file. Do not modify Sales schema, capabilities, WordPress hooks, UI, APIs, tools, CI, WordPress.org, distribution or deployed installations. The canonical release builder excludes docs; the Wave 1A runtime ZIP hash is historical evidence only until independently reproduced.

After operator review, record acceptance **only for the synthetic internal MVP contract**. Then request a separate explicit B1 GO and implement B1 on a separately reviewable increment from the verified feature HEAD. Before each future runtime patch: show commit SHA, changed files, full CRM/i18n checks, real relevant integration evidence, release ZIP integrity and independent visual/functional acceptance.

**Governance:** No merge, GitHub Actions, staging/production changes or release without separate explicit authorization.
