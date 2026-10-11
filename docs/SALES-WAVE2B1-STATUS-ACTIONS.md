# CRM Sales Wave 2B1 — Authenticated Status Actions

**Date:** 2026-10-11
**Status:** SOURCE CANDIDATE; local PHP / real WordPress-MariaDB / i18n / ZIP acceptance pending
**Branch:** feature/crm-sales-wave2b1-status-actions-v1
**GitHub parent W2A:** ba6f23163c6d32a3b923e87d45676fecdd9d0b85
**Local W2A catalog commit not on GitHub:** 3cd03bc1e1590ce5241ae67843a85bf46baec2b9
**Previously tested Base:** caa8daf1e677ed688d2d253108b6021895e1df33
**Schema:** 1.6, unchanged

## Intent and bounds

W2B1 is the first **bounded** Wave 2B mutation patch. Existing Lead and Opportunity details offer manager-only status changes, mediated by authenticated WordPress admin-post.php. Not public REST, AJAX, customer-facing or builders.

Only five operations:
- Qualify open Lead
- Disqualify open or qualified Lead
- Mark an open Opportunity Won
- Mark an open Opportunity Lost
- Explicitly reopen a Won/Lost Opportunity

Each goes exclusively through the existing C2 StaffActions facade. C2 and underlying B1-B3 repositories retain complete responsibility for revision locks, legal transitions, rollback, actors, idempotency and append-only audit events. W2B1 does NOT implement new SQL.

No Create Lead/Opportunity, Move Stage, Convert Lead, participant authoring, row-level scoping, Work actions, export/erasure, or P2/P3 release approval; defer these to subsequent W2B patches and explicit governance.

## Security boundary

- One authenticated admin_post_cb_crm_sales_transition hook; zero nopriv hooks.
- Reject non-POST requests; reject a caller without positive WordPress user ID or canonical CRM/Sales write permission.
- Strict allowlist of six scalar WordPress-slashed keys: action, sales_op, entity_type, record_id, revision, _wpnonce.
- Allowlist operation/type pairing and positive integer ID/revision; no arbitrary method calls.
- WordPress nonce action binds operation, entity type, record ID and expected revision (WordPress also session/user/time bounds it).
- Form presents only operations applicable to C1-projected status; C2 domain rules enforce again within InnoDB.
- After success: fixed, same-origin CRM detail URL via wp_safe_redirect with 303; no user supplied redirect.
- Failed, stale, forbidden or invalid nonce: generic WordPress error with no private backend messages. A later UI increment can improve conflict UX.
- C1 viewers without Sales manage permission do not see forms.
- The GET query success notice is cosmetic, not authoritative.

## Test coverage

- Standalone tests/sales-admin-w2b1-smoke.php: five exact repository delegations, manager-only forms, forbidden method and extra/missing/array POST, cross-entity/action forged nonce, stale revision WP_Error propagation, and no nopriv.
- Real MariaDB tests/integration/sales-admin-w2b1.php: approved operator, synthetic Contact/Leads/Opportunities and unprivileged account, actual WordPress nonce verification, replay/stale-deny, transactionally committed qualified/disqualified/won/lost/reopen, narrow C1 history read-back and exact audit actor. Run only in fresh disposable test site.
- Existing B1-C3 and W2A tests remain mandatory. W2A operator integration assertion now expects an authenticated status form; W2A viewer-only standalone remains read-only.
- tools/check and tools/check-sales-integration include W2B1.

## Local-only W2A catalog commit

W2A reviewed catalog commit 3cd03bc1e1590ce5241ae67843a85bf46baec2b9 exists **only on the user's local W2A branch**. It is not present on GitHub's ba6f23163c6d32a3b923e87d45676fecdd9d0b85 source. This W2B1 branch starts from the exact GitHub W2A commit and does not change that Golden source. On local checkout cherry-pick **only** the W2A catalog commit into W2B1, then regenerate W2B1 strings.

Nine new strings are in the product-specific tools/i18n/sales-w2b1-translations.json. The product seeder now seeds both W2A and W2B1 datasets. The protected canonical tooling tools/i18n/catalog.py and reference.json remain unchanged, and the six generated PO/MO and POT should be locally committed only AFTER testing.

## Operator commands

~~~bash
cd ~/Downloads/wp-core-blueprint-crm

git fetch origin \
  refs/heads/feature/crm-sales-wave2b1-status-actions-v1:refs/remotes/origin/feature/crm-sales-wave2b1-status-actions-v1

git switch -c feature/crm-sales-wave2b1-status-actions-v1 \
  refs/remotes/origin/feature/crm-sales-wave2b1-status-actions-v1

git cat-file -e 3cd03bc1e1590ce5241ae67843a85bf46baec2b9^{commit}
git cherry-pick 3cd03bc1e1590ce5241ae67843a85bf46baec2b9

git rev-parse HEAD
git branch --show-current
git status --short
git diff --check ba6f23163c6d32a3b923e87d45676fecdd9d0b85 HEAD

php -l src/Admin/SalesStatusActions.php
php -l src/Admin/SalesPage.php
php -l tests/sales-admin-w2b1-smoke.php
php -l tests/integration/sales-admin-w2b1.php
bash -n tools/check-sales-integration
php tests/sales-admin-w2b1-smoke.php

# Six-locale reviewed datasets, canonical engine is never modified:
python3 tools/i18n/seed-sales-w2a.py
./tools/i18n/update
./tools/i18n/check-reference
./tools/i18n/check

./tools/check
./tools/check-sales-integration

git diff --check
git status --short
git diff --stat -- languages

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected:
- CRM Sales W2B1 nonce-gated status action transport and form smoke passed.
- PASS: real WordPress/MariaDB W2B1 per-record nonce, authorization, stale revision and five audited status transitions.
- Core Blueprint CRM checks passed.
- CRM Sales real WordPress/MariaDB integration: PASS.
- Canonical six locale i18n PASS, ZIP integrity and checksum PASS.

Once all PASS, commit the generated 13 catalog artifacts to the *local W2B1 branch*, rerun checksum/i18n checks, and report final CRM SHA/Base SHA/ZIP SHA. Browser acceptance remains separate.

No main merge, W2A branch modifications, push, GitHub Actions, staging, distribution or production deployment is authorized by this patch alone.
