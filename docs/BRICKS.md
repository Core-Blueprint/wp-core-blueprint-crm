# Core Blueprint CRM — Bricks adapter

The Bricks integration is an optional adapter. Core Blueprint CRM remains builder-agnostic and Bricks is never a dependency of the CRM domain model, authorization layer, storage layer, or public frontend contracts.

When Bricks is inactive, the adapter does not boot.

## Dynamic Data

All tags are grouped under **Core Blueprint CRM**. Core Blueprint groups are normalized into one contiguous block in the Bricks Dynamic Data picker.

### Contact

- `{cb_crm_contact_id}`
- `{cb_crm_contact_display_name}`
- `{cb_crm_contact_first_name}`
- `{cb_crm_contact_last_name}`
- `{cb_crm_contact_job_title}`
- `{cb_crm_contact_email}`
- `{cb_crm_contact_phone}`
- `{cb_crm_contact_linked_user_id}`
- `{cb_crm_contact_primary_organization}`
- `{cb_crm_contact_organizations}`
- `{cb_crm_contact_services}`
- `{cb_crm_contact_tags}`
- `{cb_crm_contact_status}`

Outside a CRM loop, Contact tags resolve against the uniquely linked Contact for the current WordPress user. Ambiguous or missing links fail closed.

### Organization

- `{cb_crm_organization_id}`
- `{cb_crm_organization_name}`
- `{cb_crm_organization_legal_name}`
- `{cb_crm_organization_contact_methods}`
- `{cb_crm_organization_address}`
- `{cb_crm_organization_services}`
- `{cb_crm_organization_tags}`
- `{cb_crm_organization_status}`

### Service

- `{cb_crm_service_id}`
- `{cb_crm_service_name}`
- `{cb_crm_service_pricing}`
- `{cb_crm_service_amount}`
- `{cb_crm_service_currency}`
- `{cb_crm_service_tax_mode}`
- `{cb_crm_service_tax_rate}`
- `{cb_crm_service_status}`

Organization and Service tags require a matching CRM loop/current post context. All values come from the public Phase-5 projections; the adapter does not read CRM repositories or tables directly.

## Query Loop types

- **CRM: Contact** — the current user's uniquely linked Contact; empty when unavailable or ambiguous.
- **CRM: Contacts** — staff-only bounded Contact query.
- **CRM: Organizations** — staff-only bounded Organization query.
- **CRM: Services** — staff receive the service catalog; non-staff authenticated users receive only Services exposed by the public CRM access contract.

The adapter clamps Bricks loop limits to 1–100 and delegates authorization to the public query providers.

## Conditions

Group: **Core Blueprint CRM**.

- Contact — current user has an unambiguous linked CRM Contact.
- Contact · Service — the current Contact has the selected Service.
- Contact · Organization — the current Contact belongs to the selected Organization.
- Organization · Service — the current Organization has the selected Service.
- Status — the current CRM record has the selected record status.

Conditions are display logic only. They never replace CRM authorization or action capability checks.

## Form Actions

Two staff-facing actions are available:

- **CRM: Edit Contact**
- **CRM: Edit Organization**

The Contact action can map a record ID plus display name, first name, last name, job title and status. The Organization action can map a record ID plus organization name, legal name and status.

The adapter passes only explicitly mapped fields to the canonical Phase-5 actions `crm.update_contact` and `crm.update_organization`. Unmapped fields are not sent and therefore cannot clear existing CRM data. The canonical actions still require `cb_manage_crm` plus the relevant object edit capability.

These actions intentionally do not expose customer self-service, relationship replacement, service assignment replacement, address replacement, contact-method replacement, WordPress-account linking, or unrestricted CRM mutation.
