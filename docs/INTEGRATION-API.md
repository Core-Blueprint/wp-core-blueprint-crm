# Core Blueprint CRM Integration API

Core Blueprint CRM exposes a builder-neutral PHP contract for authorized frontend and integration use. WordPress Admin remains the canonical complete CRM management interface.

These contracts are the supported boundary for builders and companion extensions. Consumers must not query CRM-owned custom tables or `CB\CRM\Repository\*` classes directly.

## Authorization model

CRM records are private by default.

- Staff access requires `cb_manage_crm`.
- A normal authenticated user may read only the uniquely linked CRM Contact for their own WordPress account.
- That user may read only active related Organizations and active Services reachable from that Contact context.
- An ambiguous WordPress-user-to-Contact link fails closed.
- CRM Tags and assignment-level pricing details are staff-only projections.
- A linked WordPress account does **not** grant general CRM write access.
- The Phase 5 write actions are staff-only.

Error codes are integration signals; consumers must still treat unavailable and unauthorized records as non-readable and must not infer private CRM state from collection totals or lookup failures.

## Data

### `CB\CRM\Frontend\Data\Contact`

Methods:

- `current()` — current authenticated user's uniquely linked Contact.
- `get( int $contact_id )` — authorization-aware exact read.
- `value( string $field, ?int $contact_id = null )` — one projected field.
- `fields()` — supported field names.

Projected fields:

- `id`
- `display_name`
- `first_name`
- `last_name`
- `job_title`
- `primary_email`
- `primary_phone`
- `linked_user_id`
- `primary_organization`
- `organizations`
- `services`
- `tags` — staff only; empty for normal linked users
- `status`

Normal linked users receive active Organization and Service relationships only. Staff may see historical relationships and assignment-level pricing details.

### `CB\CRM\Frontend\Data\Organization`

Methods:

- `get( int $organization_id )`
- `value( string $field, int $organization_id )`
- `fields()`

Projected fields:

- `id`
- `name`
- `legal_name`
- `contact_methods`
- `address` — primary address projection
- `services`
- `tags` — staff only; empty for normal linked users
- `status`

A non-staff user can read an Organization only when it is actively related to their uniquely linked Contact.

### `CB\CRM\Frontend\Data\Service`

Methods:

- `get( int $service_id )`
- `value( string $field, int $service_id )`
- `fields()`

Projected fields:

- `id`
- `name`
- `pricing_summary`
- `amount_minor`
- `currency`
- `tax_mode`
- `tax_rate`
- `status`

Staff can read any Service record. A normal authenticated user can read only Services actively assigned to their linked Contact or one of its active Organizations.

## Queries

All collection APIs are bounded to a maximum of 100 records per page.

### `CB\CRM\Frontend\Queries\Contacts`

- `current_user()` — current user's uniquely linked Contact.
- `staff( array $args = [] )` — `cb_manage_crm` required.
- `for_organization( int $organization_id, int $limit = 30 )` — staff-only convenience query.

Supported staff filters:

- `search`
- `status`
- `tag`
- `include_ids`
- `page`
- `per_page`

### `CB\CRM\Frontend\Queries\Organizations`

- `staff( array $args = [] )` — `cb_manage_crm` required.

Supported filters:

- `search`
- `status`
- `tag`
- `include_ids`
- `page`
- `per_page`

### `CB\CRM\Frontend\Queries\Services`

- `query( array $args = [] )`

Staff receive the Service catalog. Normal authenticated users receive only their authorization-reachable Services. Anonymous callers receive `crm_login_required`.

Supported filters:

- `search`
- `status`
- `include_ids`
- `page`
- `per_page`

## Conditions

`CB\CRM\Frontend\Conditions\Records` exposes:

- `current_user_has_contact()`
- `contact_has_service( int $service_id, ?int $contact_id = null )`
- `contact_belongs_to_organization( int $organization_id, ?int $contact_id = null )`
- `organization_has_service( int $service_id, int $organization_id )`
- `record_status_is( string $owner_type, int $record_id, string $status )`

Conditions are authorization-aware and fail false when the target is unavailable to the current user.

## Canonical staff actions

### `crm.update_contact`

Class: `CB\CRM\Application\Actions\UpdateContact`

Call:

```php
$result = CB\CRM\Application\Actions\UpdateContact::execute( $contact_id, $input );
```

Requires `cb_manage_crm` and target-record edit permission.

Supported optional input areas:

- `title`
- `status`
- `first_name`
- `last_name`
- `job_title`
- `wp_user_id`
- `email_mode`
- `contact_methods`
- `addresses`
- `names`
- `organizations`
- `services`
- `tags`

### `crm.update_organization`

Class: `CB\CRM\Application\Actions\UpdateOrganization`

Call:

```php
$result = CB\CRM\Application\Actions\UpdateOrganization::execute( $organization_id, $input );
```

Requires `cb_manage_crm` and target-record edit permission.

Supported optional input areas:

- `title`
- `status`
- `legal_name`
- `contact_methods`
- `addresses`
- `services`
- `tags`

Both actions keep validation, WordPress-user-link uniqueness, CRM repository writes, Governance records and activity logging in the CRM domain/application boundary. Transport adapters must call these actions instead of recreating CRM save logic.

Malformed structured input fails the affected area without replacing an existing collection with an empty one. A `crm_update_failed` error may include `updated_areas` and `failed_areas` because existing CRM storage areas are independently transactional. Consumers must surface failure instead of assuming an all-or-nothing write.

## Optional Helpdesk integration

The CRM Contact editor may show recent Helpdesk tickets when Core Blueprint Helpdesk is active and the current staff user is authorized to manage Helpdesk tickets.

The integration boundary is:

- linked WordPress user ID from the CRM Contact is the authoritative customer identity;
- ticket reads use `CB\Helpdesk\Frontend\Queries\Tickets::for_user( $user_id, 10 )`;
- Helpdesk remains responsible for ticket authorization and public field projection;
- the Helpdesk workspace URL is resolved through Base `CB\Core\ExtensionRegistry`;
- CRM does not query Helpdesk tables or `CB\Helpdesk\Ticket\Repository`;
- CRM never mutates Helpdesk ticket storage;
- deactivating Helpdesk removes the optional CRM panel without changing CRM or Helpdesk data.

The first correction intentionally does not add customer-snapshot email matching for guest/external tickets. Such matching requires a dedicated ambiguity-safe public Helpdesk provider before CRM may consume it.

## Explicit non-contracts

The following are not public integration APIs:

- `CB\CRM\Repository\*`
- CRM-owned table names or direct SQL
- WordPress admin form payloads/nonces
- Helpdesk repositories, tables or private admin implementation details
