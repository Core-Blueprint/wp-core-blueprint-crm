# CRM Sales Wave 2B2-A1: Quick Capture

Status: Implementation candidate, not technically Golden and not authorized for production.
Source parent: `36001d5d7f2050dc0d782813a3aff9e15bba6f06`
Branch: `feature/crm-sales-wave2b2a-quick-capture-v1`

## User intent

A staff user may capture a Lead or Opportunity immediately using a title, without first creating or selecting a Contact or Organization. An active pipeline and stage must still be valid; the initial available stage is selected on the form. Opportunity estimate remains optional. New Sales records retain canonical owner, revision 1 and a creation audit event.

The optional identity search and selection remain available. A malformed submitted identity still fails closed. This change never invents a Contact or Organization.

## A1 scope and boundaries

- `SalesCreateActions::render_for_type()` renders the create form without a prior identity search; a searched identity is optional.
- `SalesCreateActions::process()` accepts an explicit empty party, but otherwise keeps the allowlist, nonce, manager permission, input types, stage checks and C2 staff facade.
- `Rules::valid_participants([], true)` is an explicit creation-only opt-in. The no-argument/default validation remains strict, including Lead conversion.
- `Records::create_lead()` and `create_opportunity()` permit no participants while retaining InnoDB transaction, role/capability, stage, canonical ownership and audit.
- This patch does not change schema 1.6, existing data, permissions, REST API or front-end exposure.

## Mandatory follow-ups before any production acceptance

**A2:** Governed attach/change of Contact or Organization to an existing Sales record, revision-aware, nonce- and capability-gated, with durable audit. Include user-visible `Not linked` state.

**A3:** Ensure deleting the last Contact/Organization link cannot delete the independent Sales record or immutable audit. Cover previously linked records and unlinked Lead conversion with real MariaDB tests. Preserve valid identity locks and fail-closed handling when a linked Contact/Organization becomes invalid.

**Hardening:** Prevent accidental double creation from repeated POST requests; validate display-currency UI before production acceptance.

## Testing and i18n

The two new gettext strings are reviewed in `tools/i18n/sales-w2b2a-translations.json`. The canonical, generated POT/PO/MO artifacts must be refreshed with:

```sh
python3 tools/i18n/seed-sales-w2a.py
./tools/i18n/update
./tools/i18n/check
```

These generated artifacts are not yet included in this candidate commit. **Do not promote A1 or publish its ZIP while the tracked catalogs are out of sync.**

Run focused domain, persistence, create transport and conversion tests before full regressions. Then run `./tools/check`, `./tools/check-sales-integration`, deterministic release build, ZIP integrity and checksum. The WordPress/MariaDB runner reads committed sources via Git archive; do not confuse the local working tree with the tested commit. Browser acceptance remains required.

No merge, CI dispatch, WordPress deployment or main branch changes are authorized.
