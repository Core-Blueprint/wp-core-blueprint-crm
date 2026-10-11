# CRM Sales Wave 2B2-A: Create Lead / Opportunity

- Date: 2026-10-11
- Status: Implementation candidate. Not technically Golden, browser-accepted or production-accepted.
- Source: local W2B1 Golden `d0889cb579dca2377146cc368daaf9f3db179e0c`
- Target branch: `feature/crm-sales-wave2b2a-create-v1`
Schema: 1.6, unchanged.

## Scope

Native CRM Leads/Opportunities admin pages gain a manager-only Create button. Manager searches a Contact or Organization by name (up to 20 results), selects exactly one participant with an explicit role, and chooses an active pipeline stage from a bounded manager-only list (up to 200 stages). Opportunities support an optional explicit ISO three-letter currency and non-negative amount in minor units. Records start Open with revision 1 and current staff actor as owner.

No new SQL write path: POST requests delegate to `StaffActions::create_lead` / `create_opportunity`; the existing `Records` repository owns InnoDB locking of the selected identity/pipeline/stage, transaction rollback and `created` audit event. Pipeline is resolved server-side from the selected stage. No direct SQL in Admin classes.

## Security

- Authenticated `admin_post_cb_crm_sales_create` only, no nopriv registration.
- Requires positive WordPress actor and canonical CRM+Sales manager permissions.
- Strict scalar POST allowlist for each record type; no fallback or untrusted redirect.
- `wp_verify_nonce` bound to explicit create intent and Lead/Opportunity type.
- Positive canonical IDs, allowlisted role/type, bounded title and amount.
- Stage is checked against currently active choices and rechecked transactionally by C2. Contacts and Organizations are checked transactionally against canonical `wp_posts` post type/status.
- Success PRG: WordPress Admin same-origin 303 to created record; generic failures only.
- Bounded relationship search requires manager authorization and escapes SQL LIKE and rendered names.

## Known scope/acceptance limitations

1. Exactly one canonical Contact or Organization may be linked on creation. Multiple participants and relationship editing are future work; do not claim them implemented here.
2. The create nonce is not single-use and is not an idempotency key. A replay or double submission can create a duplicate. **Promotion beyond development candidate requires an explicit duplicate-submission/idempotency resolution or approved risk gate.**
3. Bounded selector caps fail closed on >200 active stages; identity search shows at most 20 matches and requires refinement. Index/performance at enterprise volumes requires later profiling.
4. The UI uses amount in minor units to preserve the exact existing domain contract; a monetary UX conversion layer is deferred to W2C.
5. WordPress browser, Base UI consistency, 6-locale translation review, accessibility, responsive behavior, and P2/P3 release governance are separate acceptance gates.

## Required local validation

```bash
cd ~/Downloads/wp-core-blueprint-crm
php -l src/Admin/SalesCreateActions.php
php -l src/Repository/Sales/CreationOptions.php
php -l tests/sales-admin-w2b2a-smoke.php
php -l tests/integration/sales-admin-w2b2a.php
python3 -m py_compile tools/i18n/seed-sales-w2a.py
bash -n tools/check
bash -n tools/check-sales-integration
php tests/sales-admin-w2b2a-smoke.php
php tests/sales-creation-options-smoke.php

# Seed the draft translation dataset (language review required) and regenerate 6 locales with canonical tooling
python3 tools/i18n/seed-sales-w2a.py
./tools/i18n/update
./tools/i18n/check

# Full regressions incl. existing W2B1 + new W2B2-A and safe disposable WordPress/MariaDB
./tools/check
./tools/check-sales-integration

git diff --check
git status --short
./tools/build-release
unzip -t dist/core-blueprint-crm.zip
( cd dist && sha256sum -c core-blueprint-crm.zip.sha256 )
sha256sum dist/core-blueprint-crm.zip
```

The i18n update will modify 13 tracked catalog artifacts (POT + 6 PO + 6 MO); review before staging. `dist/` and `tools/i18n/__pycache__/` are existing untracked local outputs. Do not stage them.

## Browser acceptance

Check manager versus read-only staff visibility, native Base theming, Contact/Organization search, escaping, active stage grouping, create Lead/Opportunity, minor currency amount, valid C1 read-after-write and a `created` activity event. Test keyboard labels, focus, 782px responsive layout, invalid nonce, unauthorized submission, stale/deleted identities, and wrong stage pairing. Test double click or replay and record the duplicate risk before promotion.

Do not merge, push, publish, deploy, or trigger Actions without a separate explicit GO.
