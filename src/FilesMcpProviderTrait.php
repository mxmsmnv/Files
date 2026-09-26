<?php namespace ProcessWire;

/** Bounded MCPServer provider for the private Files domain. */
trait FilesMcpProviderTrait {
	private const MCP_VERSION = '1.0.1';
	private const MCP_CONTENT_CHUNK_BYTES = 524288;
	private const MCP_UPLOAD_BYTES = 1048576;

	public function mcpProviderInfo(): array {
		return ['name' => 'files', 'title' => 'Files', 'version' => self::MCP_VERSION];
	}

	/** @return array<int,array<string,mixed>> */
	public function mcpTools(): array {
		$id = ['type' => 'integer', 'minimum' => 1, 'maximum' => 4294967295];
		$idempotency = ['type' => 'string', 'minLength' => 8, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._:-]+$'];
		return [
			$this->mcpTool('files_status', 'Files status', 'Return MCP readiness, the service-user capability map, and bounded library statistics without private paths or secrets.', [$this, 'mcpFilesStatus']),
			$this->mcpTool('files_list', 'List Files folder', 'List a bounded page of accessible child files and folders using stable item IDs.', [$this, 'mcpFilesList'], 'read', [
				'folder_id' => $id,
				'query' => ['type' => 'string', 'maxLength' => 120],
				'extension' => ['type' => 'string', 'maxLength' => 30, 'pattern' => '^[A-Za-z0-9]*$'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'after_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			]),
			$this->mcpTool('files_get', 'Get Files item', 'Return safe metadata and breadcrumbs for one accessible item.', [$this, 'mcpFilesGet'], 'read', ['item_id' => $id], ['item_id']),
			$this->mcpTool('files_search', 'Search Files', 'Search accessible item names with bounded cursor pagination.', [$this, 'mcpFilesSearch'], 'read', [
				'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
				'kind' => ['type' => 'string', 'enum' => ['any', 'file', 'folder']],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
				'after_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			], ['query']),
			$this->mcpTool('files_preview_text', 'Preview Files text', 'Return a bounded plain-text preview for an owned or managed text file.', [$this, 'mcpFilesPreviewText'], 'read', [
				'item_id' => $id,
				'max_characters' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200000],
			], ['item_id']),
			$this->mcpTool('files_read_chunk', 'Read Files content chunk', 'Return one bounded base64 chunk from an owned or managed file. Reassemble chunks using next_offset until eof.', [$this, 'mcpFilesReadChunk'], 'read', [
				'item_id' => $id,
				'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991],
				'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MCP_CONTENT_CHUNK_BYTES],
			], ['item_id']),
			$this->mcpTool('files_shared_with_me', 'Files shared with service user', 'List active file and folder shares addressed to the configured service user.', [$this, 'mcpFilesSharedWithMe'], 'read', [
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
				'after_share_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			]),
			$this->mcpTool('files_shared_content', 'Files shared-folder content', 'List a bounded live subtree for one active share addressed to the configured service user.', [$this, 'mcpFilesSharedContent'], 'read', [
				'share_id' => $id,
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
				'after_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			], ['share_id']),
			$this->mcpTool('files_read_shared', 'Read small shared file', 'Return one complete active recipient-shared file up to 512 KiB and consume one download from its share limit.', [$this, 'mcpFilesReadShared'], 'read', [
				'share_id' => $id,
				'item_id' => $id,
			], ['share_id', 'item_id'], false, false, false, false),
			$this->mcpTool('files_shares', 'List Files shares', 'Return bounded controlled-share metadata for one owned or managed item without decrypting link secrets.', [$this, 'mcpFilesShares'], 'read', [
				'item_id' => $id,
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
				'after_share_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			], ['item_id']),
			$this->mcpTool('files_recipients', 'List Files share recipients', 'Return a bounded list of eligible ProcessWire account IDs and labels without email addresses.', [$this, 'mcpFilesRecipients'], 'draft', [
				'query' => ['type' => 'string', 'maxLength' => 120],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
				'after_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
			], [], false, false, true),
			$this->mcpTool('files_create_folder', 'Create Files folder', 'Create one private folder under an accessible owned destination. Idempotent replays return the original stable item ID.', [$this, 'mcpFilesCreateFolder'], 'draft', [
				'parent_id' => $id,
				'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
				'idempotency_key' => $idempotency,
			], ['parent_id', 'name', 'idempotency_key']),
			$this->mcpTool('files_upload', 'Upload Files content', 'Store one private file from bounded base64 content after verifying its SHA-256 checksum.', [$this, 'mcpFilesUpload'], 'draft', [
				'folder_id' => $id,
				'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
				'content_base64' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 1398104, 'pattern' => '^[A-Za-z0-9+/]*={0,2}$'],
				'checksum_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
				'idempotency_key' => $idempotency,
			], ['folder_id', 'filename', 'content_base64', 'checksum_sha256', 'idempotency_key']),
			$this->mcpTool('files_stage_share', 'Stage Files share', 'Validate and stage one reviewable public or exact-user share proposal without exposing the item.', [$this, 'mcpFilesStageShare'], 'draft', [
				'item_id' => $id,
				'recipient_user_id' => ['type' => 'integer', 'minimum' => 0],
				'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
				'max_downloads' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
				'password' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
				'idempotency_key' => $idempotency,
			], ['item_id', 'recipient_user_id', 'days', 'max_downloads', 'idempotency_key']),
			$this->mcpTool('files_create_share', 'Create Files share', 'Publish one previously staged, unchanged Files share proposal after explicit review confirmation.', [$this, 'mcpFilesCreateShare'], 'publish', [
				'proposal_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
				'password' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
				'confirmation' => ['type' => 'string', 'const' => 'CREATE_FILES_SHARE'],
			], ['proposal_id', 'confirmation']),
			$this->mcpTool('files_revoke_share', 'Revoke Files share', 'Revoke one controlled share by stable ID after explicit confirmation.', [$this, 'mcpFilesRevokeShare'], 'publish', [
				'share_id' => $id,
				'confirmation' => ['type' => 'string', 'const' => 'REVOKE_FILES_SHARE'],
			], ['share_id', 'confirmation'], false, true),
			$this->mcpTool('files_delete_file', 'Permanently delete Files file', 'Permanently delete one owned file only when its stable ID and exact checksum both match.', [$this, 'mcpFilesDeleteFile'], 'admin', [
				'item_id' => $id,
				'checksum_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
				'confirmation' => ['type' => 'string', 'const' => 'DELETE_FILES_FILE_PERMANENTLY'],
			], ['item_id', 'checksum_sha256', 'confirmation'], false, true),
		];
	}

	/** @return array<string,mixed> */
	private function mcpTool(string $name, string $title, string $description, callable $handler, string $scope = 'read', array $properties = [], array $required = [], bool $openWorld = false, bool $destructive = false, ?bool $readOnly = null, bool $idempotent = true): array {
		return [
			'name' => $name, 'title' => $title, 'description' => $description,
			'handler' => $handler, 'scope' => $scope, 'read_only' => $readOnly ?? $scope === 'read',
			'destructive' => $destructive, 'idempotent' => $idempotent, 'open_world' => $openWorld,
			'input_schema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass(), 'required' => $required, 'additionalProperties' => false],
		];
	}

	public function mcpFilesStatus(): array {
		$user = $this->mcpActor(false);
		$permissions = [];
		foreach([self::PERMISSION_USE, self::PERMISSION_DOWNLOAD, self::PERMISSION_UPLOAD, self::PERMISSION_FOLDERS, self::PERMISSION_SHARE, self::PERMISSION_DELETE, self::PERMISSION_MANAGE] as $permission) {
			$permissions[$permission] = $user instanceof User && ($permission === self::PERMISSION_USE ? $this->canUse($user) : ($permission === self::PERMISSION_MANAGE ? $this->canManage($user) : $this->hasCapability($permission, $user)));
		}
		return [
			'version' => self::MCP_VERSION,
			'configured' => $user instanceof User && $this->canUse($user),
			'service_user' => $user instanceof User ? ['id' => (int)$user->id, 'name' => (string)$user->name, 'superuser' => $user->isSuperuser()] : null,
			'permissions' => $permissions,
			'counts' => $user instanceof User && $this->canUse($user) ? $this->stats($user) : null,
			'limits' => ['mcp_upload_bytes' => self::MCP_UPLOAD_BYTES, 'content_chunk_bytes' => self::MCP_CONTENT_CHUNK_BYTES],
			'private_paths_exposed' => false,
		];
	}

	public function mcpFilesList(int $folder_id = self::ROOT_ID, string $query = '', string $extension = '', int $limit = 100, int $after_id = 0): array {
		$user = $this->mcpActor(); $limit = max(1, min(200, $limit)); $after_id = max(0, $after_id);
		$folder = $this->item($folder_id, $user); if(!$folder || $folder['kind'] !== 'folder') throw new Wire404Exception('Files folder was not found.');
		$where = ['i.parent_id=:parent', 'i.id>:after']; $params = [':parent' => $folder_id, ':after' => $after_id];
		$query = mb_substr(trim($query), 0, 120); if($query !== '') { $where[] = "i.display_name LIKE :query ESCAPE '='"; $params[':query'] = '%' . $this->mcpLikeLiteral($query) . '%'; }
		$extension = strtolower(substr(trim($extension), 0, 30)); if($extension !== '') { $where[] = '(i.kind=\'folder\' OR i.extension=:extension)'; $params[':extension'] = $extension; }
		if(!$this->canManage($user)) { $where[] = 'i.owner_user_id=:owner'; $params[':owner'] = (int)$user->id; }
		$sql = 'SELECT i.*,(SELECT COUNT(*) FROM `' . self::TABLE_SHARES . '` s WHERE s.item_id=i.id AND ' . $this->activeShareSql('s') . ') active_shares FROM `' . self::TABLE_ITEMS . '` i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.id LIMIT ' . ($limit + 1);
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$bounded = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
		return ['folder' => $this->mcpSafeItem($this->item($folder_id, $user)), 'count' => count($rows), 'items' => array_map(fn(array $row): array => $this->mcpSafeItem($row), $rows), 'next_after_id' => $rows ? (int)end($rows)['id'] : $after_id, 'bounded' => $bounded];
	}

	public function mcpFilesGet(int $item_id): array {
		$user = $this->mcpActor(); $item = $this->item($item_id, $user);
		if(!$item) throw new Wire404Exception('Files item was not found.');
		$privileged = $this->canManage($user) || (int)$item['owner_user_id'] === (int)$user->id;
		$breadcrumbs = $privileged ? $this->breadcrumbs($item_id, $user) : [$item];
		return ['item' => $this->mcpSafeItem($item, $privileged), 'breadcrumbs' => array_map(fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['display_name'], 'kind' => (string)$row['kind']], $breadcrumbs), 'ancestor_names_redacted' => !$privileged];
	}

	public function mcpFilesSearch(string $query, string $kind = 'any', int $limit = 50, int $after_id = 0): array {
		$user = $this->mcpActor(); $query = mb_substr(trim($query), 0, 120); if($query === '') throw new WireException('Enter a search query.');
		$kind = in_array($kind, ['any', 'file', 'folder'], true) ? $kind : 'any'; $limit = max(1, min(100, $limit)); $after_id = max(0, $after_id);
		$where = ['i.id>:after', "i.display_name LIKE :query ESCAPE '='"]; $params = [':after' => $after_id, ':query' => '%' . $this->mcpLikeLiteral($query) . '%'];
		if($kind !== 'any') { $where[] = 'i.kind=:kind'; $params[':kind'] = $kind; }
		if(!$this->canManage($user)) {
			$where[] = '(i.id=' . self::ROOT_ID . ' OR i.owner_user_id=:owner OR EXISTS (SELECT 1 FROM `' . self::TABLE_SHARES . '` s JOIN `' . self::TABLE_TREE . '` t ON t.ancestor_id=s.item_id AND t.descendant_id=i.id WHERE s.recipient_user_id=:recipient AND ' . $this->activeShareSql('s') . '))';
			$params[':owner'] = (int)$user->id; $params[':recipient'] = (int)$user->id;
		}
		$sql = 'SELECT i.* FROM `' . self::TABLE_ITEMS . '` i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.id LIMIT ' . ($limit + 1);
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$hasMore = count($rows) > $limit; $rows = array_slice($rows, 0, $limit); $items = array_map(fn(array $row): array => $this->mcpSafeItem($row, $this->canManage($user) || (int)$row['owner_user_id'] === (int)$user->id), $rows); $last = $rows ? (int)end($rows)['id'] : $after_id;
		return ['query' => $query, 'count' => count($items), 'items' => $items, 'next_after_id' => $last, 'bounded' => $hasMore];
	}

	public function mcpFilesPreviewText(int $item_id, int $max_characters = 50000): array {
		$user = $this->mcpActor(); $max_characters = max(1, min(200000, $max_characters)); $preview = $this->previewText($item_id, $user);
		$content = mb_substr((string)$preview['content'], 0, $max_characters);
		return ['item_id' => $item_id, 'content' => $content, 'truncated' => !empty($preview['truncated']) || mb_strlen((string)$preview['content']) > $max_characters];
	}

	public function mcpFilesReadChunk(int $item_id, int $offset = 0, int $length = self::MCP_CONTENT_CHUNK_BYTES): array {
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_DOWNLOAD, $user); $item = $this->item($item_id, $user);
		if(!$item || $item['kind'] !== 'file') throw new Wire404Exception('Files item was not found.');
		if(!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id) throw new WirePermissionException('Recipient-shared files must use files_read_shared so download limits are preserved.');
		return $this->mcpReadStoredFile($item, max(0, $offset), max(1, min(self::MCP_CONTENT_CHUNK_BYTES, $length)));
	}

	public function mcpFilesSharedWithMe(int $limit = 50, int $after_share_id = 0): array {
		$user = $this->mcpActor(); $limit = max(1, min(100, $limit));
		$stmt = $this->wire('database')->prepare('SELECT s.id share_id,s.permission,s.expires_at,s.download_count,s.max_downloads,i.* FROM `' . self::TABLE_SHARES . '` s JOIN `' . self::TABLE_ITEMS . '` i ON i.id=s.item_id WHERE s.recipient_user_id=:user AND s.id>:after AND ' . $this->activeShareSql('s') . ' ORDER BY s.id LIMIT ' . ($limit + 1));
		$stmt->execute([':user' => (int)$user->id, ':after' => max(0, $after_share_id)]); $source = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []; $bounded = count($source) > $limit; $source = array_slice($source, 0, $limit); $rows = [];
		foreach($source as $row) $rows[] = ['share_id' => (int)$row['share_id'], 'permission' => (string)$row['permission'], 'expires_at' => $row['expires_at'] ?: null, 'download_count' => (int)$row['download_count'], 'max_downloads' => (int)$row['max_downloads'], 'item' => $this->mcpSafeItem($row, false)];
		return ['count' => count($rows), 'shares' => $rows, 'next_after_share_id' => $rows ? (int)end($rows)['share_id'] : max(0, $after_share_id), 'bounded' => $bounded];
	}

	public function mcpFilesSharedContent(int $share_id, int $limit = 100, int $after_id = 0): array {
		$user = $this->mcpActor(); $limit = max(1, min(200, $limit)); $share = $this->shareById($share_id);
		if(!$share || (int)$share['recipient_user_id'] !== (int)$user->id || !$this->shareIsActive($share)) throw new WirePermissionException('The recipient share is unavailable.');
		$target = $this->rawItem((int)$share['item_id']); if(!$target) throw new Wire404Exception('The shared item was not found.');
		if($target['kind'] === 'file') $rows = (int)$target['id'] > $after_id ? [$target] : [];
		else { $stmt = $this->wire('database')->prepare('SELECT i.*,t.depth FROM `' . self::TABLE_TREE . '` t JOIN `' . self::TABLE_ITEMS . '` i ON i.id=t.descendant_id WHERE t.ancestor_id=:folder AND t.depth>0 AND i.id>:after ORDER BY i.id LIMIT ' . ($limit + 1)); $stmt->execute([':folder' => (int)$target['id'], ':after' => max(0, $after_id)]); $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []; }
		$bounded = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
		return ['share_id' => $share_id, 'target' => $this->mcpSafeItem($target, false), 'count' => count($rows), 'items' => array_map(fn(array $row): array => $this->mcpSafeItem($row, false), $rows), 'next_after_id' => $rows ? (int)end($rows)['id'] : max(0, $after_id), 'bounded' => $bounded];
	}

	public function mcpFilesReadShared(int $share_id, int $item_id): array {
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_DOWNLOAD, $user); $share = $this->shareById($share_id);
		if(!$share || (int)$share['recipient_user_id'] !== (int)$user->id || !$this->shareIsActive($share)) throw new WirePermissionException('The recipient share is unavailable.');
		$target = $this->rawItem((int)$share['item_id']);
		if(!$target || ($target['kind'] === 'file' ? (int)$target['id'] !== $item_id : !$this->isDescendant((int)$target['id'], $item_id))) throw new Wire404Exception('The file is outside this share.');
		$item = $this->rawItem($item_id); if(!$item || $item['kind'] !== 'file') throw new Wire404Exception('Shared file was not found.');
		if((int)$item['size_bytes'] > self::MCP_CONTENT_CHUNK_BYTES) throw new WireException('Recipient-shared MCP downloads are limited to 512 KiB so one tool call maps to one download.');
		$result = $this->mcpReadStoredFile($item, 0, self::MCP_CONTENT_CHUNK_BYTES, false);
		if(!$this->claimDownload($share)) throw new WireException('The share download limit has been reached.');
		return $result + ['share_id' => $share_id, 'download_counted' => true];
	}

	public function mcpFilesShares(int $item_id, int $limit = 50, int $after_share_id = 0): array {
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_SHARE, $user); $item = $this->item($item_id, $user);
		if(!$item || (!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id)) throw new WirePermissionException('You cannot inspect shares for this item.');
		$limit = max(1, min(100, $limit)); $stmt = $this->wire('database')->prepare('SELECT s.id,s.item_id,s.recipient_user_id,s.permission,(s.password_hash<>\'\') password_protected,s.expires_at,s.max_downloads,s.download_count,s.revoked_at,s.created_at,s.last_download_at FROM `' . self::TABLE_SHARES . '` s WHERE s.item_id=:item AND s.id>:after ORDER BY s.id LIMIT ' . ($limit + 1)); $stmt->execute([':item' => $item_id, ':after' => max(0, $after_share_id)]); $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []; $bounded = count($rows) > $limit; $rows = array_slice($rows, 0, $limit); $shares = [];
		foreach($rows as $share) { $recipient = (int)$share['recipient_user_id'] > 0 ? $this->wire('users')->get((int)$share['recipient_user_id']) : null; $share['recipient_name'] = $recipient instanceof User && $recipient->id ? (string)$recipient->name : ''; $shares[] = $this->mcpSafeShare($share); }
		return ['item_id' => $item_id, 'count' => count($shares), 'shares' => $shares, 'next_after_share_id' => $rows ? (int)end($rows)['id'] : max(0, $after_share_id), 'bounded' => $bounded, 'link_secrets_exposed' => false];
	}

	public function mcpFilesRecipients(string $query = '', int $limit = 50, int $after_id = 0): array {
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_SHARE, $user); $query = mb_strtolower(mb_substr(trim($query), 0, 120)); $limit = max(1, min(100, $limit)); $items = []; $scan = max(0, $after_id); $scanned = 0; $batchSize = 100;
		do {
			$batch = $this->wire('users')->find('id>' . $scan . ', include=all, sort=id, limit=' . $batchSize); if(!count($batch)) break;
			foreach($batch as $candidate) {
				$scan = (int)$candidate->id; $scanned++; if(!$this->activeAccount($candidate) || !$this->canUse($candidate)) continue;
			$label = trim((string)$candidate->get('title')) ?: (string)$candidate->name;
			if($query !== '' && !str_contains(mb_strtolower((string)$candidate->name . ' ' . $label), $query)) continue;
			$items[] = ['id' => (int)$candidate->id, 'name' => (string)$candidate->name, 'label' => $label];
			if(count($items) >= $limit) break 2;
			}
		} while(count($batch) === $batchSize && $scanned < 1000);
		return ['count' => count($items), 'users' => $items, 'next_after_id' => $scan, 'more_possible' => count($items) === $limit || $scanned >= 1000, 'scan_limit_reached' => $scanned >= 1000, 'email_addresses_exposed' => false];
	}

	public function mcpFilesCreateFolder(int $parent_id, string $name, string $idempotency_key): array {
		$user = $this->mcpActor(); $digest = $this->mcpDigest([$parent_id, $name, (int)$user->id]);
		return $this->mcpIdempotent('create_folder', $idempotency_key, $digest, function() use($parent_id, $name, $user): array {
			return ['item' => $this->mcpSafeItem($this->createFolder($parent_id, $name, $user))];
		});
	}

	public function mcpFilesUpload(int $folder_id, string $filename, string $content_base64, string $checksum_sha256, string $idempotency_key): array {
		$user = $this->mcpActor(); $content = base64_decode($content_base64, true);
		if(!is_string($content) || $content === '' || strlen($content) > self::MCP_UPLOAD_BYTES) throw new WireException('MCP uploads must contain between 1 byte and 1 MiB of valid base64 data.');
		if(!hash_equals($checksum_sha256, hash('sha256', $content))) throw new WireException('The uploaded content checksum does not match.');
		$digest = $this->mcpDigest([$folder_id, $filename, $checksum_sha256, (int)$user->id]);
		return $this->mcpIdempotent('upload', $idempotency_key, $digest, function() use($folder_id, $filename, $content, $user): array {
			return ['item' => $this->mcpSafeItem($this->storeContent($filename, $content, $folder_id, $user))];
		});
	}

	public function mcpFilesStageShare(int $item_id, int $recipient_user_id, int $days, int $max_downloads, string $idempotency_key, string $password = ''): array {
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_SHARE, $user); $item = $this->item($item_id, $user);
		if(!$item || (!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id)) throw new WirePermissionException('You cannot share this item.');
		$maximumDays = max(1, (int)$this->max_share_days); if($days < 1 || $days > $maximumDays) throw new WireException('Share days exceed the configured Files limit.');
		if($max_downloads < 0 || $max_downloads > 1000000) throw new WireException('The share download limit is invalid.');
		if($password !== '' && (strlen($password) < 8 || strlen($password) > 128)) throw new WireException('Share passwords must contain 8 to 128 characters.');
		$recipient = null; if($recipient_user_id > 0) { $recipient = $this->wire('users')->get($recipient_user_id); if(!$this->activeAccount($recipient) || !$this->canUse($recipient)) throw new WireException('Choose an active Files user as recipient.'); }
		if(!preg_match('/^[A-Za-z0-9._:-]{8,191}$/', $idempotency_key)) throw new WireException('Enter a valid idempotency key.');
		$actorId = (int)$user->id; $fingerprint = $this->mcpSecretFingerprint($password); $digest = $this->mcpSecretDigest([$item_id, $recipient_user_id, $days, $max_downloads, $fingerprint, $actorId]);
		$proposalId = substr(hash_hmac('sha256', 'Files share proposal|' . $actorId . '|' . $idempotency_key, (string)$this->wire('config')->userAuthSalt), 0, 32); $db = $this->wire('database');
		$db->exec('DELETE FROM `' . self::TABLE_MCP_SHARE_PROPOSALS . '` WHERE (published_share_id IS NULL AND expires_at<NOW()) OR (published_share_id IS NOT NULL AND created_at<DATE_SUB(NOW(),INTERVAL 1 YEAR)) LIMIT 100');
		$stmt = $db->prepare('SELECT * FROM `' . self::TABLE_MCP_SHARE_PROPOSALS . '` WHERE proposal_id=:id'); $stmt->execute([':id' => $proposalId]); $proposal = $stmt->fetch(\PDO::FETCH_ASSOC);
		if($proposal) {
			if(!hash_equals((string)$proposal['arguments_digest'], $digest)) throw new WireException('The idempotency key was already used with different share input.');
			if(strtotime((string)$proposal['expires_at']) <= time() && empty($proposal['published_share_id'])) throw new WireException('The staged share proposal expired; stage a new proposal with a new idempotency key.');
			return $this->mcpShareProposalView($proposal, $item, $recipient, true);
		}
		$created = date('Y-m-d H:i:s'); $expires = date('Y-m-d H:i:s', time() + 3600); $revision = $this->mcpItemRevision($item);
		$stmt = $db->prepare('INSERT INTO `' . self::TABLE_MCP_SHARE_PROPOSALS . '` (proposal_id,actor_user_id,item_id,item_revision,recipient_user_id,days,max_downloads,password_fingerprint,arguments_digest,published_share_id,created_at,expires_at) VALUES (:id,:actor,:item,:revision,:recipient,:days,:maximum,:password,:digest,NULL,:created,:expires)');
		$stmt->execute([':id' => $proposalId, ':actor' => $actorId, ':item' => $item_id, ':revision' => $revision, ':recipient' => $recipient_user_id, ':days' => $days, ':maximum' => $max_downloads, ':password' => $fingerprint, ':digest' => $digest, ':created' => $created, ':expires' => $expires]);
		$proposal = ['proposal_id' => $proposalId, 'actor_user_id' => $actorId, 'item_id' => $item_id, 'item_revision' => $revision, 'recipient_user_id' => $recipient_user_id, 'days' => $days, 'max_downloads' => $max_downloads, 'password_fingerprint' => $fingerprint, 'arguments_digest' => $digest, 'published_share_id' => null, 'created_at' => $created, 'expires_at' => $expires];
		return $this->mcpShareProposalView($proposal, $item, $recipient, false);
	}

	public function mcpFilesCreateShare(string $proposal_id, string $confirmation, string $password = ''): array {
		if($confirmation !== 'CREATE_FILES_SHARE') throw new WireException('Explicit share confirmation is required.');
		$user = $this->mcpActor(); $this->requireCapability(self::PERMISSION_SHARE, $user); $db = $this->wire('database'); $db->beginTransaction();
		try {
			$stmt = $db->prepare('SELECT * FROM `' . self::TABLE_MCP_SHARE_PROPOSALS . '` WHERE proposal_id=:id AND actor_user_id=:actor' . $this->mcpSelectForUpdate()); $stmt->execute([':id' => $proposal_id, ':actor' => (int)$user->id]); $proposal = $stmt->fetch(\PDO::FETCH_ASSOC);
			if(!$proposal) throw new Wire404Exception('The staged share proposal was not found.');
			if(!empty($proposal['published_share_id'])) { $shareId = (int)$proposal['published_share_id']; $db->commit(); return ['share' => $this->mcpShareResult($shareId, $user), 'idempotent_replay' => true]; }
			if(strtotime((string)$proposal['expires_at']) <= time()) throw new WireException('The staged share proposal has expired.');
			if((int)$proposal['days'] > max(1, (int)$this->max_share_days)) throw new WireException('The Files share policy changed after review; stage a new proposal.');
			$item = $this->item((int)$proposal['item_id'], $user); if(!$item || !hash_equals((string)$proposal['item_revision'], $this->mcpItemRevision($item))) throw new WireException('The item changed after review; stage a new share proposal.');
			if(!hash_equals((string)$proposal['password_fingerprint'], $this->mcpSecretFingerprint($password))) throw new WireException('The share password does not match the staged proposal.');
			$share = $this->createShare((int)$proposal['item_id'], ['recipient_user_id' => (int)$proposal['recipient_user_id'], 'days' => (int)$proposal['days'], 'max_downloads' => (int)$proposal['max_downloads'], 'password' => $password], $user); $shareId = (int)$share['id'];
			$update = $db->prepare('UPDATE `' . self::TABLE_MCP_SHARE_PROPOSALS . '` SET published_share_id=:share WHERE proposal_id=:id AND published_share_id IS NULL'); $update->execute([':share' => $shareId, ':id' => $proposal_id]); if($update->rowCount() !== 1) throw new WireException('The staged share proposal could not be finalized.');
			$db->commit(); return ['share' => $this->mcpShareResult($shareId, $user), 'idempotent_replay' => false];
		} catch(\Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
	}

	public function mcpFilesRevokeShare(int $share_id, string $confirmation): array {
		if($confirmation !== 'REVOKE_FILES_SHARE') throw new WireException('Explicit revocation confirmation is required.');
		$user = $this->mcpActor(); return ['share_id' => $share_id, 'revoked' => $this->revokeShare($share_id, $user)];
	}

	public function mcpFilesDeleteFile(int $item_id, string $checksum_sha256, string $confirmation): array {
		if($confirmation !== 'DELETE_FILES_FILE_PERMANENTLY') throw new WireException('Explicit permanent deletion confirmation is required.');
		$user = $this->mcpActor(); $item = $this->item($item_id, $user);
		if(!$item) return ['item_id' => $item_id, 'deleted' => false, 'already_absent' => true];
		if($item['kind'] !== 'file' || !hash_equals((string)$item['checksum_sha256'], $checksum_sha256)) throw new WireException('The file checksum does not match.');
		return ['item_id' => $item_id, 'deleted' => $this->deleteFile($item_id, $user), 'already_absent' => false];
	}

	private function mcpActor(bool $required = true): ?User {
		$id = max(0, (int)$this->mcp_service_user_id); $user = $id > 0 ? $this->wire('users')->get($id) : null;
		if(!$this->activeAccount($user) || !$this->canUse($user)) {
			if($required) throw new WirePermissionException('Files MCP is disabled until a valid service user with files-use is configured.');
			return null;
		}
		return $user;
	}

	private function mcpSelectForUpdate(): string {
		return $this->wire('database')->dialect()->name() === 'sqlite' ? '' : ' FOR UPDATE';
	}

	/** @return array<string,mixed> */
	private function mcpSafeItem(array $item, bool $privileged = true): array {
		$safe = [
			'id' => (int)($item['id'] ?? 0), 'parent_id' => (int)($item['parent_id'] ?? 0), 'kind' => (string)($item['kind'] ?? ''),
			'name' => (string)($item['display_name'] ?? ''),
			'mime_type' => (string)($item['mime_type'] ?? ''), 'extension' => (string)($item['extension'] ?? ''),
			'size_bytes' => (int)($item['size_bytes'] ?? 0),
			'preview_kind' => $this->previewKindForItem($item), 'active_shares' => isset($item['active_shares']) ? (int)$item['active_shares'] : null,
			'created_at' => (string)($item['created_at'] ?? ''), 'updated_at' => (string)($item['updated_at'] ?? ''),
		];
		if($privileged) { $safe['owner_user_id'] = (int)($item['owner_user_id'] ?? 0); $safe['checksum_sha256'] = (string)($item['checksum_sha256'] ?? ''); }
		return $safe;
	}

	/** @return array<string,mixed> */
	private function mcpSafeShare(array $share, bool $includeUrls = false): array {
		$safe = [
			'id' => (int)$share['id'], 'item_id' => (int)$share['item_id'], 'recipient_user_id' => (int)$share['recipient_user_id'],
			'recipient_name' => (string)($share['recipient_name'] ?? ''), 'permission' => (string)$share['permission'],
			'password_protected' => (bool)$share['password_protected'], 'expires_at' => $share['expires_at'] ?: null,
			'max_downloads' => (int)$share['max_downloads'], 'download_count' => (int)$share['download_count'],
			'revoked_at' => $share['revoked_at'] ?: null, 'created_at' => (string)$share['created_at'], 'last_download_at' => $share['last_download_at'] ?: null,
		];
		if($includeUrls) { $safe['url'] = (string)($share['url'] ?? ''); $safe['direct_url'] = (string)($share['direct_url'] ?? ''); }
		return $safe;
	}

	/** @return array<string,mixed> */
	private function mcpReadStoredFile(array $item, int $offset, int $length, bool $privileged = true): array {
		$path = $this->storageRoot() . (string)$item['storage_name']; if(!is_file($path)) throw new Wire404Exception('Stored file was not found.');
		$size = (int)$item['size_bytes']; if($offset > $size) throw new WireException('The content offset is beyond the end of the file.');
		$handle = fopen($path, 'rb'); if($handle === false || fseek($handle, $offset) !== 0) { if(is_resource($handle)) fclose($handle); throw new WireException('The stored file could not be read.'); }
		$content = (string)fread($handle, $length); fclose($handle); $next = $offset + strlen($content);
		return ['item' => $this->mcpSafeItem($item, $privileged), 'encoding' => 'base64', 'offset' => $offset, 'length' => strlen($content), 'content_base64' => base64_encode($content), 'next_offset' => $next, 'eof' => $next >= $size];
	}

	private function mcpLikeLiteral(string $value): string { return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value); }

	private function mcpDigest(array $value): string { return hash('sha256', (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
	private function mcpSecretDigest(array $value): string { return hash_hmac('sha256', (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string)$this->wire('config')->userAuthSalt); }
	private function mcpSecretFingerprint(string $value): string { return hash_hmac('sha256', 'Files MCP secret|' . $value, (string)$this->wire('config')->userAuthSalt); }
	private function mcpItemRevision(array $item): string { return hash_hmac('sha256', 'Files item revision|' . (int)$item['id'] . '|' . (string)$item['updated_at'] . '|' . (string)$item['checksum_sha256'], (string)$this->wire('config')->userAuthSalt); }

	/** @return array<string,mixed> */
	private function mcpShareProposalView(array $proposal, array $item, ?User $recipient, bool $replay): array {
		if($item['kind'] === 'file') { $fileCount = 1; $bytes = (int)$item['size_bytes']; }
		else { $stmt = $this->wire('database')->prepare('SELECT COUNT(*) files,COALESCE(SUM(i.size_bytes),0) bytes FROM `' . self::TABLE_TREE . '` t JOIN `' . self::TABLE_ITEMS . '` i ON i.id=t.descendant_id WHERE t.ancestor_id=:item AND t.depth>0 AND i.kind=\'file\''); $stmt->execute([':item' => (int)$item['id']]); $counts = $stmt->fetch(\PDO::FETCH_ASSOC) ?: []; $fileCount = (int)($counts['files'] ?? 0); $bytes = (int)($counts['bytes'] ?? 0); }
		return [
			'proposal_id' => (string)$proposal['proposal_id'], 'expires_at' => (string)$proposal['expires_at'],
			'item' => $this->mcpSafeItem($item), 'live_subtree' => $item['kind'] === 'folder', 'current_file_count' => $fileCount, 'current_size_bytes' => $bytes,
			'recipient' => (int)$proposal['recipient_user_id'] > 0 ? ['user_id' => (int)$proposal['recipient_user_id'], 'name' => $recipient instanceof User ? (string)$recipient->name : ''] : ['user_id' => 0, 'name' => 'public_link'],
			'days' => (int)$proposal['days'], 'max_downloads' => (int)$proposal['max_downloads'], 'password_protected' => !hash_equals($this->mcpSecretFingerprint(''), (string)$proposal['password_fingerprint']),
			'published' => !empty($proposal['published_share_id']), 'idempotent_replay' => $replay,
		];
	}

	/** @return array<string,mixed> */
	private function mcpShareResult(int $shareId, User $user): array {
		$this->requireCapability(self::PERMISSION_SHARE, $user); $share = $this->shareById($shareId); if(!$share) throw new Wire404Exception('The Files share was not found.'); $item = $this->item((int)$share['item_id'], $user);
		if(!$item || (!$this->canManage($user) && (int)$share['created_by'] !== (int)$user->id)) throw new WirePermissionException('You cannot read this share.');
		$recipient = (int)$share['recipient_user_id'] > 0 ? $this->wire('users')->get((int)$share['recipient_user_id']) : null; $share['recipient_name'] = $recipient instanceof User && $recipient->id ? (string)$recipient->name : '';
		$share['password_protected'] = (string)$share['password_hash'] !== ''; $token = $this->decryptShareToken((string)$share['token_ciphertext'], (string)$share['public_id']);
		$share['url'] = $token !== '' ? $this->shareUrl((string)$share['public_id'], $token) : ''; $share['direct_url'] = $token !== '' ? $this->shareDirectUrl((string)$share['public_id'], $token) : '';
		return $this->mcpSafeShare($share, true);
	}

	/** @return array<string,mixed> */
	private function mcpIdempotent(string $operation, string $key, string $digest, callable $callback): array {
		if(!preg_match('/^[A-Za-z0-9._:-]{8,191}$/', $key)) throw new WireException('Enter a valid idempotency key.');
		$actorId = (int)$this->mcpActor()->id; $lock = 'files_mcp_' . substr(hash('sha256', $operation . '|' . $actorId . '|' . $key), 0, 48); $db = $this->wire('database');
		$lockStmt = $db->prepare('SELECT GET_LOCK(:lock_name,10)'); $lockStmt->execute([':lock_name' => $lock]);
		if((int)$lockStmt->fetchColumn() !== 1) throw new WireException('The MCP operation is already running.');
		try {
			$db->exec('DELETE FROM `' . self::TABLE_MCP_OPERATIONS . '` WHERE expires_at<NOW() LIMIT 100');
			$stmt = $db->prepare('SELECT arguments_digest,state,result_json FROM `' . self::TABLE_MCP_OPERATIONS . '` WHERE operation=:operation AND actor_user_id=:actor AND idempotency_key=:key'); $stmt->execute([':operation' => $operation, ':actor' => $actorId, ':key' => $key]); $stored = $stmt->fetch(\PDO::FETCH_ASSOC);
			if($stored) {
				if(!hash_equals((string)$stored['arguments_digest'], $digest)) throw new WireException('The idempotency key was already used with different input.');
				if((string)$stored['state'] !== 'completed') throw new WireException('A previous operation with this idempotency key did not finalize. Review the target before choosing a new key.');
				$result = json_decode((string)$stored['result_json'], true, 32, JSON_THROW_ON_ERROR); $result['idempotent_replay'] = true; return $result;
			}
			$created = date('Y-m-d H:i:s'); $reserve = $db->prepare('INSERT INTO `' . self::TABLE_MCP_OPERATIONS . '` (operation,actor_user_id,idempotency_key,arguments_digest,state,result_json,created_at,expires_at) VALUES (:operation,:actor,:key,:digest,\'pending\',\'\',:created,:expires)');
			$reserve->execute([':operation' => $operation, ':actor' => $actorId, ':key' => $key, ':digest' => $digest, ':created' => $created, ':expires' => date('Y-m-d H:i:s', time() + 31536000)]);
			$result = $callback();
			$stmt = $db->prepare('UPDATE `' . self::TABLE_MCP_OPERATIONS . '` SET state=\'completed\',result_json=:result WHERE operation=:operation AND actor_user_id=:actor AND idempotency_key=:key AND state=\'pending\'');
			$stmt->execute([':operation' => $operation, ':actor' => $actorId, ':key' => $key, ':result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]); if($stmt->rowCount() !== 1) throw new WireException('The MCP idempotency record could not be finalized.');
			$result['idempotent_replay'] = false; return $result;
		} finally {
			$release = $db->prepare('SELECT RELEASE_LOCK(:lock_name)'); $release->execute([':lock_name' => $lock]);
		}
	}

	private function mcpConfigurationMarkup(): string {
		$gateway = $this->wire('modules')->isInstalled('McpServer'); $user = $this->mcpActor(false); $ready = $gateway && $user instanceof User;
		return '<div class="FilesPermissionInfo"><p><strong>' . $this->h($ready ? $this->_('Ready for MCPServer discovery') : $this->_('Not ready')) . '</strong></p><p>' . $this->h($gateway ? $this->_('MCP Server is installed. Files contributes bounded read, upload, folder, sharing, and deletion tools.') : $this->_('MCP Server is not installed on this site. Files remains fully usable without it.')) . '</p><p>' . $this->h($user instanceof User ? sprintf($this->_('Service user: %s. Its Files permissions and ownership are enforced for every call.'), (string)$user->name) : $this->_('Choose a non-guest service user with files-use to enable Files tool execution.')) . '</p></div>';
	}
}
