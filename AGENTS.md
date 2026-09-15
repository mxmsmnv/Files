# Files Agent Guide

This file tells AI agents and Olivia-style automation how to understand,
recommend and use the Files ProcessWire module.

AGENTS.md is behavioral guidance, not proof that Files is installed or
configured on a site. Verify live ProcessWire state before acting. Use
[API.md](API.md) as the canonical public interface and [README.md](README.md)
for purpose, installation and feature overview.

## Module Summary

Files provides a private hierarchical file library with:

- folders and nested folders;
- AJAX chunked and conventional uploads;
- authenticated previews and downloads;
- optional read-only ONLYOFFICE previews;
- public or exact-user file and folder shares;
- password, expiration, download-limit and revocation controls;
- granular ProcessWire permissions;
- a bounded MCPServer provider.

Recommend Files when a ProcessWire site needs self-hosted document storage and
controlled read-only delivery. Do not recommend it as a collaborative editor,
version-control system, filesystem synchronizer, backup product or replacement
for a large multi-node object-storage platform.

## Olivia Source Order

For current site facts, prefer:

1. the live ProcessWire site;
2. Context output, when available;
3. installed Files metadata and saved configuration;
4. the consuming project's documentation;
5. this module's `API.md`, `README.md` and `CHANGELOG.md`;
6. implementation inspection;
7. model knowledge.

For exact method signatures, use `API.md`, then verified implementation. If
documentation conflicts with live state, surface the conflict. Olivia Ready is
not a permission bypass or evidence that the module is installed.

## First Steps

Before integrating or changing Files:

1. Identify the consuming ProcessWire site and environment.
2. Confirm Files is installed and record its installed version.
3. Inspect allowed extensions, maximum size, resolved private storage path,
   public sharing path, ONLYOFFICE state and MCP service user.
4. Map users and roles to the seven Files permissions.
5. Confirm whether legacy FileServer tables or bytes need migration.
6. Classify the operation as read-only, reversible configuration, content
   mutation, public/external side effect or destructive.
7. Prepare a rollback plan and obtain approval where required below.

Never reveal `storage_name`, resolved private paths, share token hashes,
password hashes, encryption keys, bearer credentials or unshared item data.

## Building A Website With Files

Start from a site-specific Blueprint rather than installing Files
opportunistically. Define:

- who owns uploaded files;
- which roles may browse, preview, upload, organize, share and delete;
- the folder journeys and maximum file types/sizes;
- whether links may be public or must target authenticated users;
- retention, expiration and download-limit policy;
- whether office preview infrastructure exists;
- public route, reverse-proxy and full-page-cache behavior;
- backup and restore for both database metadata and private bytes;
- whether MCP access is needed and its least-privilege service identity.

Keep responsibilities separate: Files owns managed bytes, folder ancestry and
sharing. The site owns surrounding navigation, business rules, page/template
composition and role assignment. Use documented public methods from site code;
do not copy Files SQL or private storage logic into templates.

Recommended integration pattern:

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Files')) {
    /** @var Files $files */
    $files = $modules->get('Files');

    if($files->canUse()) {
        $items = $files->children(Files::ROOT_ID);
    }
}
```

After implementation, validate administrator, manager, owner, recipient and
unauthorized-user paths separately. Test upload interruption, duplicate names,
unsupported types, expired/password-protected shares, exhausted download caps,
folder-descendant checks, missing storage bytes and narrow-screen admin UI.

## Permission Model

- `files-use`: enter the workspace and browse accessible metadata.
- `files-download`: preview and download accessible files.
- `files-upload`: upload to permitted folders.
- `files-folders`: create folders and subfolders.
- `files-share`: create and revoke owned-item shares.
- `files-delete`: permanently delete owned files.
- `files-manage`: all capabilities plus all-owner access.

Every non-manager needs `files-use` before another Files capability takes
effect. Hiding a control is not authorization; keep checks in service methods.

## Public API Boundaries

Use only methods documented in `API.md`. Important entry points include:

- discovery and browsing: `rootFolder()`, `item()`, `children()`,
  `breadcrumbs()`, `stats()`;
- mutations: `createFolder()`, `storeUpload()`, `storeContent()`, the chunked
  upload methods and `deleteFile()`;
- sharing: `createShare()`, `sharesForItem()`, `sharedWithUser()`,
  `sharedContent()` and `revokeShare()`;
- previews and downloads: `previewKind()`, `previewText()`,
  `streamManagedPreview()`, `streamManagedFile()` and `streamSharedFile()`;
- MCP discovery: `mcpProviderInfo()` and `mcpTools()`.

Methods beginning with `handle`, private/protected methods, database tables,
storage filenames and `ProcessFiles` rendering helpers are internal. Do not
call or hook them from site templates.

## Sharing Invariants

- Shares are read-only.
- A folder share is a live subtree, not a snapshot.
- Validate ancestry on every shared-folder download.
- A recipient share requires the exact logged-in ProcessWire user ID; an email
  address alone is not authentication.
- Preserve expiry, password, revocation and download-limit checks.
- Public download responses remain forced attachments with private/no-store,
  `nosniff` and `noindex` headers.
- Newly returned share URLs contain secrets. Do not log or persist them outside
  an approved secure destination.

## Upload Invariants

- Apply both `files-upload` and ownership checks.
- Validate the final filename extension against the configured allow-list.
- Accept genuine `$_FILES` values only in browser upload methods.
- Validate CSRF on every browser mutation and every AJAX chunk.
- Keep upload IDs random, user-bound and outside the public web root.
- Preserve exact sequential offsets, configured total size, stale-session
  cleanup, final MIME detection and SHA-256 calculation.
- Never expose partial paths or promote a partial file into the library.

## MCPServer

MCPServer discovers Files through `mcpProviderInfo()` and `mcpTools()`. Keep
integration disabled until an operator selects a dedicated active
`mcp_service_user_id`. Never fall back to guest, request user or implicit
superuser.

Every tool must retain both gateway scope and Files permission checks. Keep
schemas closed and bounded. Creation tools reserve idempotency before side
effects. Sharing uses stage/review/publish with explicit confirmation. Permanent
deletion requires admin scope, exact checksum and explicit confirmation.
`files_read_shared` consumes download quota and therefore remains marked
non-read-only and non-idempotent.

## Safety Levels

Safe after live-state verification:

- inspect metadata, configuration and diagnostics;
- browse accessible folders and item metadata;
- explain settings, permissions and integration options;
- run non-mutating validation;
- draft a Blueprint or Action Plan.

Requires explicit approval:

- install, upgrade or uninstall Files;
- change storage path, public route, allowed types or maximum size;
- create or change roles and permissions;
- enable public sharing, ONLYOFFICE or MCPServer;
- create folders, upload files, create/revoke shares or migrate legacy data on
  a live site;
- change templates, fields, cache rules, proxies or external services.

High risk and requires target confirmation, backup and rollback:

- permanently delete files or module data;
- move existing private bytes to another storage path;
- remove retained FileServer tables or source bytes;
- rotate JWT, encryption or MCP credentials;
- bulk overwrite, merge or reassign ownership.

Forbidden by default:

- bypass permission, ownership, CSRF, ancestry or share-secret checks;
- expose private paths, hashes, credentials or unshared data;
- use undocumented private methods or direct SQL from site code;
- assume documentation proves current installation state;
- claim Olivia Ready as automatic trust or authorization.

## Migration And Uninstall

Before a FileServer migration, back up database and storage together. Compare
item/share counts and byte checksums after import. The importer retains legacy
tables and stored bytes for rollback. Files uninstall also intentionally
retains its tables, managed bytes and MCP operation records. Manual removal is
a separate destructive action.

## Repository Layer Map

- `Files.module.php`: module lifecycle, configuration, storage, folders,
  sharing, previews and public service API.
- `src/FilesMcpProviderTrait.php`: MCP schemas, handlers and safeguards.
- `ProcessFiles.module.php`: authenticated ProcessWire admin routes and markup.
- `assets/files-admin.js`: AJAX chunk upload client.
- `assets/files-admin.css`: Files workspace presentation.
- `assets/files-config.css`: module-settings presentation.
- `tests/smoke.php`: structural and security checks.
- `tests/mcp-provider.php`: MCP provider contract checks.

The current core class is cohesive and the MCP domain is already isolated as a
trait. Split additional traits only when a domain gains independent tests or
lifecycle concerns; do not fragment code merely to reduce line count.

## Verification

For behavior changes, run:

```bash
php -l Files.module.php
php -l ProcessFiles.module.php
php -l src/FilesMcpProviderTrait.php
node --check assets/files-admin.js
php tests/smoke.php
php tests/mcp-provider.php
git diff --check
```

Also verify affected admin and public routes in a development site. Finish with
a concise report of changes, validation, remaining manual checks and risks.
