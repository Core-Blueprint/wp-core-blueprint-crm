# CRM Sales Wave 1A: real WordPress/MariaDB integration

**Status:** Authored, NOT YET EXECUTED or accepted locally.
**Scope:** Fresh WordPress 7.0/7.1 and real MariaDB 10.11.19, not the 1.5 to 1.6 upgrade yet.

## Isolation and safety

The runner in tools/check-sales-integration requires PHP 8.4+, Docker, WP-CLI and Git. It verifies the already-running cb-base-test-db container uses mariadb:10.11.19 and is bound only to 127.0.0.1:3307.

It creates a fresh random directory under /tmp/core-blueprint-tests/core-blueprint-crm-sales/ and a random cb_crm_it_* database plus a restricted test DB user. It never drops any existing database or deletes any directory and never starts/replaces the Docker container. It stages Base and CRM from the current Git commits with git archive, leaving both local repositories and dist/ unchanged. On failure, it leaves the isolated test data for investigation.

The script uses the existing container bootstrap root password from its local configuration, or the explicit CB_CRM_TEST_DB_ROOT_PASSWORD override, without printing it. The new test DB account credentials remain in the protected temporary wp-config.php. Never share that file or shell debug logs. WordPress core may be downloaded from wordpress.org. Automatic WP-Cron is disabled in this test environment so manual reconciliation can be tested deterministically.

## Commands

Run from the CRM checkout:

    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    bash -n tools/check-sales-integration
    php -l tests/integration/sales-mariadb.php
    ./tools/check
    ./tools/i18n/check
    ./tools/check-sales-integration

The runner is deliberately separate from tools/check because it provisions a real disposable database.

## What this proves

- Six Sales tables are InnoDB and Base SchemaRegistry reports CRM schema 1.6.
- Default pipeline creation and a retry preserve customized stage names.
- A real transaction rollback removes a staged insert.
- MariaDB unique-key constraints reject duplicate pipeline keys.
- Actual CRM Lead creation and WordPress Trash/untrash preserve Sales participants.
- A temporary MariaDB trigger forces Sales event deletion to fail after a permanent WordPress Contact deletion. The relational cleanup must roll back, leave a durable recovery intent, then complete on retry.
- Repeated retry must be idempotent.

No production or existing test data is used. The only destructive actions target freshly generated test fixtures in the uniquely named database.

## Open after this increment

Real 1.5-to-1.6 upgrade, concurrent inserts and transaction races, interrupted SchemaRegistry migration, multi-party deletion, scheduler reliability and the Sales privacy retention/export/erasure contract remain separately gated.

## Release validation

    ./tools/check
    ./tools/i18n/check
    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

Tests, tools and docs are excluded from the release ZIP. No merge, manual CI, staging or production installation is authorized.
