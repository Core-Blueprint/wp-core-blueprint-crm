# CRM integration-aware Bricks exposure

This layer is optional and remains a thin adapter over builder-neutral public contracts. Bricks does not own CRM ↔ Docs or CRM ↔ Helpdesk matching logic.

## Builder-neutral Helpdesk customer context

`CB\CRM\Frontend\Queries\HelpdeskTickets` is the supported CRM → Helpdesk bridge for ticket data associated with an authorized CRM Contact.

Methods:

- `for_contact( int $contact_id, int $limit = 50 )`
- `count_for_contact( int $contact_id, int $limit = 100 )`
- `has_open_for_contact( int $contact_id, int $limit = 100 )`

The provider resolves the Contact through `CB\CRM\Frontend\Data\Contact`, reads only its public `linked_user_id`, and then delegates ticket authorization/projection to `CB\Helpdesk\Frontend\Queries\Tickets::for_user()`. Helpdesk inactivity returns an empty collection. A CRM user who is not authorized by Helpdesk cannot use this bridge to read another customer's tickets.

`has_open_for_contact()` checks the public Helpdesk `status` projection for the canonical `open` state. It fails false when the Contact or Helpdesk ticket context is unavailable or unauthorized.

## Bricks Query Loop types

The CRM adapter conditionally registers these types only while the relevant companion plugin is active:

### CRM + Docs

- **CRM: Linked documentation** — readable Docs linked to the current CRM Contact or Organization. The relation is resolved through `CB\CRM\Frontend\Queries\DocumentLinks::for_owner()` and every document remains subject to Docs authorization.
- **CRM: Records linked to document** — CRM Contact/Organization relation projections linked to the current readable Doc. Reverse lookup keeps the staff/document-edit boundary enforced by `DocumentLinks::for_document()`.

When looping **Records linked to document**, these integration tags expose the relation projection:

- `{cb_crm_linked_record_id}`
- `{cb_crm_linked_record_name}`
- `{cb_crm_linked_record_type}`
- `{cb_crm_linked_record_relation}`

The current CRM Contact/Organization also exposes:

- `{cb_crm_record_document_count}`

### CRM + Helpdesk

- **CRM: Contact tickets** — authorized Helpdesk ticket projections for the current CRM Contact.
- `{cb_crm_contact_ticket_count}` — bounded ticket count for that Contact.

The Helpdesk ticket loop returns the public Helpdesk projection, so Helpdesk's own Bricks dynamic-data adapter can render ticket fields when Helpdesk is active.

## Bricks Conditions

The CRM condition group conditionally adds:

- **Documentation** — current Contact/Organization has at least one readable linked Doc.
- **Contact · Open Helpdesk tickets** — current Contact has at least one authorized Helpdesk ticket in the canonical `open` state.

These conditions are display helpers only. They do not grant CRM, Docs or Helpdesk access.

## Inactive companion plugins

When Docs or Helpdesk is inactive:

- its integration-specific query types/tags/conditions are not registered;
- stored CRM ↔ Docs relations remain untouched;
- CRM continues to operate normally;
- no Bricks adapter class becomes a hard dependency on the companion plugin.
