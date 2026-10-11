# CRM Sales Wave 1A P1A: operator recovery oversight

**Date:** 2026-10-11
**Status:** P1A operator oversight Golden PASS locally on 2026-10-11; P1B automatic scheduler fairness still open.
**Prerequisite:** P0 durable single-site deleted-user anonymization Golden PASS at CRM `ebc1041aae1624d77470c08c2d89bc523a456531`, Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`, real disposable MariaDB DB `cb_crm_it_0l1kujdc`. Previous P0 release SHA-256 `feccc71fa3e40847e50e14bd8c426afb4e84a0d683a15a8fec57d09c95247c91`.

## Scope and trust boundary

This increment adds a **separate** WP-CLI operator command, not part of Base's own `wp cb operator` hierarchy:

    wp --user=crm-it-admin cb-crm-recovery status --after=0 --limit=20
    wp --user=crm-it-admin cb-crm-recovery retry --kind=user --id=123 --confirm=1

Use a real approved CRM operator's WP user login, not the example `crm-it-admin` outside a disposable test. Both commands require `cb_crm_runtime_ready()`, a signed/approved active user and `current_user_can('cb_manage_crm')`. WordPress CLI global `--user` does not itself bypass authorization. No anonymous or unapproved status listings, no bulk deletion, no force mode, no automated mutation by `status`. The retry command is deliberately limited to one existing, structurally valid, **ready** durable intent with exact kind+ID and explicit `--confirm=1`. WordPress login present, wrong type, invalid intent, missing intent, Multisite-user intent or database lookup failure is refused.

## What operators can inspect

`RecoveryOversight::page($after, $limit)` uses a bounded `wp_options` keyset scan ordered by `option_id` and filters the two existing durable-intent prefixes. Page size 1..100, default 20. Output contains only `kind`, numeric `id`, `state`, `scheduled=yes/no` and `option_id` plus `NEXT_CURSOR` and `HAS_MORE`.

State meanings:

| State | Meaning | Explicit retry |
| --- | --- | --- |
| `ready` | Valid durable intent; canonical WordPress identity absent in a direct SQL read | Permitted for one ID after privilege and confirmation |
| `identity_exists` | WordPress login/post still exists, including aborted deletion | Refused, preserves intent |
| `invalid` | Malformed or mismatched intent; not discarded | Refused; requires forensic review |
| `lookup_error` | The direct database identity check failed | Refused |
| `missing` | Targeted option no longer exists | Refused as an explicit retry; normal duplicate cron callbacks remain idempotent |

Do not infer a person, account name, email address, Contact details or legal erasure status from numeric IDs. A valid CRM deletion intent can be Contact or Organization; a user intent belongs only to single-site Sales owner/actor minimization.

## Checks

New deterministic smoke `tests/recovery-oversight-smoke.php`, wired into `./tools/check`, covers: 27 seeded recovery options; keyset pagination beyond the first 20; bad payload still visible; already-existing identity rejected; failed SQL inventory rejected; unapproved and anonymous operator refused; missing confirmation refused; cross-kind mismatch refused; invalid ID refused; single-ID dispatch; Multisite user refusal.

Opt-in actual WordPress+MariaDB test in a **new, random, disposable** `cb_crm_it_XXXXXXXX` database:

    CB_CRM_TEST_OVERSIGHT=1 ./tools/check-sales-integration

The integration fixture seeds 25 synthetic absent-user recovery options and one deliberately malformed post intent. The runner runs the CLI as a signed, approved disposable test operator, inspects two pages, validates the malformed entry remains visible, rejects anonymous access and missing confirmation, then executes exactly one confirmed user retry and checks that it removes only the requested intent. No existing test DB or production/staging database is reused. The fixture does not create real users or operate on third-party identities.

## Local acceptance

    cd ~/Downloads/wp-core-blueprint-crm
    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    git diff --check origin/main...HEAD
    php -l src/Repository/RecoveryOversight.php
    php -l src/CLI/RecoveryCommand.php
    php -l src/Plugin.php
    php -l tests/recovery-oversight-smoke.php
    php -l tests/integration/recovery-oversight.php
    bash -n tools/check-sales-integration
    ./tools/check
    ./tools/i18n/check

    CB_CRM_TEST_OVERSIGHT=1 ./tools/check-sales-integration

Once the test passes, produce a new runtime ZIP:

    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

`src/Plugin.php`, `src/CLI/RecoveryCommand.php` and `src/Repository/RecoveryOversight.php` change the shipped runtime; the P0 checksum `feccc71f...` is **not valid** for this candidate.

## Outstanding P1B

The **automatic** `LifecycleRecovery::reschedule_pending()` and `UserAnonymizationRecovery::reschedule_pending()` functions still read only the first 20 options on each eligible `admin_init`. Persistent, inert or malformed first-page intents can starve later entries. This has intentionally not been rewritten before P1A acceptance. P1B must implement a bounded *progressing* scanner and prove fairness for both queues with real options and multiple requests. WP-Cron delivery/host outage and optional operator observability UX remain separate concerns.

P2 verified data-subject access/export and P3 lawful retention/erasure remain governance decisions. Do not merge, run GitHub Actions, deploy, publish or process real Sales personal data without explicit approval.

## Actual local P1A acceptance (2026-10-11)

User validated CRM source `f28f93a4e9fc3cbaf301d263a3b605bda81b530b` with Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`. PHP lint, complete `./tools/check`, `./tools/i18n/check`, and optional isolated WordPress/MariaDB operator integration PASS. Disposable DB `cb_crm_it_wlsfksrp` under `/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.wlSFKSRp`. 25 synthetic user intents plus one malformed post intent were enumerated in two pages (cursor 175 then 181); bad payload remained visible; unauthenticated call and unconfirmed retry were refused; one approved `user #700000024` retry succeeded without deleting any other intent. Six-locale PO header and absent-sendmail warnings are nonblocking.

`./tools/build-release`, ZIP integrity test and checksum-file verification all PASS. Canonical release ZIP SHA-256: **`1cda98d251e8deef9011712c8287be593543d22b9bda515f844a85f2b0483e3c`**. This certifies P1A only. The legacy per-request `admin_init` first-20 scan remains an open fairness/starvation issue to be handled separately as P1B. No merge/CI/staging/production GO.

## P1B supersession (2026-10-11)

The earlier “Outstanding P1B” subsection above is historical. P1B `RecoveryQueueScanner` is now locally GOLDEN PASS at `1d0a2745d18f738b0b838a2e67e4865a12443d8c` with real WordPress/MariaDB 45-intent-per-queue fairness, bounded wrap and non-starvation. See `SALES-WAVE1A-RECOVERY-FAIRNESS.md`. This does not certify host WP-Cron delivery or multisite.
