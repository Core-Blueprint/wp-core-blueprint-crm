# CRM Sales Wave 1A: real schema upgrade 1.5 to 1.6

**State: source candidate, local WordPress/MariaDB run not yet executed.**
This is the next increment after the separate fresh-install real MariaDB test passed in the user's isolated WordPress test database (October 11, 2026).

## Objective

Prove that Base SchemaRegistry migrates an existing CRM 1.5 installation to CRM 1.6 without destroying, overwriting, or changing nine legacy CRM relational tables or linked WordPress post identities. Prove that the CRM Install version 1 to 2 role migration runs once, the Base Privileged Access Guard keeps a valid signed approval, and exactly six new InnoDB Sales tables plus two pipelines and seven stages are installed.

## Non-destructive isolation boundary

- The runner: tools/check-sales-schema-upgrade.
- Fresh *random* directory under /tmp/core-blueprint-tests/core-blueprint-crm-sales-upgrade/run.XXXXXXXX/.
- Fresh *random* MariaDB database prefixed cb_crm_up_ and database user prefixed cb_up_.
- Canonical existing container: cb-base-test-db, mariadb:10.11.19, 127.0.0.1:3307.
- Base source pinned to the local checked-out HEAD, staged with git archive.
- CRM schema 1.5 source pinned to Git commit 42e6915263694210698511735e9b9cd80dd01a40; no rewrites of main.
- CRM 1.6 target sourced from the checked-out feature HEAD using git archive.
- The two snapshots are exchanged ONLY within the freshly created WordPress test root. The original schema 1.5 plugin tree is retained under the run root for debugging.
- No existing database is dropped, reset or reused. Neither existing cb_crm_it_* test databases nor Base/Work/Backups databases are selected.
- The disposable DB and directory are retained on both success and failure. Cleanup is NOT automatic, requires separate inspection.
- No secrets are written to the Git repository or printed, except that the test WordPress configuration (mode-protected under the isolated root) necessarily contains temporary test DB credentials.

## Two-phase test

Phase seed (actual old 1.5 plugin active):
1. Assert schema 1.5 and role policy v1, and that CRM runtime is ready.
2. Use synthetic-only Contact, Organization and document WordPress posts.
3. Insert exactly one test row into each of nine v1.5 relational tables: methods, addresses, names, business identifiers, organization relationships, service agreements, document links, notes, and activities.
4. Store a versioned snapshot of all nine full-table row hashes in an isolated WordPress option.
5. Ensure the Base guard's official CLI Operator recovery has approved the test administrator.

Phase verify (v1.6 feature plugin active, real Base schema sweep):
1. Assert Base committed schema version 1.6.
2. Compare nine table hashes exactly and assert all pre-existing WordPress entities retain type and status.
3. Assert CRM's role policy is version 2, all three CRM/Sales caps are stored/effective and the official Base signed approval is still valid.
4. Inspect real InnoDB engines for six Sales tables.
5. Assert the Sales defaults marker and exactly two pipelines and seven stages.
6. Repeat the same verification in a second WordPress request to catch unintended repeat migrations.

## Validation instructions

From the CRM repository:

    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    bash -n tools/check-sales-schema-upgrade
    php -l tests/integration/sales-upgrade-15-to-16.php
    ./tools/check
    ./tools/i18n/check

Then, on the development machine only:

    ./tools/check-sales-schema-upgrade

The script will create a new, disposable WordPress and MariaDB database. The previously passing fresh-install test database must not be touched or reused.

After acceptance:

    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

As this increment changes only docs/tools/tests, the release ZIP should remain byte-for-byte identical to its previously validated SHA-256: 8c3317925167387351d8c7af665768d3312aaf8693bc433650c41137261593d5. This is an expectation to confirm locally, not a newly executed build.

## Acceptance limits and follow-ups

This verifies a complete 1.5 to 1.6 upgrade in one isolated fresh environment, not a broad migration compatibility matrix. A future incremental test must still cover interrupted migrations, concurrency/race handling, user-deletion cleanup, privacy retention/export/erasure, and production cron reliability. Do not infer live-site readiness, request GitHub Actions, merge, deploy, or publish from a green test alone.
