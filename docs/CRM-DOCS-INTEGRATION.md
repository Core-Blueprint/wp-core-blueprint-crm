# Core Blueprint CRM ↔ Docs Integration

Core Blueprint CRM owns the relation between CRM records and Core Blueprint Docs documents. Core Blueprint Docs remains the owner of document content, document lifecycle, document authorization, and public document projections.

## Ownership and storage

CRM schema 1.2 adds the CRM-owned `cb_crm_document_links` relation table.

Supported owners:

- CRM Contact
- CRM Organization

Each relation stores the CRM owner type/ID, Docs document ID, optional relation context, creation timestamp, and creating user ID. A unique owner/document key makes linking idempotent.

A relation is reference data only. CRM does not copy document content, access rules, categories, tags, or protected metadata into its relation table.

## Authorization

A stored relation never grants document access.

Public reads use `CB\CRM\Frontend\Queries\DocumentLinks::for_owner()`. Linked document IDs are re-resolved through the public authorization-aware Docs query contract before they are returned. Documents that the current user cannot read are omitted.

Reverse reads use `CB\CRM\Frontend\Queries\DocumentLinks::for_document()` and are staff-only. They require `cb_manage_crm` and edit permission for the document.

## Canonical actions

### `crm.link_document`

Class: `CB\CRM\Application\Actions\LinkDocument`

```php
$result = CB\CRM\Application\Actions\LinkDocument::execute(
    'contact',
    $contact_id,
    $document_id,
    [ 'relation_type' => 'Onboarding' ]
);
```

The action requires:

- `cb_manage_crm`;
- a valid Contact or Organization target;
- edit permission for the CRM record;
- the public Docs provider to be available;
- an authorization-aware readable document;
- edit permission for the document.

Identical repeated links are no-ops and do not emit duplicate Governance events. Updating relation context reuses the same relation row.

### `crm.unlink_document`

Class: `CB\CRM\Application\Actions\UnlinkDocument`

```php
$result = CB\CRM\Application\Actions\UnlinkDocument::execute(
    'organization',
    $organization_id,
    $document_id
);
```

Unlink uses the same CRM/document authorization boundary. Unlinking a missing relation is an idempotent no-op.

## Admin integration

When Core Blueprint Docs is active, CRM adds an optional Docs panel to Contact and Organization editors. Staff can search Docs through `CB\Docs\Frontend\Search::documents()`, link a result with optional context, open readable documents, and unlink existing relations.

When both plugins are active, CRM can also add a CRM panel to a Docs edit screen. That reverse panel searches the public CRM staff query contracts and links Contacts or Organizations to the current document.

The admin transport is vanilla JavaScript and all CRM/Docs AJAX endpoints are POST-only. Non-POST requests are rejected before nonce processing. AJAX nonces protect the transport only; canonical action methods still enforce capability and object-level authorization server-side.

If Docs is inactive, the integration UI and actions become unavailable but stored CRM relations remain intact.

## Lifecycle

Moving a CRM record or document to Trash does not remove relations, so restoration preserves the links.

Permanent deletion cleans up CRM-owned relation rows:

- permanently deleting a Contact/Organization removes its relations;
- permanently deleting the referenced WordPress document removes rows for that document ID.

Governance records link/update/unlink events using CRM record ID/type, document ID and relation context. Document content and relation notes are not copied into Governance payloads.

## Public and private boundaries

Public integration contracts:

- `CB\CRM\Frontend\Queries\DocumentLinks::for_owner()`
- `CB\CRM\Frontend\Queries\DocumentLinks::for_document()`
- `CB\CRM\Application\Actions\LinkDocument::execute()`
- `CB\CRM\Application\Actions\UnlinkDocument::execute()`

Private implementation details that consumers must not call directly:

- `CB\CRM\Repository\DocumentLinks`
- `cb_crm_document_links`
- CRM/Docs admin AJAX actions
- Docs private storage or admin implementation classes
