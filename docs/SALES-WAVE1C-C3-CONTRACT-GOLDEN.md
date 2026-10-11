# CRM Sales Wave 1C — C3 Combined Staff Contract Golden Gate

**Recorded:** 2026-10-11
**Status:** SOURCE CANDIDATE; LOCAL PHP, WORDPRESS/MARIADB AND RELEASE ACCEPTANCE PENDING
**Repository:** Core-Blueprint/wp-core-blueprint-crm
**Branch:** feature/crm-sales-wave1c-contract-golden-v1
**Parent C2 technically accepted:** e51abaa1f42d56662f3a075ee1634acbef4174f8
**Previous real integration Base SHA:** 792cee2c42dbcf50c699f44e8b6108c3d1d4afbf
**Schema:** 1.6, unchanged

## Scope

C3 is a **verification-only consolidation** of Wave 1C and the previously validated Wave 1A/1B Sales repository contracts. It introduces no user-facing features or production PHP changes. C1 StaffProjections and C2 StaffActions remain separate, fully capability-gated internal PHP surfaces, and the B1–B3 repositories continue to own transactions, stale-revision conflict checks, conversion replay safety and append-only event history.

This is **not** a public customer API, REST route, AJAX endpoint, WordPress Admin permission/nonce implementation, generalized record-sharing engine, Work integration, marketing consent system, automated P2/P3 erasure/export contract or production deployment permission. The Sales title may contain sensitive staff-entered information, despite the absence of Contact/Organization PII joins: **staff projections are not public or anonymous**.

## Verified design contract

| Boundary | Requirement |
| --- | --- |
| Runtime | CRM/Base product runtime ready and exact CRM schema version 1.6 |
| Read C1 | Logged-in actor, canonical cb_manage_crm AND cb_view_crm_sales OR cb_manage_crm_sales |
| Write C2 | Logged-in actor, canonical cb_manage_crm AND cb_manage_crm_sales |
| Owner ID | Filtering and attribution metadata only, never a permission substitute |
| C1 page | Whitelisted filters, descending-ID keyset, <=50 records, explicit 10-field summary |
| C1 detail | Whitelisted scalar fields, <=50 numeric participant references, no wp_posts JOIN |
| C1 history | Ascending event-ID keyset, <=50 records, nine audit fields |
| C2 actions | Exactly eight typed operations, no generic dispatcher, no SQL in facade |
| Mutations | B1/B3 revision-checked transitions, B2 replay-safe conversion, unchanged errors |
| Audit | One committed event per successful transition; stale/denied requests must not add events |
| Transport | None in C3. Future browser handlers require nonces, action intent and capability checks |
| Governance | P2/P3 synthetic/internal MVP gate remains a separate approval requirement |

The existing raw Records::get() remains internal and is **not** the sanctioned Wave 2 staff-UI projection. Wave 2 should use StaffProjections for reads and StaffActions for writes, never direct Sales SQL.

## C3 tests added

- tests/sales-contract-golden-smoke.php: loads the real C1 and C2 classes against deterministic fake WordPress capabilities and SQL reads, with write repository stubs; exercises unready runtime, anonymous actor, outdated schema, CRM-only, Sales-only, staff-read-only, staff manager, actor/metadata isolation, exact public methods, no arbitrary dispatch, no SQL writes or unauthorized delegation, nine-field history and ten-field summary, and unchanged stale revision errors.
- tests/integration/sales-contract-golden.php: runs only inside the disposable isolated WordPress 7.x/MariaDB 10.11 setup. Uses a Base-approved operator and a newly created **synthetic Subscriber** to verify actual WordPress capability denies for all three C1 reads and all eight C2 writes, without record/event changes. Then it exercises C1 revision snapshot -> C2 qualification -> stale revision rejection -> fresh stage move -> B2 conversion + replay, followed by Won -> stale Reopen refusal -> explicit Reopen -> Lost, and reads the immutable event history via C1.
- Existing standalone B1/B2/B3/C1/C2 tests and real transaction/rollback/concurrency tests remain unchanged and registered.
- tools/check and tools/check-sales-integration invoke the additional C3 acceptance tests. No tests need production datasets or user information.

## Known limits

- A real Subscriber negative-permission check is **not** a complete audit of every possible Base privileged account/role. The isolated C3 fixture exercises all logical capability combinations with fakes; a separate privileged-role and row-level policy assessment may still be necessary before organizations introduce custom permission scopes.
- C1 staff-wide views do not implement row ownership restrictions; owner filtering is not authorization.
- No front-end transport, route, nonce or escaping is tested here because none is introduced by C3. These must be verified when Wave 2 creates Admin screens/forms/handlers.
- Any P2/P3 privacy, portability, retention, erasure, legal-basis or lawful-disclosure decision remains a distinct governance gate.
- The operator still needs to validate the complete test suite, synthetic WordPress/MariaDB integration and the current ZIP checksum. Since C3 changes **no packaged runtime code**, the ZIP may intentionally match C2 exactly; verify rather than assuming.

## Operator verification commands

The user's Git checkout has a narrow fetch configuration: new remote branches may not be fetched by ordinary git fetch, and automatic --track setup previously failed. First fetch the exact C3 ref and create/check out the local branch explicitly from that remote-tracking commit; do not pull another branch into the currently checked-out C2 branch.

~~~bash
cd ~/Downloads/wp-core-blueprint-crm

git fetch origin refs/heads/feature/crm-sales-wave1c-contract-golden-v1:refs/remotes/origin/feature/crm-sales-wave1c-contract-golden-v1

# Create a local C3 branch explicitly. On a first checkout, this is safe
# without --track and does not move the existing C2 branch.
git switch -c feature/crm-sales-wave1c-contract-golden-v1 \
  refs/remotes/origin/feature/crm-sales-wave1c-contract-golden-v1

git rev-parse HEAD
git branch --show-current
git status --short
git diff --check e51abaa1f42d56662f3a075ee1634acbef4174f8 HEAD
git diff --name-status e51abaa1f42d56662f3a075ee1634acbef4174f8 HEAD

php -l tests/sales-contract-golden-smoke.php
php -l tests/integration/sales-contract-golden.php
bash -n tools/check
bash -n tools/check-sales-integration

php tests/sales-contract-golden-smoke.php
./tools/check
./tools/i18n/check
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected new results:
- CRM Sales C3 joint read/write authorization and contract golden smoke passed.
- PASS: real MariaDB C3 joint C1/C2 read/write privilege boundary, stale revision, replay and immutable audit.
- CRM Sales real WordPress/MariaDB integration: PASS.
- Core Blueprint CRM checks passed.
- Six locale i18n PASS and ZIP integrity/checksum PASS.

The local source tree may contain untracked dist/ artifacts; preserve them. Existing nonfatal PO header and isolated sendmail warnings remain outside C3 scope.

**Acceptance:** If all tests pass, pin CRM C3 HEAD, tested Base HEAD and SHA-256 as the Wave 1C internal contract Golden candidate. No main merge, CI/Actions, distribution or production deployment without separate explicit GO.
