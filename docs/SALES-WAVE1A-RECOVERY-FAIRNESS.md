# CRM Sales Wave 1A P1B: fair recovery rescheduling

**Status:** GOLDEN PASS, local WordPress/MariaDB and full regression acceptance on 2026-10-11. Site-wide runtime release remains gated.
**Date:** 2026-10-11.
**Scope:** Existing single-site WordPress CRM Contact/Organization permanent-deletion recovery and Sales deleted-user anonymization. No new data tables or external services, no forced retries, and no writes to Sales records during scanning.

## Prerequisite evidence

P1A approved operator recovery `wp cb-crm-recovery` passed WordPress/MariaDB integration, local regressions, i18n and ZIP verification at CRM `f28f93a4e9fc3cbaf301d263a3b605bda81b530b` and Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`. P1A ZIP SHA-256 `1cda98d251e8deef9011712c8287be593543d22b9bda515f844a85f2b0483e3c`.

The two previous `admin_init` recovery reschedulers always scanned the first 20 intent options sorted by `option_id`. Inert, invalid, blocked, or long-lived records at the beginning therefore could prevent scheduling anything beyond the first 20, indefinitely.

## P1B candidate

A shared, internal `RecoveryQueueScanner::next_names('identity'|'user')` now performs a strictly bounded `wp_options` query using ascending `option_id` keyset pagination and exact escaped prefix selection:

- Per invocation and per queue, select at most 20 intent names. Cursor stored separately under non-autoloaded `cb_crm_recovery_cursor_identity` and `cb_crm_recovery_cursor_user` options.
- Persist the new cursor **before** scheduling any events; on failed read/cursor write, return no names, leaving the durable intents untouched.
- Advance even when an intent name/payload is invalid or a WordPress post/login remains, so one bad or inert entry cannot indefinitely starve later entries.
- When no newer matching option IDs exist, wrap to the oldest matching option. A new caller then cycles through the queue again.
- `LifecycleRecovery::reschedule_pending()` and `UserAnonymizationRecovery::reschedule_pending()` retain their existing `current_user_can('cb_manage_crm')` gate; the user queue also retains its schema and single-site guards.
- The scan only reads option names. Each owning repository still validates exact intent payload, numerical ID and kind before calling `wp_schedule_single_event`; no SQL repair, Contact deletion, Sales data change or intent deletion is performed by this scheduler.

This is a bounded **fairness improvement**, not a hard real-time SLA and not a guarantee that WordPress traffic/WP-Cron runs periodically. Two concurrent admin requests may still overlap the current cursor and reschedule duplicate events, but normal `wp_next_scheduled` guards and idempotent retry paths remain in place. Heavy concurrency requires a separate lock/fairness hardening if necessary.

## Tests

New standalone `tests/recovery-queue-fairness-smoke.php` checks 45 options in each queue, with the oldest malformed; verifies first 20, next 20, final 5 and wrap, and fails closed for query errors, unpersisted cursor, corrupt cursor and unknown queue. It is registered in `./tools/check`. Existing lifecycle recovery PHP mocks now support the cursor query while retaining all original cleanup/permission assertions.

New optional true WordPress/MariaDB integration:

    CB_CRM_TEST_QUEUE_FAIRNESS=1 ./tools/check-sales-integration

This provisions a fresh random `cb_crm_it_XXXXXXXX` database and isolated WordPress installation, not any earlier test database. A fixture creates 45 Contact deletion intents and 45 user anonymization intents, each with one malformed first record, using synthetic IDs which do not exist in the WordPress users/posts tables. It first asserts that an unauthenticated request cannot schedule either queue, then runs three operator-authorized `reschedule_pending()` requests and verifies that the last valid intent in each queue is eventually scheduled. It checks that the corrupt first entries remain in options but are not scheduled, and that both cursors wrap after reaching the end. All SQL/cron/option writes are limited to the isolated environment.

**Caveat:** The fixture exercises multiple scanner calls within an isolated WP-CLI request, not a real host where WP-Cron delivery was disabled. This suffices to test starvation prevention at the scheduling boundary, not server reliability or retention/erasure legislation.

## Local acceptance procedure

    cd ~/Downloads/wp-core-blueprint-crm
    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    git diff --check origin/main...HEAD
    php -l src/Repository/RecoveryQueueScanner.php
    php -l src/Repository/LifecycleRecovery.php
    php -l src/Repository/UserAnonymizationRecovery.php
    php -l tests/recovery-queue-fairness-smoke.php
    php -l tests/integration/recovery-queue-fairness.php
    bash -n tools/check-sales-integration
    ./tools/check
    ./tools/i18n/check

    CB_CRM_TEST_QUEUE_FAIRNESS=1 ./tools/check-sales-integration

After the real integration succeeds, build and verify:

    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

Runtime code changed, so the P1A ZIP SHA-256 is NOT an acceptance checksum for P1B. Record the actual new SHA-256. Do not merge, run Actions, deploy, publish or modify a live database without separate explicit GO.

## Following gates

After P1B local acceptance: reconcile CRM docs and Operator dashboard requirements, evaluate multisite deletion semantics separately, then develop a policy-reviewed P2 data-subject export/erasure design and P3 retention/hold contracts. No claim of complete GDPR compliance from recovery alone.

## Actual local Golden acceptance (2026-10-11)

Source CRM commit `1d0a2745d18f738b0b838a2e67e4865a12443d8c`; Base commit `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`. User executed PHP lint (scanner, both existing recovery classes, smoke/fixture), Bash syntax, `git diff --check`, complete `./tools/check`, and six-locale `./tools/i18n/check` successfully. The expected injected failure paths emitted nonfatal recovery diagnostic log lines; translation catalogs also emitted nonfatal missing `MIME-Version`/`Content-Transfer-Encoding` header warnings.

The real isolated WordPress/MariaDB command `CB_CRM_TEST_QUEUE_FAIRNESS=1 ./tools/check-sales-integration` PASS in disposable WordPress root `/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.G9mAjPNA`, DB `cb_crm_it_g9majpna` (retained for inspection). This includes the baseline six Sales InnoDB tables, schema 1.6, transaction rollback, unique-key constraint, Trash behavior and durable post-deletion recovery. Then 90 synthetic intents were seeded: 45 in each queue, one intentionally malformed first entry per queue. The real WordPress test proved that authorized bounded scanner requests eventually scheduled the last valid record, left the malformed first entries untouched, refused the unauthenticated scheduling attempt and safely wrapped each cursor. `./tools/build-release`, `unzip -t` and the checksum file check PASS. **Release ZIP SHA-256: `e7539338e321ca4d6896675c78acf0e96738e8c9e297fba87200cdb86df32579`.** Nonblocking sendmail warnings came from isolated WordPress provisioning.

**Accepted scope:** bounded, progressing per-queue rescheduling with persisted cursor in a local single-site MariaDB test environment. **Not accepted:** guaranteed WP-Cron execution, multisite, exhaustive concurrent scheduler fairness, automatic treatment of malformed intents, private-person export/erasure and policy-led retention. No merge, CI, staging/production or public release approval.
