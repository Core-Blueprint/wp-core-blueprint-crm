# Core Blueprint CRM integration API

## Ownership

CRM owns Contacts, Organizations, customer identity/context, organization Business Identifiers and customer-specific Service Agreements. Core Blueprint Work owns Services, default pricing and VAT/tax data.

There is no CRM Service CPT, CRM ServicePricing authority, CRM VAT repository/table or compatibility facade for the removed catalog architecture. CRM Business Identifiers are organization identity data; they do not make CRM the authority for tax rates or tax calculation.

## Builder-neutral read contracts

- `CB\CRM\Frontend\Data\Contact`
- `CB\CRM\Frontend\Data\Organization`
- `CB\CRM\Frontend\Queries\Contacts`
- `CB\CRM\Frontend\Queries\Organizations`
- `CB\CRM\Frontend\Queries\ServiceAgreements`
- the existing optional Docs/Helpdesk integration providers

`Contact` and `Organization` projections expose `service_agreements`. Agreement items reference Work Service IDs and names. Sensitive pricing/notes remain staff-only.

`Organization` additionally defines two staff-only Business Identifier projections:

- `business_identifiers`: a compact canonical map for `vat`, `registration_number` and `eori`. An explicitly primary value wins; otherwise the first stored value of that type is used.
- `business_identifier_records`: the full structured records with `type`, `value`, optional two-letter `country`, optional `label` and `is_primary`.

Both Business Identifier fields return an empty array for non-staff Organization readers, even when that reader is otherwise authorized to see the Organization. Administrative consumers that need business identity must use an authorized staff context such as `CB\CRM\Frontend\Queries\Organizations::staff()`.

Business Identifier storage accepts the standard types `vat`, `registration_number`, `eori` and `other`. `other` requires a descriptive label. CRM stores identity values but does not claim that a supplied registration number is externally or legally verified.

## Server-side first-party Contact contract

`CB\CRM\PublicApi\Contacts` is the canonical read-only Contact contract for trusted first-party server-side consumers such as background workers, scheduled jobs and sibling extensions.

It is intentionally separate from the authenticated `Frontend` query boundary. The Public API does not impersonate a WordPress user and does not perform presentation-layer capability checks; the consuming product must authorize the operation that caused the read before calling it. The contract is PHP-only and does not expose a REST, AJAX or shortcode endpoint.

`Contacts::get( $contact_id )` returns a minimal scalar/list projection containing:

- `contact_id`, display name, resolved first/last name, CRM-owned name prefix and CRM status;
- the effective email plus `email_source` and `email_path` provenance;
- linked WordPress user ID;
- CRM tag slugs;
- related organization IDs.

`name_prefix` is canonical CRM-owned Contact identity. It is intentionally independent from live WordPress/WooCommerce first/last-name source resolution and from the aliases/history stored in `cb_crm_names`.

Effective email selection remains owned by the existing CRM source resolver. The Public API only annotates which authority supplied the chosen value (`crm`, `wordpress` or `woocommerce`); it does not introduce a second email-selection policy.

`Contacts::query()` supports only generic CRM filters: search, CRM status, one CRM tag slug, organization ID, explicit Contact IDs and pagination. Returned Contacts exclude Trash and auto-drafts.

Marketing permission, newsletter subscription state, suppression, bounce/complaint state and campaign eligibility are deliberately **not** part of this CRM contract. A communication product may use CRM identity and segmentation context, but it must own and apply its own communication-permission policy before sending.

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

## Optional sibling context

CRM may project sibling-module context into a Contact or Organization editor, but the sibling module remains the authority for its domain state.

The Subscriptions integration is read-only and resolves the Contact's linked WordPress user through CRM identity. It then reads subscription presentation data only through `CB\Subscriptions\Frontend\Queries::for_user()`. CRM does not copy subscription status, renewal dates, plan data or payment state into CRM storage, and the panel is shown only to operators who satisfy Subscriptions' own `manage_woocommerce` / `manage_options` staff boundary.

WooCommerce orders and Helpdesk tickets follow the same ownership rule: CRM may display or reference their customer context, while WooCommerce and Helpdesk remain authoritative for order/ticket state.

## WooCommerce identity provisioning

WooCommerce remains authority for customers, orders, checkout and billing data. CRM does not mirror WooCommerce orders or treat checkout fields as CRM master data.

For registered customers, `CB\CRM\Integration\WooCommerce` reconciles CRM identity from three converging WooCommerce lifecycle signals: order-status changes, payment completion and durable order updates. Every path reloads the order from WooCommerce and only provisions once the order is `processing` or `completed` and has a real registered customer ID. This covers payment-provider flows where account binding becomes durable after the earliest payment/status callbacks.

Provisioning policy:

1. an existing canonical WordPress User → Contact link is reused;
2. ambiguous existing links fail closed;
3. an existing CRM email candidate requires operator review and is never silently claimed;
4. only when no canonical link or email collision exists may CRM create a new Contact and link that WordPress user.

Guest orders do not create CRM Contacts. Organization creation from WooCommerce billing/company data is deliberately outside this provisioning path. Operators can review and repair identity links through `CRM → Users`.

`CB\CRM\Application\ContactProvisioner` owns this internal provisioning policy so commerce integrations do not write canonical identity metadata directly. New-contact creation is protected by an atomic, non-autoloaded WordPress option lease keyed by WordPress user ID, with stale-lease recovery and shutdown/finally release. The service re-checks identity after acquiring the lease and rolls back only the Contact created by its own request if post-write reconciliation is not uniquely canonical.

Failed automatic provisioning is recorded as warning-level WooCommerce activity in the Base audit log with the order, user, source and fail-closed reason. Repeated identical failures for the same order, user and reason are deduplicated for a short window across the converging Woo signals; transient lock contention is treated as expected concurrency and does not generate warning noise.

## Builder adapters

Builder adapters may only call these builder-neutral/public contracts. No adapter reads CRM or Work tables directly. Bricks is the first supported adapter, not a dependency or architectural special case.
