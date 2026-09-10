# Core Blueprint CRM integration API

## Ownership

CRM owns Contacts, Organizations, customer identity/context and customer-specific Service Agreements. Core Blueprint Work owns Services, default pricing and VAT/tax data.

There is no CRM Service CPT, CRM ServicePricing authority, CRM VAT repository/table or compatibility facade for the removed catalog architecture.

## Builder-neutral read contracts

- `CB\CRM\Frontend\Data\Contact`
- `CB\CRM\Frontend\Data\Organization`
- `CB\CRM\Frontend\Queries\Contacts`
- `CB\CRM\Frontend\Queries\Organizations`
- `CB\CRM\Frontend\Queries\ServiceAgreements`
- the existing optional Docs/Helpdesk integration providers

`Contact` and `Organization` projections expose `service_agreements`. Agreement items reference Work Service IDs and names. Sensitive pricing/notes remain staff-only.

`Organization` also exposes a canonical `business_identifiers` map for reusable legal/customer identifiers such as VAT, company-registration or EORI references. CRM owns the identifier values; downstream document/payment extensions must copy the values they need into their own immutable snapshots rather than live-reading CRM for historical documents.

Business identifier keys are canonical lowercase tokens and values are bounded strings. `CB\CRM\Application\Actions\UpdateOrganization` accepts `business_identifiers` as either an associative map or the organization-admin repeatable row shape (`key` + `value`). CRM does not assign tax meaning or calculation behavior to these identifiers.

## Service Agreement storage

CRM stores agreements in `cb_crm_service_agreements` with customer type/id, `work_service_id`, status, validity window, pricing mode, optional custom amount/currency/VAT override and notes. There is deliberately no cross-plugin SQL foreign key.

Agreement service and tax references are validated through public Work contracts:

- `CB\Work\PublicApi\Services`
- `CB\Work\PublicApi\TaxRates`

If Work is inactive, agreement write/resolution functionality is inert. CRM does not dual-read old service assignments and does not provide a fallback Service/VAT catalog.

## Effective pricing

`CB\CRM\Integration\WorkPricing` registers CRM as an optional provider through `CB\Work\PublicApi\PricingProviders`. CRM returns only the customer agreement layer plus agreement provenance.

Work's canonical `CB\Work\PublicApi\Pricing` contract owns final precedence:

1. explicit Work Item/document override;
2. CRM customer Service Agreement;
3. Work Service default.

An agreement using `inherit` returns an empty pricing override but still retains its agreement reference/provenance for later commercial snapshots.

## Builder adapters

Builder adapters may only call these builder-neutral/public contracts. No adapter reads CRM or Work tables directly. Bricks is the first supported adapter, not a dependency or architectural special case.
