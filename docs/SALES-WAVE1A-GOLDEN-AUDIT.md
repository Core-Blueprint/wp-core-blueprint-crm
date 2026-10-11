# CRM Sales Wave 1A — Consolidated Golden Audit

**Audit date:** 2026-10-11
**Status:** TECHNICAL FOUNDATION LOCALLY GOLDEN, **NOT** production/WordPress.org/privacy-policy release approved.
**Tested source (before evidence-only documentation commit):** `1d0a2745d18f738b0b838a2e67e4865a12443d8c`
**Base dependency pinned in real tests:** `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`
**Branch:** `feature/crm-sales-wave1-foundation-v1`
**Tested local ZIP:** `dist/core-blueprint-crm.zip`
**SHA-256:** `e7539338e321ca4d6896675c78acf0e96738e8c9e297fba87200cdb86df32579`

## Executive conclusion

The schema 1.6 **Sales domain foundation** has passed the user's local PHP regression suite, canonical i18n checks and real WordPress 7.0/MariaDB 10.11.19 acceptance for the explicitly tested scenarios. The foundation is **not** the complete customer-facing Sales product, cannot claim overall AVG compliance, and is not authorized for a production, staging or public release. This acceptance is about technical invariants and controlled recovery rather than a user-facing CRM sales UI.

## Golden evidence matrix

| Increment | Real/local evidence | Result and boundary |
| --- | --- | --- |
| InnoDB/schema 1.6 | Six actual Sales tables, defaults, transaction rollback, unique index, Trash and deletion-recovery injection | PASS in isolated WordPress/MariaDB |
| Upgrade 1.5 to 1.6 | Nine v1.5 relational tables, three WordPress records, role migration, signed Base trust, two verify requests | PASS; independent disposable DB `cb_crm_up_jtm5mxjc` |
| Blocked migration/retry | Held official Base per-schema lock; 1.5 marker not advanced; Sales writes refused; retry after release | PASS; `cb_crm_up_fjxuxef1` |
| Concurrent Contact deletion-first | Two WordPress processes: permanent Contact deletion before locking Sales identity; no orphan | PASS; `cb_crm_it_yzhajylk` |
| Concurrent Contact creation-first | Lead creation locks Contact row and commits; WordPress delete waits, cleanup purges Lead, last participant and Events | PASS; `cb_crm_it_nagmwpit` |
| P0 deleted-user recovery | Actual WordPress user removal with synthetic SQL trigger failure and durable intent; explicit retry anonymizes owner/actor; control untouched | PASS; `cb_crm_it_0l1kujdc` |
| P1A operator oversight | 25 synthetic user intents + invalid post intent; two keyset pages, unauthorized/unconfirmed retry denied; confirmed one-ID action | PASS; `cb_crm_it_wlsfksrp` |
| P1B recovery fairness | 45 identity + 45 user intents, including malformed earliest entries; bounded cursor progress and wrap, unauthorized scan denied | PASS; `cb_crm_it_g9majpna` |
| Full CRM smoke/integration harness and six-locale i18n | `./tools/check`, `./tools/i18n/check`, PHP/Bash lint as applicable | PASS at Golden source; nonfatal PO header warnings |
| Deterministic distributable | `./tools/build-release`, `unzip -t`, SHA-256 file check and printed digest | PASS at Golden source; SHA above |

All disposable databases retained for inspection. No known existing database was dropped or reused. Source-level and test-mode fixes are traceable in the per-increment documents.

## Implementation gates currently CLOSED in tested scope

1. Versioned CRM Sales InnoDB schema, two default pipelines / seven default stages and permissions/capability migration.
2. Fail-closed staff authorisation and Base privileged operator trust in the isolated environment; no automatic non-staff Sales access.
3. Genuine Contact/Organization relational cleanup and replay after injected MariaDB failure; tested canonical Contact/Organization retention on Trash.
4. Both controlled two-process Contact deletion-versus-Sales-write orderings, assuming `wp_posts` supports InnoDB row-level locking.
5. Single-site WordPress deleted-user Sales owner/actor ID minimisation with durable pre-delete intent and successful retry.
6. Read-only operator recovery CLI with keyset pagination, signed privilege boundary, exact-kind-ID validation and explicit per-ID confirmation.
7. Bounded, progressing and separate cursor scheduling for Contact/Organization and user intents, preventing first-20 starvation in tested scenarios.

## Open gates: not part of this Golden claim

| Priority | Gate | Acceptance needed before broad deployment/marketing |
| --- | --- | --- |
| P0 operational | WP-Cron reliability / disabled cron / operator health view | Host-level trigger/delivery and stalled-intent recovery practice, not only scheduling correctness |
| P0 platform | WordPress Multisite user/site deletion | Explicit semantics for global users versus per-blog membership; independent tests |
| P1 integrity | Heavy-concurrency and multi-participant data races | Independent transaction interleavings/deadlock/retry tests, and explicit non-InnoDB policy |
| P1 domain | Query/index performance and bounded access projection for large Sales datasets | Explain plans, volume fixtures, pagination and record-level disclosure rules |
| P1 UX | Staff/admin Sales management interfaces, operator UX and reviewed error messages | Product acceptance independent of foundation tests |
| P2 governance | Data-subject authentication, verified CRM identity, export and privacy-tool integration | Controller-approved third-party redaction especially shared Leads/Opportunities; no unverified email-only inference |
| P3 governance | Erasure, partial retention, commercial/audit/legal holds, configurable schedules | Lawful-basis decisions, retention triggers, human review, safe dry-run, cross-extension boundaries |
| Quality | Translation PO missing standard headers; blocked-host sendmail warnings | Tidy translation metadata when the next i18n hardening wave is scoped; sendmail only matters to email delivery tests |

Note: the `RecoveryQueueScanner` runs as a capability-gated `admin_init` backstop. It does not guarantee delivery without qualifying admin traffic and functioning WP-Cron. Invalid intents remain for operator investigation, not arbitrary automatic disposal. P0 single-site `wp_die` on unavailable durable intent is an intentional security tradeoff and requires reviewed operator UX before live use.

## Decision and next recommended work

**Technical Wave 1A foundation: ACCEPTED for the controlled, tested local scope.** P0/P1A/P1B recovery hardening accepted. **Merge/deploy/public-release gate: HOLD**, because privacy P2/P3 and UI/access/performance/operational questions are still unresolved.

Recommendation: (1) treat this as a candidate handoff to an operator-led governance contract rather than prematurely implementing automatic erasure; (2) review the Sales personal-data field map and controller choices from `SALES-WAVE1A-PRIVACY-RETENTION-GATE.md`; (3) create a separate scoped P2 export/erasure design and test matrix only after the policy questions have concrete decisions. Do not invent retention periods or imply blanket data-subject access.

## Revalidation after evidence-only documentation updates

Only the above *tested source* and actual printed ZIP checksum are evidence. Documentation-only commits after that baseline must not be mistaken for a new runtime acceptance. Before deciding on merge or the next code wave, revalidate on the current feature HEAD:

    cd ~/Downloads/wp-core-blueprint-crm
    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    git diff --check origin/main...HEAD
    ./tools/check
    ./tools/i18n/check
    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

Expected ZIP SHA-256 remains `e7539338e321ca4d6896675c78acf0e96738e8c9e297fba87200cdb86df32579` **only if the Git diff is documentation-only**; confirm locally. No merge, Actions, staging or production without the user's separate explicit GO.
