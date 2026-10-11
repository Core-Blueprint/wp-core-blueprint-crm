# CRM Sales Wave 1A: canonical identity row locking

**Status:** Deletion-first two-process WordPress/MariaDB integration PASS locally (2026-10-11); creation-first concurrency and full post-patch regression remain open.

## Evidence and risk

As of October 11, 2026 the independent fresh CRM 1.6 integration, actual CRM 1.5 -> 1.6 upgrade, Base migration-lock interruption and retry all passed locally using WordPress 7.0, Base at `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4` and MariaDB 10.11.19. The schema-lock test used disposable `cb_crm_up_fjxuxef1`. The 1.6 release ZIP prior to this patch was verified at SHA-256 `8c3317925167387351d8c7af665768d3312aaf8693bc433650c41137261593d5`.

A separate concurrency risk remained: before this patch `Records::create_lead()` and `Records::create_opportunity()` checked a participant via WordPress `get_post_status()` and `Entity::valid_owner()`, both able to use cached post state, inside a Sales transaction that locked pipeline and stage but not the canonical WordPress identity. A concurrent `wp_delete_post()` could complete cleanup after the stale read and before the new Sales record commits, leaving an orphan. The earlier sequential tests did not exercise this interleaving.

## Candidate mitigation

Before inserting any Sales record or participant, `Repository/Sales/Records::create()` now checks every validated participant through:

    SELECT post_type, post_status FROM wp_posts WHERE ID = ? LIMIT 1 FOR UPDATE

The actual query uses `$wpdb->posts` and prepared parameters. The read, Sales record insert, participant insert, event insert and commit all run inside one InnoDB transaction. Participants are locked in ascending post ID order to reduce lock-order inversions; their original input order is not rewritten. Wrong WordPress type, Trash, auto-draft and nonexistent posts fail with `crm_sales_invalid_identity`; database query failure fails with `crm_sales_storage_failed`. The code must never trust WordPress's previously cached status here.

With InnoDB's locking read, deletion-first means the row cannot be selected and creation aborts; creation-first means the WordPress row lock survives until Sales commit, and the competing `wp_delete_post()` must wait before its final post deletion/relational cleanup. This relies on the canonical WordPress `wp_posts` table being InnoDB. Do not claim this same guarantee on MyISAM or another engine without a matching atomic identity lock contract.

## Tests

The isolated `tests/sales-records-persistence-smoke.php` now simulates a stale WordPress cache that still knows a deleted Contact while the authoritative SQL table no longer contains it. Creation must be rejected and roll back. The smoke also requires `FOR UPDATE` locking reads, stable identity lock ordering for multi-party deals and a failed identity SQL read to remain fail closed.

A new optional actual *two-process* test is part of the fresh-install runner:

    CB_CRM_TEST_CONCURRENT=1 ./tools/check-sales-integration

It creates an entirely new `cb_crm_it_XXXXXXXX` database and temporary WordPress install; it DOES NOT reuse the previously passing database `cb_crm_it_jux4zaml`. After the standard integration checks, process A warms its WordPress object cache, then pauses immediately before issuing the locking `SELECT` (inside `Records::create_lead`). Process B permanently deletes the Contact via real WordPress hooks, after which process A resumes. The test must observe a rejected Sales write and zero orphan participants. Strict timeouts and a trap release the child if the test fails. Test-only signals and logs are kept in the isolated root. This demonstrates deletion-first behavior; it does NOT exhaustively validate the opposite creation-first interleaving or all simultaneous deletes and participants.

## Local acceptance instructions

    cd ~/Downloads/wp-core-blueprint-crm
    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    git diff --check origin/main...HEAD
    php -l src/Repository/Sales/Records.php
    php -l tests/sales-records-persistence-smoke.php
    php -l tests/integration/sales-concurrent-deletion.php
    bash -n tools/check-sales-integration
    php tests/sales-records-persistence-smoke.php
    ./tools/check
    ./tools/i18n/check

    CB_CRM_TEST_CONCURRENT=1 ./tools/check-sales-integration

Only on successful real integration:

    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

Unlike the previous docs/test-only commits, this patch changes runtime PHP under `src/`, so its release artifact SHA-256 MUST differ from the previously validated `8c331792...` checksum. Record the actual new checksum, never reuse the old value.

## Out of scope and residual risks

- The test does not crash MariaDB in the middle of a write or test creation-first row-lock sequencing.
- WordPress on non-InnoDB `wp_posts` requires a separate supported-engine policy.
- `LifecycleRecovery` currently retains inert intents when a delete was vetoed, and WP-Cron reliability/operator queues require review.
- WordPress user deletion Sales anonymization is transactional, but recovery for failures and privacy-export/erasure/retention policy remain open.
- No UI, no new external service, no CI, no merge, no staging or production deployment is part of this increment.

## Real deletion-first race evidence (2026-10-11)

At CRM `e1c7306abbd8e0c2bf1857f1e74bca98ffb3cbf4` and Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`, the user executed `CB_CRM_TEST_CONCURRENT=1 ./tools/check-sales-integration` against isolated MariaDB 10.11.19 database `cb_crm_it_yzhajylk` and a disposable WordPress 7.0 site. The fresh-install InnoDB, seed retry, rollback, unique-key, Trash and post-delete cleanup recovery cases passed. In the two-process race, WordPress removed the Contact before Sales issued its canonical locking read; the Sales transaction rejected the now-missing identity despite cached WordPress state; verification found no orphan relation. **Result: PASS for deletion-first ordering only.**

The user then built and verified `dist/core-blueprint-crm.zip`, canonical root `core-blueprint-crm/`, ZIP integrity PASS, checksum file PASS, SHA-256 **`53c0c06b0b404ba2067865156158ccb9613de4106ddd9e4d4f38f249234a55c2`**. The preceding runtime commit added canonical identity locking and appropriately changed the release checksum.

Only the integration command and build were included in the latest supplied output. Run `php tests/sales-records-persistence-smoke.php`, `./tools/check` and `./tools/i18n/check` after the runtime change before treating the patch as fully accepted. Creation-first ordering, true concurrent multi-party writes, and privacy/retention remain open. The isolated DB stays intact, with no staging/production action or merge.
