# CRM Sales Wave 1A: real WordPress/MariaDB integration

**Status:** Fresh-install integration PASS locally on 2026-10-11 (CRM runtime 248f131, Base 8544d10); upgrade 1.5 to 1.6 remains a separate unexecuted test.
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

## Troubleshooting and resume without new database

WP-CLI `eval-file` evaluates source text rather than requiring a PHP module; source modules with `declare(strict_types=1)` fail there before any assertions execute. The runner uses `wp eval` with an ordinary PHP `require` instead. If an earlier run reached the eval-file fatal, reuse its isolated WordPress install rather than running the provisioning script again:

    cd ~/Downloads/wp-core-blueprint-crm
    CB_CRM_INTEGRATION_TEST_FILE="$PWD/tests/integration/sales-mariadb.php" \\
      wp eval 'require getenv("CB_CRM_INTEGRATION_TEST_FILE");' \\
      --path=/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.jUx4Zaml/wordpress

The path above is the first run's documented test-only path. It must never be replaced by staging, production, or a non-isolated WordPress location. Requiring the file retains PHP strict typing while invoking actual WP-CLI/WordPress APIs.

## Privileged Access Guard in fresh WordPress installations

Base may initially mark a newly installed WordPress administrator as Needs Review with reason `guard_marker_recovered`. This is correct Base fail-closed behavior. CRM's versioned policy can have all three stored capabilities (`cb_manage_crm`, `cb_view_crm_sales`, `cb_manage_crm_sales`) while effective permission remains denied because the administrator has not received Base's signed trust approval.

The isolated runner now invokes **the official Base WP-CLI recovery** after activating Base and CRM, exclusively against the **new per-run disposable installation**:

    wp cb operator recover crm-it-admin --path="$wp_root"
    wp cb operator status crm-it-admin --path="$wp_root"

This is not a security bypass. The command adds the additional CB Operator role and creates a valid signed approval, retaining the user's Administrator base role. It must not be used automatically against staging or production.

The first test installation already exists. Resume it **without reprovisioning** by running:

    WP_TEST=/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.jUx4Zaml/wordpress
    wp cb operator recover crm-it-admin --path="$WP_TEST"
    wp cb operator status crm-it-admin --path="$WP_TEST"
    CB_CRM_INTEGRATION_TEST_FILE="$PWD/tests/integration/sales-mariadb.php" \\
      wp eval 'require getenv("CB_CRM_INTEGRATION_TEST_FILE");' --path="$WP_TEST"

Do not weaken the Base Privileged Access Guard, add ad-hoc roles, or grant caps directly just to satisfy this test.

## Actual local acceptance evidence (2026-10-11)

User executed the real WordPress/MariaDB fresh-install integration against CRM runtime snapshot `248f1318afc2b30069b4f5b56066a112cfc452f4` and Base `8544d1097d98c3a241a6c46b8c6fd0dcc27372e4`. The separate disposable test database was `cb_crm_it_jux4zaml`. Base's official CLI Operator recovery established a signed privilege approval. All assertions in `tests/integration/sales-mariadb.php` passed: InnoDB schema, seed retry, rollback, unique indexes, WordPress Trash, a real injected SQL error, durable recovery and duplicate retry.

This is **fresh-install integration PASS**, not evidence of an existing 1.5 installation upgrade. That is tested separately in `tools/check-sales-schema-upgrade`. No production or staging environment was used. The newer integration-runner-only commits do not change CRM runtime behavior.
