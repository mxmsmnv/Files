# Files Public API

This is the supported PHP and MCPServer contract for Files 1.0.2. Use it as the
canonical source for method names and arguments. Confirm installed module state
and saved configuration in the target ProcessWire site before calling it.

## Loading The Module

```php
<?php namespace ProcessWire;

if(!$modules->isInstalled('Files')) return;

/** @var Files $files */
$files = $modules->get('Files');
```

Methods accepting `?User $actor` use the current ProcessWire user when it is
omitted. Pass an explicit actor in queues, CLI scripts and service integrations.

## Item Rows

Folder and file methods return associative arrays derived from managed item
records. Common keys are:

```text
id, parent_id, kind, owner_user_id, display_name, normalized_name,
mime_type, extension, size_bytes, checksum_sha256, created_at, updated_at
```

Some server-side rows also contain internal migration or storage fields. Never
serialize an entire item row to a browser, log, public API or model. Select an
explicit output allow-list and omit `storage_name`, private paths and legacy
identifiers.

## Permissions

```php
$files->canUse(?User $user = null): bool;
$files->canDownload(?User $user = null): bool;
$files->canUpload(?User $user = null): bool;
$files->canCreateFolders(?User $user = null): bool;
$files->canShare(?User $user = null): bool;
$files->canDelete(?User $user = null): bool;
$files->canManage(?User $user = null): bool;
```

The corresponding ProcessWire permissions are `files-use`, `files-download`,
`files-upload`, `files-folders`, `files-share`, `files-delete` and
`files-manage`. A non-manager must have `files-use` before another capability
takes effect. `files-manage` includes every capability and all-owner access.

## Browsing

```php
$files->rootFolder(?User $actor = null): array;
$files->item(int $id, ?User $actor = null): array;
$files->children(int $folderId, array $filters = [], ?User $actor = null): array;
$files->breadcrumbs(int $itemId, ?User $actor = null): array;
$files->stats(?User $actor = null): array;
$files->descendants(int $folderId, bool $filesOnly = false): array;
```

`children()` accepts:

- `q`: a name fragment;
- `type`: one exact lowercase extension.

It returns folders first and is capped at 500 rows. `descendants()` is a trusted
server-side composition helper without an actor argument; perform an explicit
access check on the root folder before using or exposing its result.

Example:

```php
<?php namespace ProcessWire;

if(!$files->canUse()) return;

$pdfs = $files->children(Files::ROOT_ID, [
    'q' => 'invoice',
    'type' => 'pdf',
]);
```

## Folder Creation

```php
$folder = $files->createFolder(
    int $parentId,
    string $name,
    ?User $actor = null
): array;
```

Requires `files-folders`, an accessible folder and owner or manager authority.
Names are normalized and must be unique within the parent.

## Conventional Uploads

```php
$item = $files->storeUpload(
    array $upload,
    int $folderId = Files::ROOT_ID,
    ?User $actor = null
): array;
```

Pass one genuine `$_FILES` entry. The method checks `files-upload`, folder
access, upload status, configured size, allowed final extension and duplicate
name. It moves bytes into private storage, detects MIME type and calculates
SHA-256 before inserting metadata.

```php
$item = $files->storeContent(
    string $name,
    string $content,
    int $folderId = Files::ROOT_ID,
    ?User $actor = null
): array;
```

`storeContent()` is the bounded server-side byte-ingest method used by the MCP
provider. Do not pass arbitrary local paths or unbounded/untrusted content.

## AJAX Chunked Uploads

```php
$session = $files->beginChunkedUpload(
    string $name,
    int $size,
    int $folderId = Files::ROOT_ID,
    ?User $actor = null
): array;
```

Returns:

```php
[
    'upload_id' => '32-character-secret-session-id',
    'chunk_size' => 5242880,
    'offset' => 0,
    'total_size' => 10485760,
]
```

The actual `chunk_size` is capped at 5 MiB and reduced to stay below the current
PHP `upload_max_filesize` and `post_max_size` values.

```php
$result = $files->appendChunkedUpload(
    string $uploadId,
    int $offset,
    array $chunk,
    ?User $actor = null
): array;
```

Pass a genuine `$_FILES['chunk']` entry and the last server-returned offset.
Intermediate responses contain `complete=false`, `offset` and `total_size`.
The final response also contains `item`. Browser controllers must allow-list
the item ID and never serialize the full item row.

```php
$files->cancelChunkedUpload(string $uploadId, ?User $actor = null): bool;
$files->chunkUploadSize(): int;
```

Sessions are random, private, bound to the initiating user and expire after 24
hours. Re-sending a fully accepted chunk is idempotent by offset. Browser
controllers must validate a ProcessWire CSRF token on start, every chunk and
cancel. The bundled `ProcessFiles` controller already does so.

## Sharing

```php
$share = $files->createShare(
    int $itemId,
    array $options = [],
    ?User $actor = null
): array;
```

Supported options:

```php
[
    'recipient_user_id' => 0, // 0 = anyone with the secret URL
    'days' => 14,
    'max_downloads' => 0,    // 0 = unlimited
    'password' => '',        // public links: empty or at least 8 characters
]
```

Shares are always read-only. The returned array contains `id`, `item_id`,
`url`, `direct_url` and `recipient_user_id`. Both URLs contain a secret and must
not be logged or disclosed beyond the intended recipient.

```php
$files->sharesForItem(int $itemId, ?User $actor = null): array;
$files->sharedWithUser(?User $actor = null): array;
$files->sharedContent(int $shareId, ?User $actor = null): array;
$files->revokeShare(int $id, ?User $actor = null): bool;
$files->shareUrl(string $publicId, string $token): string;
$files->shareDirectUrl(string $publicId, string $token): string;
```

`sharesForItem()` is owner/manager-only and returns authorized copyable URLs
when their encrypted token can be recovered. `sharedWithUser()` and
`sharedContent()` require the exact selected logged-in user. Treat
`sharedContent()` as trusted server-side data and expose an explicit allow-list.
A folder share always represents its current live subtree.

## Downloads And Previews

```php
$files->streamManagedFile(array $item, ?User $actor = null): void;
$files->streamSharedFile(int $shareId, int $fileId, ?User $actor = null): void;
$files->previewKind(int $itemId, ?User $actor = null): string;
$files->previewText(int $itemId, ?User $actor = null): array;
$files->streamManagedPreview(int $itemId, ?User $actor = null): void;
```

Streaming methods send headers and terminate the request. Call them only from a
dedicated controller before other output. Managed downloads/previews require
`files-download` and owner or manager access. Recipient downloads also require
an active exact-user share and consume one download from its cap.

`previewKind()` returns `image`, `pdf`, `text`, `audio`, `video`, `office` or an
empty string. `previewText()` returns:

```php
['content' => '...', 'truncated' => false]
```

Text previews are capped at 512 KiB and must be escaped for their output
context. Browser preview responses are inline, private, non-cacheable,
`nosniff`, same-origin frame restricted and non-indexable.

## ONLYOFFICE

```php
$editor = $files->onlyOfficeEditorConfig(
    int $itemId,
    ?User $actor = null
): array;
```

Requires an allowed office format plus configured `onlyoffice_url` and
`onlyoffice_jwt_secret`. Returns `api_url` and a signed view-only `config`.
Source URLs expire after one hour and are bound to the item checksum. Editing,
download from the editor and save callbacks are disabled by Files.

## Configuration

| Property | Default | Meaning |
| --- | --- | --- |
| `allowed_extensions` | common image, document, archive and media types | Final-extension allow-list |
| `max_upload_mb` | `100` | Maximum size of one managed file |
| `public_path` | `/files/share/` | Public link route prefix |
| `storage_path` | empty | Private directory; empty uses the safe sibling default |
| `default_share_days` | `14` | Preselected share lifetime |
| `max_share_days` | `365` | Hard maximum share lifetime |
| `onlyoffice_url` | empty | ONLYOFFICE Document Server base URL |
| `onlyoffice_jwt_secret` | empty | Matching ONLYOFFICE JWT secret |
| `mcp_service_user_id` | `0` | Dedicated ProcessWire user; zero disables MCP execution |

Changing `storage_path` does not move existing bytes. Changing `public_path`
invalidates previously copied URLs. Treat both as architectural changes with a
backup and rollback plan.

## MCPServer Provider

```php
$files->mcpProviderInfo(): array;
$files->mcpTools(): array;
```

Files reports provider name `files`, title `Files` and version `1.0.1`. Tool
execution remains disabled until `mcp_service_user_id` names an active,
non-guest ProcessWire user. MCPServer owns transport, bearer clients, endpoint,
gateway scopes, rate limits and auditing.

Provider tools:

- read: `files_status`, `files_list`, `files_get`, `files_search`,
  `files_preview_text`, `files_read_chunk`, `files_shared_with_me`,
  `files_shared_content`, `files_read_shared`, `files_shares`;
- draft: `files_recipients`, `files_create_folder`, `files_upload`,
  `files_stage_share`;
- publish: `files_create_share`, `files_revoke_share`;
- admin: `files_delete_file`.

Schemas are closed and bounded. MCP uploads are base64 and SHA-256 verified and
capped at 1 MiB. Owned reads use chunks up to 512 KiB. Recipient-shared reads
are complete files up to 512 KiB and consume download quota.

Folder creation and MCP upload reserve actor-scoped idempotency before the
side effect. Share publishing consumes an unchanged one-hour staged proposal
and requires `CREATE_FILES_SHARE`. Revocation requires `REVOKE_FILES_SHARE`.
Permanent deletion requires admin scope, the exact current checksum and
`DELETE_FILES_FILE_PERMANENTLY`. Share-list tools never return existing URL
secrets.

## Errors And Edge Cases

Methods throw ProcessWire `WirePermissionException`, `Wire404Exception` or
`WireException` for authorization, missing targets and validation/storage
failures. Callers should handle errors without exposing internal paths or SQL.

Common failure cases include inactive users, missing permissions, inaccessible
or duplicate folders/items, disallowed extensions, oversized or partial
uploads, wrong chunk offsets, expired upload sessions, unavailable bytes,
expired/revoked/exhausted shares, wrong passwords and unreachable ONLYOFFICE.

## Hooks And Internal APIs

Files 1.0.1 does not declare a stable public hook/event API. Public methods
whose names begin with `handle` are ProcessWire route callbacks and are not
integration methods. Private/protected methods, SQL tables, `ProcessFiles`
render helpers, storage paths and MCP helper methods are internal and may
change without notice.

## Lifecycle

Installation creates the Files tables, root folder, permissions and private
storage directory, and can import supported FileServer data. Upgrade rechecks
schema and permissions. Uninstall intentionally retains managed bytes, tables
and MCP operation records. Their removal requires a separate verified backup
and explicit destructive action.
