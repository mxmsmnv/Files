<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/Files.module.php');
$process = file_get_contents($root . '/ProcessFiles.module.php');
$css = file_get_contents($root . '/assets/files-admin.css');
$js = file_get_contents($root . '/assets/files-admin.js');
$configCss = file_get_contents($root . '/assets/files-config.css');

$checks = [
	'private default storage is outside root' => str_contains($service, "dirname(rtrim((string)\$this->wire('config')->paths->root"),
	'share secret is hashed' => preg_match('/hash\(\s*[\'\"]sha256[\'\"]\s*,\s*\$token\s*\)/', $service) === 1,
	'share token recovery is authenticated encryption' => str_contains($service, 'sodium_crypto_secretbox(') && str_contains($service, 'sodium_crypto_secretbox_open('),
	'passwords use password_hash' => preg_match('/password_hash\(\s*\$password\s*,\s*PASSWORD_DEFAULT\s*\)/', $service) === 1,
	'password grant does not redirect a case-sensitive token' => !str_contains($service, "redirect((string)\$this->wire('input')->url())"),
	'public downloads are attachments' => str_contains($service, 'Content-Disposition: attachment'),
	'public downloads use nosniff' => str_contains($service, 'X-Content-Type-Options: nosniff'),
	'admin writes validate CSRF' => substr_count($process, "CSRF->validate()") >= 4,
	'granular permissions cover every operation' => str_contains($service, "PERMISSION_DOWNLOAD = 'files-download'") && str_contains($service, "PERMISSION_UPLOAD = 'files-upload'") && str_contains($service, "PERMISSION_FOLDERS = 'files-folders'") && str_contains($service, "PERMISSION_SHARE = 'files-share'") && str_contains($service, "PERMISSION_DELETE = 'files-delete'") && substr_count($service, 'requireCapability(') >= 8,
	'first release has no historical permission upgrade path' => !str_contains($service, 'grantLegacyCapabilities()'),
	'workspace hides unauthorized operations' => str_contains($process, 'canCreateFolders()') && str_contains($process, 'canUpload()') && str_contains($process, 'canDownload()') && str_contains($process, 'canShare()') && str_contains($process, 'canDelete()'),
	'folder tree has a closure table' => str_contains($service, 'files_tree') && str_contains($service, 'ancestor_id') && str_contains($service, 'descendant_id'),
	'folder creation is public API' => str_contains($service, 'public function createFolder('),
	'folder shares verify descendants' => str_contains($service, 'isDescendant('),
	'file shares expose explicit direct downloads' => str_contains($service, 'shareDirectUrl(') && str_contains($service, "get('dl')"),
	'user shares require an exact recipient' => str_contains($service, "recipient_user_id") && str_contains($service, "(int)\$user->id"),
	'user labels use ProcessWire Users API' => !preg_match('/\bJOIN\s+`?users`?\b/i', $service) && str_contains($service, "wire('users')->get"),
	'legacy tables are retained' => str_contains($service, 'fileserver_files') && str_contains($service, 'fileserver_shares') && str_contains($service, 'public function ___uninstall(): void {}'),
	'CSS is scoped' => !preg_match('/^\s*(?:body|html|\.uk-|#)/m', $css),
	'dark theme tokens are used' => str_contains($css, 'var(--pw-blocks-background)') && str_contains($css, 'var(--pw-text-color)'),
	'file sharing uses a modal dialog' => str_contains($process, 'FilesShareDialog') && str_contains($process, 'Internal Link') && str_contains($process, 'Direct download link'),
	'library controls are functional and labeled' => str_contains($process, 'typeOptions(') && str_contains($process, 'Apply filters') && str_contains($process, 'Clear filters') && !str_contains($process, 'Select all') && !str_contains($process, 'FilesViewModes'),
	'list and grid views are functional' => str_contains($process, "get('view')==='grid'") && str_contains($process, 'FilesViewToggle') && str_contains($process, 'FilesGridItem') && str_contains($process, 'viewUrl(') && str_contains($css, '.FilesGridItem'),
	'library table has responsive labels' => str_contains($process, 'data-label=') && str_contains($css, 'content: attr(data-label)') && str_contains($css, '.FilesTableScroll thead { display: none; }'),
	'folder dialog suppresses browser autofill' => str_contains($process, 'name="folder_name"') && str_contains($process, 'autocomplete="off"') && str_contains($process, "post('folder_name')"),
	'upload page exposes policy and feedback' => str_contains($process, 'FilesDropzone') && str_contains($process, 'FilesSelectedFile') && str_contains($process, 'accept=') && str_contains($process, 'Private by default'),
	'large browser uploads are chunked over AJAX' => str_contains($service, 'public function beginChunkedUpload(') && str_contains($service, 'public function appendChunkedUpload(') && str_contains($service, 'public function cancelChunkedUpload(') && str_contains($service, 'Upload offset mismatch') && str_contains($process, '___executeUploadStart') && str_contains($process, '___executeUploadChunk') && str_contains($process, "unset(\$result['item'])") && str_contains($js, 'file.slice(offset, end)') && str_contains($js, "'X-Requested-With': 'XMLHttpRequest'") && str_contains($js, 'files-upload-meter'),
	'chunk sessions are private bounded and cleaned' => str_contains($service, 'CHUNK_SESSION_TTL = 86400') && str_contains($service, "owner_user_id") && str_contains($service, "is_uploaded_file(\$tmp)") && str_contains($service, "preg_match('/^[A-Za-z0-9_-]{32}$/D'") && str_contains($service, 'cleanupChunkSessions()') && str_contains($service, 'registerStoredFile('),
	'upload page is responsive' => str_contains($css, '.FilesUploadLayout') && str_contains($css, '.FilesDropzone.is-dragging') && str_contains($css, '.FilesUploadSelectedFile[hidden]') === false && str_contains($css, '.FilesSelectedFile[hidden]'),
	'item page separates metadata and controlled access' => str_contains($process, 'FilesPropertiesPanel') && str_contains($process, 'FilesAccessPanel') && str_contains($process, 'FilesShareScope') && str_contains($process, 'Create sharing link'),
	'file previews are authenticated and safely rendered' => str_contains($service, 'public function streamManagedPreview(') && str_contains($service, 'public function previewText(') && str_contains($service, 'managedFileForOwner(') && str_contains($service, "default-src 'none'") && str_contains($service, 'text/plain; charset=UTF-8') && str_contains($process, 'FilesPreviewStage') && str_contains($process, '<pre tabindex="0">'),
	'open preview navigates in the active tab' => str_contains($process, "_('Open preview')") && !str_contains($process, 'target="_blank"'),
	'unsafe preview formats fall back to download' => str_contains($process, 'Preview unavailable') && str_contains($process, 'Download file') && !str_contains($service, "'svg' => 'image/svg+xml'"),
	'ONLYOFFICE previews use signed view-only configuration' => str_contains($service, 'public function onlyOfficeEditorConfig(') && str_contains($service, "'mode' => 'view'") && str_contains($service, "'edit'=>false") && str_contains($service, 'jwtEncode(') && str_contains($service, 'onlyOfficeSourceSignature(') && str_contains($process, 'new DocsAPI.DocEditor('),
	'ONLYOFFICE source links are short lived' => str_contains($service, 'time() + 3600') && str_contains($service, '$expires > time() + 3660') && str_contains($service, 'hash_equals($expected, $signature)'),
	'share history is legible and guarded' => str_contains($process, 'FilesShareHistory') && str_contains($process, 'FilesShareCount') && str_contains($process, 'Revoke this sharing link?') && str_contains($css, '.FilesBadge[data-state="warning"]'),
	'settings show server diagnostics' => str_contains($service, 'serverInformationMarkup(') && str_contains($service, 'Maximum file size') && str_contains($service, 'AJAX chunks up to') && str_contains($service, 'PHP extensions'),
	'settings are grouped and documented' => str_contains($service, 'FilesConfigOverview') && substr_count($service, '->description') >= 9 && substr_count($service, '->notes') >= 6,
	'config CSS is scoped and responsive' => !preg_match('/^\s*(?:body|html|\.uk-|#(?!ModuleEditForm))/m', $configCss) && str_contains($configCss, '@media (max-width: 640px)'),
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
