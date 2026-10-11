# CRM Sales Wave 1A P0: durable single-site WordPress user anonymisation

**Status:** Candidate runtime patch, NOT YET locally tested or accepted.
**Date:** 2026-10-11.
**Scope:** CRM Sales v1.6 under a *single-site* WordPress 7.0/7.1 installation, using InnoDB. No new schema tables, outbound service, GUI, merge, CI, staging or deployment.

## Actual risk

The existing `LifecycleCleanup::for_deleted_user()` zeroes `owner_user_id` on Leads/Opportunities and `actor_user_id` on Sales Events in one InnoDB transaction. Previously `Lifecycle::deleted_user()` invoked that cleanup only after WordPress had already removed the user. If SQL failed, the transaction rolled back but the deleted WordPress account could not be restored; CRM wrote an audit failure and left no durable work item for replay.

WordPress calls `delete_user` before `wp_delete_user()` removes the user (but this hook does not fire on all network deletion paths), `wpmu_delete_user` before a network deletion, and `deleted_user` after either operation. **The `delete_user` hook is an action**, not a cancellable filter; the candidate deliberately uses `wp_die` to abort a single-site delete when it cannot establish a durable intent and retry schedule. This failure mode is intentional and must be user-acceptance-tested.

## Candidate implementation

- `src/Repository/UserAnonymizationRecovery.php`: tracks a minimal per-user ID intent `cb_crm_user_anonymize_intent_{id}` in a non-autoloaded WordPress option before a WordPress user is deleted. No name, email, role, IP, other PII or free text is copied into the intent.
- A single event `cb_crm_user_anonymize_reconcile` is scheduled for retry. A bounded, capability-gated `admin_init` scan re-schedules pending intents if the cron event was lost; it never writes Sales data itself.
- If intent persistence or retry scheduling fails, the pre-delete action exits with a 503 safety error on a single-site installation. It also blocks deletion if CRM 1.6 code is active but schema reconciliation is incomplete.
- `Lifecycle::deleted_user` invokes the recovery boundary after WordPress deletion and then performs its separate pre-existing linked-CRM-Contact unlink logic.
- Retry validates exact intent payload and directly queries `$wpdb->users` to prove the login no longer exists, without trusting cached `get_userdata()` or a caller-supplied identity. If the user still exists after an aborted deletion, no Sales writes occur and the intent is retained.
- Only after proof of user absence does it invoke the existing transactionally atomic `LifecycleCleanup::for_deleted_user()`. On SQL/COMMIT failure or failed acknowledgement, the intent remains for a safe replay. Duplicate callbacks after acknowledgement are idempotent. No other user IDs are targeted.
- `wpmu_delete_user` was wrongly used as an after-delete notification previously; it is now registered for pre-delete work, while `deleted_user` is the sole post-delete hook. **Multisite remains unsupported for this new durable pathway**: removing a user from a blog and deleting a global network login are different operations. This test candidate performs no new multisite anonymization, and is NOT a multisite readiness claim.

## Source and real database tests

Deterministic PHP smoke:

    php tests/sales-user-anonymization-recovery-smoke.php

This checks hook registration, failing intent writes, failed cron scheduling and deletion block, pending user protection, failed direct DB user lookup, failed cleanup, failed acknowledgement, successful recovery, repeated recovery, unrelated user safety, admin recovery rescheduling and single-site readiness guard.

The opt-in real WordPress + MariaDB fixture `tests/integration/sales-user-anonymization.php` runs in a new randomly named `cb_crm_it_XXXXXXXX` database. It creates a synthetic WordPress user plus a separate control user, and seeds two Sale Leads with different owner/actor IDs. It installs a disposable SQL trigger that forces the Sales Events UPDATE to fail during `wp_delete_user()`, proving (1) the login is gone, (2) an intent remains, (3) the Sales transaction rolled back, and (4) the control user is unaffected. After removing the trigger, it retries and confirms both deleted-user IDs zeroed, control user unchanged, canonical Contact retained, intent acknowledged and second retry no-op.

Execute **only on a developer workstation**:

    cd ~/Downloads/wp-core-blueprint-crm
    git fetch origin
    git switch feature/crm-sales-wave1-foundation-v1
    git pull --ff-only origin feature/crm-sales-wave1-foundation-v1
    git rev-parse HEAD
    git diff --check origin/main...HEAD
    php -l src/Repository/UserAnonymizationRecovery.php
    php -l src/Lifecycle.php
    php -l tests/sales-user-anonymization-recovery-smoke.php
    php -l tests/integration/sales-user-anonymization.php
    bash -n tools/check-sales-integration
    ./tools/check
    ./tools/i18n/check

    CB_CRM_TEST_USER_RECOVERY=1 ./tools/check-sales-integration

The runner **creates an entirely new** disposable database and WordPress test installation. Existing Base/Work/CRM test databases, site data and `dist/` are not selected or modified by the integration test. It retains its own test root on failure for diagnosis; do not run it again to overwrite/reuse a failing DB.

After acceptance, create and verify a **new** release artifact:

    ./tools/build-release
    unzip -t dist/core-blueprint-crm.zip
    ( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
    sha256sum dist/core-blueprint-crm.zip

**Checksum must differ from the previously validated `53c0c06b0b404ba2067865156158ccb9613de4106ddd9e4d4f38f249234a55c2`** because `src/Lifecycle.php` and the new runtime class are shipped in the plugin ZIP. Never reuse the prior checksum as evidence.

## Residual risks (not covered by P0)

- A plugin disabled or a host that bypasses both pre/post WordPress deletion hooks cannot provide an intent guarantee. Recovery also requires a working WordPress options table.
- A single-site deletion error is deliberately blocking for privacy protection. Confirm operator error UX and user-facing copy before live use.
- WP-Cron delivery depends on the host and website traffic. The current bounded first-20-intents admin rescheduler can starve later pending items if the first 20 never finish. P1 requires paginated operator visibility, explicit retrial and removal of starvation.
- Network-wide WordPress user deletion and per-site roles require a separate multisite policy. The current P0 path is intentionally single-site only.
- This anonymizes **WordPress login references**, not CRM Contact personal data and not a GDPR data-subject erasure response. Title, monetary estimate, provenance and other potentially identifying Sales fields may remain. Do not claim full privacy export/erasure/retention until P2/P3.
- An operator/legal decision is still required for an explicit retention schedule and shared multi-party Sales records. No automatic destructive purge or outbound system is introduced.
