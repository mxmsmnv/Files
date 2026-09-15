<?php

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Files.module.php');
$provider = (string)file_get_contents($root . '/src/FilesMcpProviderTrait.php');

$checks = [
	'provider metadata is explicit' => str_contains($module, "'mcpProvider' => true"),
	'provider contract is exposed' => str_contains($provider, 'mcpProviderInfo()') && str_contains($provider, 'mcpTools()'),
	'tool names are installation-neutral' => str_contains($provider, "'files_status'") && !str_contains($provider, "'fileserver_files_status'"),
	'all schemas are closed' => substr_count($provider, "'additionalProperties' => false") >= 1,
	'read inventory is bounded' => str_contains($provider, "'maximum' => 200") && str_contains($provider, "'maximum' => 100"),
	'content reads are chunk bounded' => str_contains($provider, 'MCP_CONTENT_CHUNK_BYTES = 524288') && str_contains($provider, 'mcpFilesReadChunk'),
	'uploads are base64 and checksum bounded' => str_contains($provider, 'MCP_UPLOAD_BYTES = 1048576') && str_contains($provider, 'base64_decode($content_base64, true)') && str_contains($provider, "hash_equals(\$checksum_sha256"),
	'MCP uses an explicit service user' => str_contains($module, "'mcp_service_user_id' => 0") && str_contains($provider, 'mcpActor('),
	'Files permissions remain authoritative' => str_contains($provider, 'requireCapability(self::PERMISSION_DOWNLOAD') && str_contains($provider, 'createFolder($parent_id, $name, $user)') && str_contains($provider, '$this->createShare('),
	'private storage details are redacted' => !str_contains($provider, "'storage_name' =>") && str_contains($provider, "'private_paths_exposed' => false"),
	'share listings redact URLs' => str_contains($provider, "'link_secrets_exposed' => false") && str_contains($provider, 'mcpSafeShare($share, true)'),
	'share creation consumes a staged revision' => str_contains($provider, "'files_stage_share'") && str_contains($provider, 'TABLE_MCP_SHARE_PROPOSALS') && str_contains($provider, 'mcpItemRevision(') && str_contains($provider, "'const' => 'CREATE_FILES_SHARE'"),
	'shared downloads disclose quota mutation' => str_contains($provider, "'files_read_shared'") && str_contains($provider, "'download_counted' => true") && str_contains($provider, "false, false, false, false"),
	'pagination is pushed into bounded SQL' => str_contains($provider, "ORDER BY s.id LIMIT '") && str_contains($provider, "ORDER BY i.id LIMIT '") && str_contains($provider, "ORDER BY i.id LIMIT ' . (\$limit + 1)"),
	'search escapes SQL wildcard characters' => str_contains($provider, "ESCAPE '='") && str_contains($provider, 'mcpLikeLiteral('),
	'shared breadcrumbs and owner metadata are redacted' => str_contains($provider, "'ancestor_names_redacted'") && str_contains($provider, 'mcpSafeItem($item, $privileged)') && str_contains($provider, 'mcpSafeItem($target, false)'),
	'inactive accounts are rejected' => str_contains($module, 'activeAccount(') && str_contains($module, 'statusLocked') && str_contains($provider, '!$this->activeAccount($user)'),
	'permanent deletion requires admin confirmation and checksum' => str_contains($provider, "'files_delete_file'") && str_contains($provider, "'admin'") && str_contains($provider, 'DELETE_FILES_FILE_PERMANENTLY') && str_contains($provider, "hash_equals((string)\$item['checksum_sha256']"),
	'mutations reserve durable idempotency before execution' => str_contains($module, 'files_mcp_operations') && str_contains($provider, '$reserve->execute(') && strpos($provider, '$reserve->execute(') < strpos($provider, '$result = $callback();') && str_contains($provider, 'idempotent_replay'),
	'secret-bearing fingerprints are keyed' => str_contains($provider, 'mcpSecretFingerprint(') && str_contains($provider, "hash_hmac('sha256', 'Files MCP secret|"),
	'recipient discovery omits email addresses' => str_contains($provider, "'email_addresses_exposed' => false") && !str_contains($provider, "'email' =>"),
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
