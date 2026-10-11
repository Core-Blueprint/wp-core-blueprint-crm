# Core Blueprint CRM

Core Blueprint CRM owns customer identity and relationship context: Contacts, Organizations and customer-specific Service Agreements.

Core Blueprint Work is the authority for the Service catalog, default pricing and VAT/tax data. CRM references Work through public contracts only and never provides a fallback Service or VAT catalog.

The plugin remains builder-neutral. Optional builder adapters, including Bricks, sit on top of the public frontend contracts.

## Proposed Sales Foundation (design only)

The proposed, **not yet implemented or approved for runtime development**, Sales Foundation v1 is documented in:

- [Sales Foundation v1 architecture RFC](docs/SALES-FOUNDATION-V1-RFC.md): Lead and Opportunity ownership, pipelines, canonical identity relationships, security and Work boundaries.
- [Sales Foundation v1 implementation and acceptance plan](docs/SALES-FOUNDATION-V1-IMPLEMENTATION-PLAN.md): staged delivery, regressions and rollout gates.

The existing CRM contact, organisation and agreement behaviour is unchanged by this documentation branch. The project does not currently claim Leads/Opportunities functionality.
