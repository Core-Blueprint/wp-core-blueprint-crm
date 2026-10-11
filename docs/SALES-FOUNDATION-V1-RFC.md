# CRM Sales Foundation v1 — Product and Architecture RFC

**Recorded:** 2026-10-11

**Status:** DESIGN CANDIDATE, NOT APPROVED FOR IMPLEMENTATION

**Repository owner:** Core Blueprint CRM

**Scope:** Generic sales/qualification foundation for entrepreneurs, agencies and multi-user organisations; not an Operator Pack-specific workflow

**Source baseline:** CRM `main` `4e487145b49b396341732b5da9b16d2a608e0502`; Work `main` `0bf78e540bb4db8d366c20835c1b5625c281f319`

**Companion:** [Implementation and acceptance plan](SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md)

## 1. Problem and design intent

Core Blueprint CRM already manages **Contacts, Organizations, customer identity/relationships, notes, activity and customer Service Agreements**. Core Blueprint Work manages **Services, Projects, Work Items, planning, assignments, recurrence and billing readiness**. Both are reported by the site operator to run on coreblueprint.io; the deployed code SHA and live datasets have **not** been inspected.

Neither checked source baseline contains a dedicated Lead/Opportunity sales-lifecycle model. General CRM `active/inactive/archived` is a **relationship-record state**. Work `planned/in_progress/blocked/completed/skipped/cancelled` is an **execution state**. Neither should be repurposed for sales stages.

**Objective:** Model a customer's commercial journey independently from their enduring identity and independently from operational task completion. Deliver useful sales foundations while preserving existing first-party data ownership, customer independence, privacy and modularity.

**Explicit non-goals for v1:** marketing campaigns, email sequences, mass lead import/scraping, AI scoring, sales forecasts, invoice/payment truth, a generic automation engine, a second Work task manager, arbitrary custom entities or enterprise-level multi-tenant isolation.

## 2. Domain ownership and invariant boundaries

| Domain | Canonical owner | Sales Foundation relationship |
| --- | --- | --- |
| Contact and Organization identity, associated emails, addresses and relationships | CRM existing identity domain | **Reference existing canonical IDs**, never duplicate identity into Leads/Opportunities. |
| Lead and qualification outcome | CRM new Sales domain | Separate record for commercial interest before a specific qualified deal. |
| Opportunity / Deal and commercial stage, outcome, estimated value | CRM new Sales domain | A genuine sales possibility; may link to multiple people with different roles. |
| Sales stages/pipeline definitions and change history | CRM Sales domain | Stage definitions never overwrite identity status; stage movement auditable. |
| Relationship Notes and Activity | CRM | Existing Activity repository currently validates only Contact/Organization; **requires new Sales-aware contract or dedicated event persistence**, not unchecked reuse. |
| Work Item, assignee, due date, project and operational task state | Work | Optional association via documented **public** Work APIs; CRM must remain independently useful if Work is unavailable. |
| Service catalog, tax data and effective pricing resolution | Work + existing CRM agreement layer | Estimated deal value is not authoritative pricing and must not mutate the Work catalog. |
| Quotes, invoices, payments, subscriptions and finance documents | Their authoritative product/provider | Opportunities may hold references, never become accounting truth. |
| Communication permissions, suppression, campaign eligibility | Future communication provider / specific channel | CRM lead existence is **never** consent to send marketing. |

**One canonical identity, many commercial interests:** A single Contact or Organization can own multiple Leads and multiple Opportunities; acquisition status belongs to the commercial records, not to the person.

**Existing customers can create new opportunities.** The identity record remains unchanged whether a sale is Won, Lost or not currently being pursued.

## 3. Lead and Opportunity are separate entities

### 3.1 Lead

A Lead is a **potential commercial interest to qualify**. It must point to at least one existing CRM **Contact or Organization**. It can link to both. It may originate from a referral, enquiry, an event or a legitimate founder-led outreach conversation; source is provenance, not proof of communication permission.

**Creation paths:** (a) attach to an existing Contact/Organization; (b) *quick capture* runs an explicit, authorised CRM identity-create action and then Lead creation, with collision/ambiguity checks and safe recovery. It must **not** invent anonymous WordPress users or silently promote email matches to canonical Contacts.

**Lead fields, initial contract:**

- stable ID and `created_at/updated_at` (UTC), `created_by`, `owner_user_id`;
- primary Contact and/or Organization references (at least one valid), optional related parties;
- title or concise need, source code + optional reference, qualification context;
- lead pipeline ID, stable stage ID, `qualified_at`, `disqualified_at`, optional disqualification reason;
- `converted_opportunity_id` only after durable, confirmed conversion; version/revision for concurrent updates.

**Lead lifecycle:** New → Attempting Contact → Connected → Qualified, with separate **Disqualified** and **Converted** terminal outcomes. `Qualified` means validated potential, **not** Closed Won and not a new Contact. Conversion is explicit; qualifying alone does not silently create a Deal.

### 3.2 Opportunity / Deal

An Opportunity is a **specific, qualified, actionable sales possibility** for a product or service. Creating one directly for an existing customer is valid; a Lead is **not** mandatory.

**Opportunity fields, initial contract:**

- stable ID, name/need, description, `owner_user_id`, UTC creation/update and revision;
- CRM Organization and/or Contact references (at least one required), plus additional participant links;
- sales pipeline ID, current **open** stage ID, separately stored result `open | won | lost`;
- amount estimate in **minor currency units** and explicit ISO currency code; optional expected decision/close date;
- `won_at/lost_at`, loss reason where applicable, optional external source/reference and converted Lead ID;
- commercial outcome metadata as an estimate only, never a paid-invoice claim.

**Default open stages:** Discovery → Proposal → Decision; **Won and Lost are outcomes, not ordinary draggable open stages**. A Deal may skip a stage when justified; terminal transition requires an explicit operator action and must have a controlled reopen policy.

**Participant roles:** `primary_contact`, `decision_maker`, `influencer`, `advisor`, `other` (initial vocabulary). Multiple Contacts per Opportunity are supported in the data model from the outset. Do not assume each participant belongs to the same Organization; record and validate the actual association.

**Multi-currency:** Store amount and currency explicitly; never sum amounts from different currencies as if comparable. No foreign-exchange conversion or tax-inclusive revenue claim in v1.

## 4. Pipelines, stages and outcome semantics

### 4.1 Stable contracts

Keep an explicit `pipeline_type` (`lead` or `opportunity`) and durable pipeline/stage identifiers independent from localized display labels or sort orders.

**v1 UX:** supply one usable default Lead pipeline and one usable default Opportunity pipeline. User-managed pipeline/stage configuration is a **later increment**, but the v1 storage and query contracts must permit multiple pipeline IDs and ordered stage definitions without changing record identity.

A stage belongs to exactly one pipeline and compatible entity type. A record cannot transition to a foreign/disabled stage; records already on a disabled stage require an explicit, audited migration strategy before such a setting may be removed. Never equate pipeline stage IDs with WordPress post statuses or Work Item states.

**Stage transition:** canonical server-side action checks actor permission, allowed stage/type, expected revision, valid entity references and current terminal outcome. Record old/new IDs, actor and timestamp, with no unrestricted free text or secrets in audit context. Reject stale updates rather than silently overwriting.

**Defaults are examples, not business facts:** Users may later define their own commercial pipelines without us rebuilding the sales domain.

### 4.2 Conversion invariants

`Lead → Opportunity` must:

1. Authorise the actor and re-resolve current Lead, Contact(s) and Organization(s).
2. Refuse ambiguous/archived/trash references according to documented policy.
3. Detect or return an already-linked Opportunity on replay (idempotent conversion).
4. Create the Opportunity and participant associations, preserving a stable link to the source Lead.
5. Finalise the Lead as Converted **only after** the Opportunity and source link are durable.
6. Recover safely if interrupted between related writes; never report successful conversion if only half completed.
7. Emit minimal event metadata, excluding raw note contents, emails and sensitive deal descriptions.

Do not auto-create a WordPress account, Work Project, WooCommerce order, invoice or Service Agreement during conversion.

### 4.3 Won/Lost and reactivation

Winning or losing an Opportunity does **not** set the parent Contact or Organization to Active/Inactive/Archived, activate licenses or assert a paid invoice. It records a commercial outcome only. A new opportunity or authorised reopen is valid later; reopened records retain prior outcome history.

## 5. Storage architecture — design decision

**Recommendation:** Use a CRM-owned, **indexed relational sales storage layer**, with internal repository/domain contracts and WordPress-native admin navigation. This complements the existing CRM design, which already uses CPTs for Contacts/Organizations and relational tables for associations and history.

**Why not force Lead/Opportunity into general CPT meta?** Multi-participant links, stage changes, pipeline filters, conversion idempotency, per-owner queues and revenue aggregates are inherently relational. Heavy `postmeta` sorting/filtering is a weak foundation for a scalable sales board. CPTs remain an option if benchmark evidence later shows a real benefit; no schema is approved merely by this RFC.

**Proposed logical tables (names provisional, not migration code):**

- `cb_crm_sales_pipelines` and `cb_crm_sales_stages`: typed, ordered, immutable IDs; label/settings, active flag;
- `cb_crm_leads`: stable lead identity, owner, stage/status, timestamps, revision, provenance and conversion link;
- `cb_crm_opportunities`: identity, owner, pipeline/stage, outcome, amount/currency, dates, external reference and revision;
- `cb_crm_sales_participants`: typed Lead/Opportunity → CRM Contact/Organization relations and role, index by both sides;
- `cb_crm_sales_events`: durable stage/status/conversion audit events, keyed by entity, revision and time.

**Required constraints:** indexed entity/pipeline/stage/owner/outcome/due queries; unique source conversion link; unique participant tuple; unique stable pipeline/stage keys in scoped domain; bounded pagination; defensible deletion/retention. Verify MySQL/MariaDB indexes and generated SQL on supported shared hosting. WordPress schema upgrade is **versioned** through existing `CB_CRM_SCHEMA_VERSION` / `SchemaRegistry`, with staged failure handling and no destructive table reset.

**Schema-review gate:** Before writing a migration, produce exact DDL/indices and a tested interruption/retry strategy. Distinguish actual DB atomic guarantees from eventual recovery when crossing WordPress entities and separate tables. Include rollback/recovery and preservation of existing Contact/Organization/Service Agreement rows. Never mutate existing identity data to simulate a new sales state.

## 6. Public API, permissions, data protection

**Public contracts (proposed, not present):**

- `CB\CRM\PublicApi\Leads::get/query`, `Opportunities::get/query`, `Pipelines::list/stages`: stable scalar/array projections, bounded queries and documented audience;
- `CB\CRM\PublicApi\SalesActions::create_lead/qualify/convert/create_opportunity/transition/close/reopen`: governed, server-side PHP mutation facade; design read vs mutation permissions and authorization before implementation;
- `Frontend\Queries\...` for explicit, staff-only/admin and future customer-safe views. **Do not** expose pipeline financial data to normal Contact frontend users.

**Access foundation:** Existing CRM has one `cb_manage_crm` capability. For v1, maintain its fail-closed boundary and plan independent `cb_view_crm_sales` / `cb_manage_crm_sales` capabilities with explicit role migration and tests before multi-user launch. `owner_user_id` is assignment metadata, **not** a permission boundary by itself. Per-record visibility and organisational separation require a distinct approved access model; do not claim they are available now.

**Transport:** Authenticated admin actions must enforce capability/record scope + nonce/CSRF protection; nonces are not authorisation. All SQL uses prepared statements. Never accept a caller-supplied actor as authoritative; protect against stale revisions, tampering, race-condition double conversion, reflected/stored XSS and unrestricted exports. Public PHP contracts are not automatically REST endpoints.

**GDPR and trust:** Minimum data and clear purpose; record provenance and appropriate contact eligibility separately from marketing permissions; support retention, access/export, correction and erasure review with related sales records; define what historical records may be anonymised vs retained under a lawful basis. Restrict amount/loss-reason notes and audit projections; preserve a data-processing inventory. No external analytics, bulk-enrichment, Google/Microsoft APIs or third-party AI by default.

**Reporting:** Aggregate counts and stage aging only after correctly defining state, time zone, exclusions, multi-currency and closed-vs-open semantics. No misleading `revenue` label for estimated deal amounts.

## 7. Work integration: separate concerns, stable relationships

Work's verified public operational API already exposes `WorkItems::by_relation()`, `WorkItemActions::create_from_source()` and `add_relation()` with the `cb_manage_work` capability. This is an integration **option**, not a dependency.

**v1:** allow operator-managed, optional linkage between a CRM Lead/Opportunity and a Work Item. CRM owns next-contact intent/due facts; Work owns actual work item, assignment, work-status and reminders/due queue. Avoid maintaining two editable copies of due date or task status. Distinguish CRM opportunity `owner_user_id` from Work task assignees.

**Later (explicit separate gate):** a user action `Create Work follow-up` may call Work's public `create_from_source('crm','opportunity_follow_up', stable_source_id, ...)` using an idempotency key for **one intended action**, not simply an Opportunity ID reused across multiple follow-ups. That operation requires BOTH the CRM permission and Work's `cb_manage_work`; it must return a clear unavailable state when Work is disabled. For ordinary manual Work tasks use `add_relation()`. Never read Work private tables or manufacture task completion in CRM.

**Default absence:** Without Work installed/ready, Leads, Opportunities, stages, notes and events must still function.

## 8. UX and accessibility requirements

- Add **CRM → Leads** and **CRM → Opportunities** with overview lists, useful filters, quick creation and direct links back to existing Contact/Organization editors.
- First useful action always visible: add lead, convert qualified lead, add opportunity, record an outcome, see next action.
- List view is the canonical accessible workflow; a board is an additional view and not a prerequisite for use or release.
- Keyboard-equivalent stage controls; never drag-and-drop-only. Clear screen-reader labels, status feedback, confirmation for terminal actions, no misleading colour-only semantics.
- Progressive disclosure of advanced role/amount fields, safe empty states, touch/mobile support, consistent Base Admin Theme composition, no Bricks dependency.
- Show `Opportunity value` as an **estimate**; avoid misleading probability or revenue labels.
- Separate visual sections for linked CRM identity, the Lead/Opportunity itself and related Work actions; no surprise propagation of edits into siblings.

## 9. Release and acceptance boundaries

- No existing CRM Contact/Organization/Note/Activity/Agreement behaviour is allowed to regress.
- No running site records converted automatically; no implicit lead import from existing contacts/tags.
- No deployment or public sales feature claim without tests, real-data backup and user acceptance.
- No automatic access to sensitive lead records for visitors or logged-in non-CRM users.
- Avoid creating duplicate customers, contacts, tasks or converted deals on retries and concurrent requests.
- Product source-of-truth and runtime build remain in CRM; wider sales strategy/targets remain in the company Overseer; Work remains operational authority.

**Candidate decision requiring user acceptance:** Approve separate Lead and Opportunity entities, canonical identity links, relational sales storage, one default pipeline of each type for v1, and optional Work integration. This RFC records the recommended direction but authorises **no runtime code change, merge, deployment or launch**.

## Evidence inventory (read 2026-10-11)

- CRM: `core-blueprint-crm.php` (`1.0.0-rc1`, `CB_CRM_SCHEMA_VERSION=1.5`), `src/Content/PostTypes.php`, `src/Content/RecordStatus.php`, `src/Database/Schema.php`, `src/Repository/Activity.php`, `src/Capabilities.php`, `src/PublicApi/Contacts.php`, `docs/INTEGRATION-API.md`.
- Work: `docs/DOMAIN-BOUNDARIES.md`, `docs/PUBLIC-OPERATIONAL-API.md`, `src/PublicApi/WorkItems.php`, `src/PublicApi/WorkItemActions.php`, `src/Integration/CRMCustomers.php`, `src/Domain/WorkItemStatus.php`.
- A source read is not a runtime or database migration test.

## Changelog

- 2026-10-11 — First evidence-led architecture RFC, before implementing any new sales domain code.
