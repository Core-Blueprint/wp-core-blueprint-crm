# Core Blueprint CRM — Bricks adapter

The Bricks integration is an optional thin adapter. CRM remains builder-neutral; Bricks never owns CRM storage, authorization, Service Agreement logic, or pricing resolution.

## Dynamic Data

Contact tags include identity, contact information, organizations, status, CRM tags and `{cb_crm_contact_service_agreements}`. Organization tags include organization data, contact methods, address, status, CRM tags and `{cb_crm_organization_service_agreements}`.

The agreement tags render the names of Work Services connected through readable CRM Service Agreements. CRM does not expose a duplicate Service catalog or CRM-owned Service pricing tags.

## Query Loop types

- **CRM: Contact** — current user's uniquely linked Contact.
- **CRM: Contacts** — staff-only Contact query.
- **CRM: Organizations** — staff-only Organization query.
- **CRM: Service Agreements** — readable Service Agreement projections for the current CRM Contact or Organization.

The Service Agreement query delegates to the builder-neutral `CB\CRM\Frontend\Queries\ServiceAgreements` contract.

## Conditions

- Contact — current user has an unambiguous linked CRM Contact.
- Contact · Service Agreement — current Contact has an active agreement for the selected Work Service.
- Contact · Organization — current Contact belongs to the selected Organization.
- Organization · Service Agreement — current Organization has an active agreement for the selected Work Service.
- Status — current CRM Contact or Organization has the selected CRM record status.

Work Service choices are obtained through `CB\Work\PublicApi\Services`. If Work is unavailable, Service Agreement choices fail empty; CRM never falls back to a local Service catalog.

## Form Actions

The existing staff-facing **CRM: Edit Contact** and **CRM: Edit Organization** actions remain thin adapters over CRM application actions. They do not expose unrestricted relationship or Service Agreement replacement.
