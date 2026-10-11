# CRM Sales Wave 1A: Privacy, Erasure, Retention and Recovery Gate

**Date:** 2026-10-11
**State:** Code-audited design candidate, **NOT** accepted operational/legal policy and **NOT** runtime implementation.
**Source baseline:** CRM `a217e08577a9e225fde2e81dad43cb99be3cf5ba`, Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`.
**Evidence:** Source inspection of `Schema`, `Records`, `Lifecycle`, `LifecycleCleanup`, `LifecycleRecovery`, `Governance`, and the previously approved Sales RFC/plan. Both MariaDB concurrency orders passed locally; this does not imply GDPR feature acceptance.

## Privacy boundary: known facts, not legal assumptions

Core Blueprint CRM Contacts and Organizations are the canonical identities, stored as WordPress CPT records. Sales Leads and Opportunities are **commercial records**, not additional Contact identities. They may contain directly or indirectly identifying text even though the normal Sales creation API does not copy Contact email/address into Sales tables. Title, source/source_reference, linked participants, owner/actor IDs, conversion links, commercial estimates and times may all relate to identifiable people. Freeform details in titles can therefore create PII; an ID alone can still be identifying when it is linkable to WordPress records.

Sales stores six tables:

| Table | Identifiability / purpose | Current handling | Required gate |
| --- | --- | --- | --- |
| `sales_pipelines` | Site configuration (names may reference people) | Seeded; admin-configurable | Review custom names when exporting full site configuration, not automatically attached to every person's request |
| `sales_stages` | Site pipeline taxonomy (custom names) | Seeded; admin-configurable | Minimise names and preserve user configuration in ordinary Sales lifecycle |
| `sales_leads` | Potentially identifying title, provenance, owner WP user ID, conversion/ref, schedule | Created under capabilities; no privacy export/retention contract | Export only if identity and data-subject relationship can be authoritatively established; safe erasure policy required |
| `sales_opportunities` | Potentially identifying title, owner, conversion ref, expected date, monetary estimate/currency | Created under capabilities, no accounting source-of-truth | Same, with special care for commercial confidentiality and any separate lawful retention grounds |
| `sales_participants` | Contact/Organization reference, Sales record ID and participant role | On Contact/Org permanent deletion, detach; if last participant, purge Sales record and Events | Document partial versus complete erasure semantics, including multi-party records |
| `sales_events` | Entity/stage/outcome/revision history, WP actor user ID, UTC time | Actor user ID zeroed on WP user deletion; orphaned Events deleted | Minimal PII, lawful retention review, export only when relevant; audit metadata may persist separately |

Minimal `Governance` audit entries include numeric record IDs and actor IDs. The Core Blueprint Base Audit retention controller owns audit log policy; erasing a CRM participant is **not** blanket permission to delete Base audit history or Woo/financial records.

## Identity and event distinctions

1. **Delete WP login:** existing `Lifecycle::deleted_user()` calls `LifecycleCleanup::for_deleted_user()` to set Sales `owner_user_id=0` and Events `actor_user_id=0`, transactionally. The linked CRM Contact is **not** automatically erased; its WordPress login meta is unlinked. This is the intended product boundary, distinct from an erasure request.
2. **Permanent delete CRM Contact or Organization:** the existing pre-delete durable intent is saved before WordPress deletion. Sales participant relations are deleted inside CRM's relational cleanup. If other canonical participants remain, shared Lead/Opportunity and events remain; if not, the Sales row and its events are deleted. Trash does not purge.
3. **Failed Contact cleanup:** persisted intent, guarded `deleted_post` retries and bounded admin rescheduling exist, but scheduler health, queue starvation and operator visibility still require acceptance.
4. **Failed WP-user anonymization:** **HIGH RISK, currently not durable**. The WordPress user may already be gone while SQL owner/actor updates rolled back; `Lifecycle::deleted_user` presently only records a Governance failure and an error log. No persisted retry intent or safe replay mechanism is evidenced. This must be addressed before claiming reliable user-ID minimization.
5. **Data-subject access/export/erasure:** **NOT IMPLEMENTED** for Sales; do not claim WordPress Privacy Tools support or infer ownership merely from a matching email address. Contact-based data subject relationship, multiple linked people, organisational data, and staff actor identities need an explicit authorization/verification gate before export/erasure.
6. **Other records:** WooCommerce orders, invoices/payments, Bookings, Work, Service Agreements and Base Audit have their own sources of truth; a CRM Sales privacy request must not silently alter them.

## Proposed privacy contract for review (NOT defaults implemented)

- **Data minimisation:** Store canonical CRM IDs, typed roles, actor references and structured stage/outcome changes; avoid cloning names, emails, phone numbers, message contents, IP addresses, tokens or notes into Sales Events or audit metadata. Treat human-entered Sales titles/source references as potentially personal.
- **Access and rectification:** Staff-only capabilities do not equal data-subject access. Verification/role isolation must be explicit; change histories should not expose other participants' personal data to a requester.
- **Export:** Build a bounded Sales projection by verified canonical Contact/Organization ID. Export only records containing that participant and clearly distinguish the requester's own data from third-party commercial details and other contacts' roles. Avoid automatic full-record export of multi-party opportunities. Review redaction policy before implementation.
- **Erase:** Distinguish account unlink, Contact deletion, Sales participant dissociation, erasure of sole-participant Sales history, and legal hold on otherwise required business information. A verified data-subject erasure request is not synonymous with deletion of every shared commercial deal; explicit outcome and audit trail required.
- **Retention:** **No universal hard-coded period in Wave 1A**. Require a controller-operated, documented schedule by category and purpose, with triggers (e.g. closure/last activity), documented lawful ground, hold override and an explicit deletion/anonymisation path. Do not invent statutory periods or assume accounting records apply to draft deal estimates.
- **Governance and integrity:** Explicit reasons for an erasure denial/partial response, minimal audit entry excluding sensitive content, predictable retry on failure, and auditable operator review.
- **Default runtime stance:** No automatic marketing consent, enrichment, external analytics, profiling, or cross-extension writes. No automatically scheduled destructive retention purge until controller choices and tests are approved.

## Implementation sequence and acceptance (proposed)

**P0. Durable WP-user anonymisation retry.** Persist a minimal deletion/anonymisation intent before the relevant irreversible WP deletion boundary, or establish a robust post-delete repair inventory; ensure failure rollback does not drop required work. Guard all retries; only zero matching `owner_user_id` and `actor_user_id`, with idempotent SQL transactions and governance metadata. Test failed SQL/commit, persisted repair, replay, and unrelated accounts unchanged under real MariaDB. Include WordPress multisite hook coverage when supported.

**P1. Operator recovery observability.** Bounded pagination over durable intents with accurate pending/stalled status, cron and WP-CLI explicit retry, ownership checks and no silent discarding of malformed intents. Address the present `REPAIR_LIMIT=20` scan starvation risk if the first 20 options remain inert. No email/cloud dependency by default.

**P2. Verified data subject projection.** Decide a canonical identity verification route (not an unverified email-only join), enumerate Sales data fields/links, implement access-controlled and third-party-safe export, and cover multi-party and shared Organization cases.

**P3. Policy-led erasure and retention.** A reviewed per-purpose schedule and hold policy, test-only dry-run preview, explicit operator review, no silent cross-product cascade, and deterministic purge/partial-response acceptance tests. Align with Base governance without placing legal policy decisions in code.

## Required decision record before P2/P3

Decide which commercial fields (e.g. owner, title, estimate, source references, closed-stage history) constitute the data subject's own exportable information versus third-party information; whether shared opportunities survive participant erasure; what lawful basis applies to retained business records and the categories/timing of retention; which logged/audit metadata can remain; whether contacts with no linked WP account can request exports using site-specific verification. These are controller/legal governance decisions, not assumptions made by CRM automation.

## Gate and release

Wave 1A runtime code for the foundational Sales schema and both InnoDB race orderings is locally golden at the source baseline above. This privacy gate remains **OPEN** until P0/P1 and at least the relevant P2/P3 contract decisions have been validated. Do not merge, deploy, enable live sales intake, publish privacy claims or process real Sales personal data based on a passing schema/concurrency check. Separate explicit GO is required for merge, manual GitHub Actions, staging and production changes.

## P0 implementation candidate (2026-10-11, not yet locally tested)

A narrowly scoped single-site implementation now exists at `src/Repository/UserAnonymizationRecovery.php`, integrated via `Lifecycle.php`. It stores a non-autoloaded per-user numerical intent before WordPress user deletion, schedules a retry, and after deletion verifies direct database absence before re-running the existing transactional owner/actor-ID anonymization. Failure retains the intent; duplicate replay is harmless. For single-site deletions, inability to persist/schedule the durable intent (or an incomplete Sales 1.6 schema) deliberately blocks the user deletion with a WordPress safety error, rather than silently permitting an irreversible deletion with unminimized references.

An isolated PHP smoke is registered in `tools/check` and a new opt-in real MariaDB fault-injection integration is available with `CB_CRM_TEST_USER_RECOVERY=1 ./tools/check-sales-integration`. **Neither has been reported as run locally.** This P0 candidate does not establish multisite readiness, full data-subject erasure, operator queue scalability, or a retention schedule. See `SALES-WAVE1A-USER-ANONYMIZATION-RECOVERY.md`.
