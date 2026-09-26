# Changelog

All notable changes to Files are documented here.

## [1.0.3] - 2026-09-26

### Fixed

- Recognize ProcessWire's `pgsql` dialect name when reporting the required PostgreSQL PDO extension in server diagnostics.

## [1.0.2] - 2026-09-26

### Fixed

- Replaced boolean `SUM()` expressions in library statistics with portable
  conditional aggregates for PostgreSQL.

## [1.0.1] - 2026-09-26

### Fixed

- Kept staged-share publication transactional on SQLite by relying on
  ProcessWire's immediate write transaction instead of unsupported
  `SELECT ... FOR UPDATE` syntax.
- Documented support for ProcessWire's MySQL/MariaDB, SQLite and PostgreSQL
  database drivers instead of requiring PDO MySQL specifically.

## [1.0.0] - 2026-09-15

### Added

- Added a private ProcessWire file library with folders, nested folders,
  ownership-aware browsing, search, type filters and list/grid views.
- Added AJAX chunked uploads with progress, bounded retries, CSRF protection,
  user-bound temporary sessions, exact offsets, final size validation, MIME
  detection and SHA-256 checksums.
- Added authenticated previews for images, PDF, text, audio and video, plus an
  optional signed read-only ONLYOFFICE integration.
- Added revocable read-only file and live-subtree folder sharing with public or
  exact-user recipients, passwords, expiration and download limits.
- Added granular ProcessWire permissions for browsing, downloading, uploading,
  creating folders, sharing, deletion and all-owner management.
- Added a bounded MCPServer provider for discovery, navigation, search,
  previews, content transfer, folders, uploads, recipient discovery, staged
  sharing, revocation and guarded deletion.
- Added FileServer compatibility import, server diagnostics, Olivia-oriented
  API and agent documentation, sponsorship metadata and an MIT license.
