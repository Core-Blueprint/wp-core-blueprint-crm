# CRM Sales Wave 1C — C2 Staff Action Facade

**Date:** 2026-10-11
**Status:** SOURCE CANDIDATE. LOCAL PHP / WORDPRESS / MARIADB / RELEASE ACCEPTANCE PENDING.
**Repository:** Core-Blueprint/wp-core-blueprint-crm
**Branch:** feature/crm-sales-wave1c-staff-actions-v1
**Parent C1 local Golden:** a96bed7f0ded01cff1b0b5ab7080337b91b3675d
**Last C1 real integration Base SHA:** 7c0c823bdf2edcb84a0566458257b16e3bde72d5
**Schema:** 1.6, unchanged.

## Internal scope, not a transport

C2 introduces CB\CRM\Application\Sales\StaffActions, a **staff-only, server-side PHP facade** for the explicit repositories of Waves 1A-1B. This unifies the intended future WordPress Admin action contract. No REST, AJAX, WP Admin page, nonce handler, public builder provider, CLI action, generic mutation router, customer-facing access or cross-extension call is added.

Each method checks all the following *before delegation*:
- ready CRM product runtime via canonical Access::can_manage();
- both cb_manage_crm and cb_manage_crm_sales capabilities;
- logged-in actor with positive current WordPress user ID;
- exact ready CRM schema version 1.6 via CB_CRM_SCHEMA_VERSION and Schema::OPTION.

The repositories repeat their own access, domain, transaction and revision checks. Each facade method returns the **unchanged repository result**: int (a new record ID, converted Opportunity ID or committed revision depending on method), or WP_Error with the **original** repository code. The facade performs no SQL and does not catch or suppress repository errors.

This gate is *not a CSRF boundary*. A future browser/action transport must establish a nonce and explicit intent **as well as** the authenticated capabilities and record-scope policy. The facade is not automatically a PublicApi surface and remains internal until a separate publication gate.

## Eight typed methods

| Method | Returns int on success | Existing domain owner |
| --- | --- | --- |
| create_lead(input, participants) | Created Lead ID | Records::create_lead |
| create_opportunity(input, participants) | Created Opportunity ID | Records::create_opportunity |
| qualify_lead(lead_id, expected_revision) | New Lead revision | Transitions::set_lead_status(qualified) |
| disqualify_lead(lead_id, expected_revision) | New Lead revision | Transitions::set_lead_status(disqualified) |
| move_stage(type, id, stage_id, expected_revision) | New record revision | Transitions::move_stage |
| convert_lead(lead_id, expected_revision, opportunity_pipeline_id, opportunity_stage_id) | New or verified replay Opportunity ID | Conversion::convert |
| close_opportunity(id, outcome, expected_revision) | New Opportunity revision | Outcomes::close(won/lost) |
| reopen_opportunity(id, expected_revision) | New Opportunity revision | Outcomes::reopen |

Creation inputs are the original repository allowlists; no arbitrary owner, actor, outcome, status, conversion link, consent, Work ID or SQL field may be supplied. No implicit conversion from qualification. No direct Won-to-Lost change. B2 replay returns the existing reciprocal Opportunity ID only after its repository verifies both directions; the facade must not introduce a second idempotency mechanism.

C1's StaffProjections remains the approved bounded internal read path. The earlier raw Records::get() is **not** the UI read contract. Neither the facade nor C1 implements user-by-user record sharing, data-subject export, P2 data portability, P3 retention, or production release.

## Validation and tests

- tests/sales-staff-actions-smoke.php uses controlled stub repositories to prove no forbidden method delegates, confirm exact arguments/IDs/revisions/status constants for all eight methods, verify original WP_Error propagation, and prevent accidental arbitrary public dispatch.
- tests/integration/sales-staff-actions.php uses synthetic Contact and Organization identities in a **fresh disposable** WordPress/MariaDB test site. It covers all eight operations, improper field/role input, forbidden anonymous callers, stale revisions, duplicate qualification, replay-safe conversion, disqualification, terminal states, explicit Reopen and audit chronology read back through C1.
- Canonical tools/check and tools/check-sales-integration both execute the C2 tests after all preexisting B1-C1 tests. The B2 concurrent two-process conversion race remains mandatory.
- These tests are not substitutes for future browser nonce, accessibility, permission-scoping or P2/P3 acceptance.

### Operator acceptance commands

~~~bash
cd ~/Downloads/wp-core-blueprint-crm
git fetch origin
git switch feature/crm-sales-wave1c-staff-actions-v1
git pull --ff-only origin feature/crm-sales-wave1c-staff-actions-v1
git rev-parse HEAD
git status --short

# Pinned Golden C1 SHA, regardless of local remote-tracking branch availability.
git diff --check a96bed7f0ded01cff1b0b5ab7080337b91b3675d HEAD
git diff --name-status a96bed7f0ded01cff1b0b5ab7080337b91b3675d HEAD

php -l src/Application/Sales/StaffActions.php
php -l tests/sales-staff-actions-smoke.php
php -l tests/integration/sales-staff-actions.php
bash -n tools/check-sales-integration
php tests/sales-staff-actions-smoke.php
./tools/check
./tools/i18n/check
./tools/check-sales-integration

./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
~~~

Expected success markers:
- CRM Sales C2 guarded staff action facade delegation smoke passed.
- PASS: real MariaDB C2 guarded staff facade creation, qualification, conversion, stages and Won/Lost/Reopen with C1 audit.
- CRM Sales real WordPress/MariaDB integration: PASS.
- Core Blueprint CRM checks passed.
- Canonical six-locale i18n PASS.
- ZIP integrity and actual generated SHA-256 PASS.

Note: a new runtime PHP file necessarily changes the ZIP checksum relative to C1. The i18n .po header warnings and absent local sendmail in the isolated test site have been preexisting nonfatal housekeeping items.

## Senior governance and next steps

C2 technical Golden requires user/operator acceptance of all local results **and** recording exact CRM SHA, Base SHA and artifact checksum. Only then propose C3: consolidated read/write contract, privilege-scoping and failure cases as a separate gate. C3 must not imply browser transport/nonce acceptance; these remain Wave 2 concerns.

No merge to main, GitHub Actions, WordPress.org, staging, production, plugin distribution, P2/P3 processing or Work integration is authorized by C2 alone. Keep all prior Golden branches intact.
