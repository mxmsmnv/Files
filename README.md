# Files

Files adds a private, hierarchical file library to ProcessWire with folders,
subfolders, previews, large-file uploads and controlled sharing.

![Files](assets/Files.png)

It is made for sites that need a self-hosted alternative to the core workflows
of Seafile, kDrive or MyCloud without exposing original files from the public
web root.

**Author:** Maxim Semenov<br>
**Website:** [smnv.org](https://smnv.org)<br>
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development:
[GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or
[smnv.org/sponsor](https://smnv.org/sponsor/).

## What Files Does

- Stores original files in private server storage outside the web root.
- Organizes files into folders and nested subfolders.
- Uploads large files with server-sized AJAX chunks, progress and bounded
  retries, while retaining a normal multipart fallback.
- Previews images, PDF, text, audio and video through authenticated responses.
- Optionally previews office documents through an external ONLYOFFICE Document
  Server in read-only mode.
- Creates revocable read-only links for one file or a complete live folder
  subtree.
- Restricts a share to a selected ProcessWire user when required.
- Supports share passwords, expiration dates and download limits.
- Exposes a permission-aware MCPServer provider with bounded read, draft,
  publish and administrative tools.
- Imports supported legacy FileServer metadata while retaining the source data
  for rollback.

## Admin Area

Files adds **Setup → Files**, where authorized users can:

- browse their private library in list or grid view;
- search and filter the current folder;
- create folders and subfolders;
- upload files and monitor chunk progress;
- inspect metadata and SHA-256 checksums;
- preview or download supported files;
- create, copy and revoke file or folder shares;
- review items shared directly with their account.

The interface follows ProcessWire AdminThemeUikit and the Tickets module's
visual language.

## Uploads And Previews

JavaScript uploads begin a private server session and transfer the file in
sequential chunks sized below the current PHP request limits. Every request
checks the ProcessWire session, CSRF token, `files-upload` permission, owner,
folder and byte offset. Temporary sessions expire after 24 hours. The final
file is size-checked, MIME-inspected and SHA-256 hashed before it appears in the
library.

Inline previews are available for JPG/JPEG, PNG, GIF, WebP, PDF, TXT, Markdown,
CSV, JSON, XML, MP3, WAV, M4A, MP4, WebM and MOV. SVG, archives and unconfigured
office formats use the download fallback.

## Sharing

Public links contain a high-entropy secret and may have a password, expiration
date and download cap. Direct user shares require the visitor to sign in as the
exact selected ProcessWire account. Folder shares represent the folder's live
subtree, so later files added below it are included automatically.

All shares are read-only. Files does not provide collaborative editing or
write-enabled public folders.

## Installation

Requirements:

- ProcessWire 3.0.200 or newer;
- PHP 8.1 or newer;
- Fileinfo, mbstring, PDO MySQL and Sodium PHP extensions;
- rewrite rules that pass unmatched public share routes to ProcessWire.

Install the module:

1. Copy or symlink `Files` into `/site/modules/Files`.
2. Refresh modules in ProcessWire Admin.
3. Install **Files**.
4. Review allowed extensions, maximum file size, private storage and public
   sharing path.
5. Grant `files-use` and only the operational permissions each role needs.

## Permissions

| Permission | Capability |
| --- | --- |
| `files-use` | Open the workspace and browse accessible metadata |
| `files-download` | Preview and download accessible files |
| `files-upload` | Upload files to permitted folders |
| `files-folders` | Create folders and subfolders |
| `files-share` | Create and revoke owned-item shares |
| `files-delete` | Permanently delete owned files |
| `files-manage` | Perform every operation and access all owners' items |

Every non-manager needs `files-use` before another Files permission takes
effect.

## ONLYOFFICE

Office previews are optional. Configure the Document Server URL and matching
JWT secret under **Modules → Files → ONLYOFFICE preview**. The Document Server
must be able to resolve and reach the ProcessWire host. Files creates a signed,
one-hour source URL and disables editing and save callbacks.

## MCPServer Integration

When a compatible MCPServer module is installed, Files is discovered as a
provider. Select a dedicated ProcessWire service user under **Modules → Files →
MCP Server** to enable it. Every tool is constrained by both the MCP client's
scope and that user's Files permissions and ownership.

Files does not create bearer clients or own the MCP endpoint. See [API.md](API.md)
for the exact provider contract and mutation safeguards.

## Documentation

- [API.md](API.md) — public PHP API, configuration and MCP tools.
- [AGENTS.md](AGENTS.md) — Olivia and AI-agent usage and safety guidance.
- [CHANGELOG.md](CHANGELOG.md) — release notes.

## Security And Lifecycle

Managed bytes never receive public filesystem URLs. Downloads use private,
no-store and `nosniff` headers. Secrets and passwords are stored as hashes or
authenticated ciphertext where recovery is required for authorized owners.
Uninstall retains database tables and stored bytes; removing that data is a
separate destructive operation.

## Author

Maxim Semenov<br>
[smnv.org](https://smnv.org)<br>
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT — see [LICENSE](LICENSE).
