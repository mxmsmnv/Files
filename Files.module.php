<?php namespace ProcessWire;

require_once __DIR__ . '/src/FilesMcpProviderTrait.php';

/** Private hierarchical file storage and controlled sharing for ProcessWire. */
class Files extends WireData implements Module, ConfigurableModule {
	use FilesMcpProviderTrait;

	public const VERSION = 100;
	public const TABLE_ITEMS = 'files_items';
	public const TABLE_TREE = 'files_tree';
	public const TABLE_SHARES = 'files_shares';
	public const TABLE_MCP_OPERATIONS = 'files_mcp_operations';
	public const TABLE_MCP_SHARE_PROPOSALS = 'files_mcp_share_proposals';
	public const PERMISSION_USE = 'files-use';
	public const PERMISSION_DOWNLOAD = 'files-download';
	public const PERMISSION_UPLOAD = 'files-upload';
	public const PERMISSION_FOLDERS = 'files-folders';
	public const PERMISSION_SHARE = 'files-share';
	public const PERMISSION_DELETE = 'files-delete';
	public const PERMISSION_MANAGE = 'files-manage';
	public const ROOT_ID = 1;
	private const MAX_DEPTH = 20;
	private const MAX_CHUNK_BYTES = 5242880;
	private const CHUNK_SESSION_TTL = 86400;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Files',
			'summary' => 'Store files in folders and share files or complete folder trees.',
			'version' => 101,
			'author' => 'Maxim Semenov',
			'icon' => 'folder-open',
			'autoload' => true,
			'singular' => true,
			'mcpProvider' => true,
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1'],
			'installs' => ['ProcessFiles'],
		];
	}

	public static function getDefaultConfig(): array {
		return [
			'allowed_extensions' => 'jpg jpeg png gif webp svg pdf txt md csv json xml doc docx xls xlsx ppt pptx odt ods odp zip 7z mp3 wav m4a mp4 mov webm',
			'max_upload_mb' => 100, 'public_path' => '/files/share/',
			'storage_path' => '', 'default_share_days' => 14, 'max_share_days' => 365,
			'onlyoffice_url' => '', 'onlyoffice_jwt_secret' => '',
			'mcp_service_user_id' => 0,
		];
	}

	public function __construct() {
		parent::__construct();
		foreach(self::getDefaultConfig() as $key => $value) $this->set($key, $value);
	}

	public function init(): void {
		$path = $this->normalizedPublicPath();
		$this->addHook($path . 'onlyoffice/{file}/{expires}/{signature}/?', $this, 'handleOnlyOfficeSource');
		$this->addHook($path . '{id}/{token}/download/{file}/?', $this, 'handleFolderDownload');
		$this->addHook($path . '{id}/{token}/?', $this, 'handleShareRequest');
	}

	public function ___install(): void {
		$this->createSchema();
		$this->installPermissions();
		$this->ensureStorageDirectory();
		$this->migrateLegacyConfig();
		$this->migrateLegacyData();
	}

	public function ___upgrade($fromVersion, $toVersion): void {
		$this->createSchema();
		$this->installPermissions();
	}

	/** Stored bytes and tables are intentionally retained on uninstall. */
	public function ___uninstall(): void {}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper {
		$config = $this->wire('config');
		$config->styles->add($config->urls->Files . 'assets/files-config.css?v=' . self::VERSION);

		$fieldset = function(string $name, string $label, string $icon, string $description, bool $expanded = true): InputfieldWrapper {
			/** @var InputfieldWrapper $field */
			$field = $this->wire('modules')->get('InputfieldFieldset');
			$field->name = 'files_config_' . $name;
			$field->label = $label;
			$field->icon = $icon;
			$field->description = $description;
			$field->collapsed = $expanded ? Inputfield::collapsedNo : Inputfield::collapsedYes;
			$field->addClass('FilesConfigSection' . ($expanded ? ' FilesConfigSection--expanded' : ''), 'wrapClass');
			return $field;
		};

		$overview = $this->wire('modules')->get('InputfieldMarkup');
		$overview->name = '_files_config_overview';
		$overview->label = $this->_('Files settings');
		$overview->icon = 'folder-open';
		$overview->description = $this->_('Configure what can be uploaded, where private bytes are stored, and how long sharing links may remain active.');
		$overview->addClass('FilesConfigOverview', 'wrapClass');
		$overview->value = $this->configOverviewMarkup();
		$inputfields->add($overview);

		$uploads = $fieldset(
			'uploads',
			$this->_('Uploads'),
			'upload',
			$this->_('Control the file types and per-file size accepted by the Files library.')
		);
		$server = $this->wire('modules')->get('InputfieldMarkup');
		$server->name = '_files_server_information';
		$server->label = $this->_('Server information');
		$server->icon = 'server';
		$server->description = $this->_('Live runtime and storage diagnostics that affect uploads, downloads, and recoverable sharing links.');
		$server->notes = $this->_('Read-only information. Values are collected from the current web request and are not saved with the module configuration.');
		$server->collapsed = Inputfield::collapsedYes;
		$server->addClass('FilesConfigServer', 'wrapClass');
		$server->value = $this->serverInformationMarkup();
		$extensions = $this->wire('modules')->get('InputfieldTextarea');
		$extensions->name = 'allowed_extensions'; $extensions->label = $this->_('Allowed file extensions');
		$extensions->description = $this->_('Only files whose final filename extension appears in this list can be uploaded. Enter extensions without a leading dot, separated by spaces or commas.');
		$extensions->notes = $this->_('Example: pdf docx jpg. This is an upload allow-list, not a content converter; Files also records the detected MIME type and always serves managed files as downloads.');
		$extensions->rows = 4; $extensions->value = (string)$this->allowed_extensions; $uploads->add($extensions);
		$max = $this->wire('modules')->get('InputfieldInteger');
		$max->name = 'max_upload_mb'; $max->label = $this->_('Maximum upload size (MB)');
		$max->description = $this->_('Maximum size accepted by Files for one uploaded file.');
		$max->notes = sprintf($this->_('AJAX uploads can use the full Files limit and currently send chunks of up to %s. Without JavaScript, the conventional form is limited to %s by PHP.'),$this->formatBytes($this->chunkUploadSize()),$this->formatBytes($this->effectiveUploadLimit()));
		$max->min = 1; $max->max = 2048; $max->value = (int)$this->max_upload_mb; $max->columnWidth = 50; $uploads->add($max);
		$inputfields->add($uploads);

		$sharing = $fieldset(
			'sharing',
			$this->_('Sharing links'),
			'share-alt',
			$this->_('Define the public route and the lifetime boundaries used when users create file or folder shares.')
		);
		$public = $this->wire('modules')->get('InputfieldText');
		$public->name = 'public_path'; $public->label = $this->_('Public sharing path');
		$public->description = $this->_('URL path used for public file and folder links. It must begin and end with a slash.');
		$public->notes = sprintf($this->_('Current link prefix: %s. Changing this path makes previously copied sharing URLs stop working.'), rtrim((string)$config->urls->httpRoot, '/') . $this->normalizedPublicPath());
		$public->required = true;
		$public->value = (string)$this->public_path;
		$sharing->add($public);
		foreach(['default_share_days' => $this->_('Default share lifetime (days)'), 'max_share_days' => $this->_('Maximum share lifetime (days)')] as $name => $label) {
			$field = $this->wire('modules')->get('InputfieldInteger');
			$field->name = $name; $field->label = $label; $field->min = 1; $field->max = $name === 'max_share_days' ? 3650 : 365;
			if($name === 'default_share_days') {
				$field->description = $this->_('Preselected lifetime when a user opens the sharing form.');
				$field->notes = $this->_('Users may choose a shorter or longer period, but never longer than the maximum configured beside it.');
			} else {
				$field->description = $this->_('Hard upper limit for newly created sharing links.');
				$field->notes = $this->_('Changing this value does not shorten, extend, or revoke links that already exist.');
			}
			$field->value = (int)$this->get($name); $field->columnWidth = 50; $sharing->add($field);
		}
		$inputfields->add($sharing);

		$onlyOffice = $fieldset(
			'onlyoffice',
			$this->_('ONLYOFFICE preview'),
			'file-word-o',
			$this->_('Connect an ONLYOFFICE Document Server to render office documents inside the private Files workspace.'),
			false
		);
		$onlyOfficeUrl = $this->wire('modules')->get('InputfieldURL');
		$onlyOfficeUrl->name = 'onlyoffice_url'; $onlyOfficeUrl->label = $this->_('Document Server URL');
		$onlyOfficeUrl->description = $this->_('Base HTTPS URL of your ONLYOFFICE Docs installation, without the API script path.');
		$onlyOfficeUrl->notes = $this->_('Example: https://office.example.com. The Document Server must be able to resolve and reach this ProcessWire site. Leave empty to disable office previews.');
		$onlyOfficeUrl->value = (string)$this->onlyoffice_url; $onlyOffice->add($onlyOfficeUrl);
		$onlyOfficeSecret = $this->wire('modules')->get('InputfieldText');
		$onlyOfficeSecret->name = 'onlyoffice_jwt_secret'; $onlyOfficeSecret->label = $this->_('JWT secret');
		$onlyOfficeSecret->description = $this->_('Shared HMAC secret configured in ONLYOFFICE Docs for browser, inbox, and outbox tokens.');
		$onlyOfficeSecret->notes = $this->_('Use a long random value. It signs editor configuration and short-lived source URLs; it is never sent to the browser.');
		$onlyOfficeSecret->attr('type', 'password'); $onlyOfficeSecret->attr('autocomplete', 'new-password');
		$onlyOfficeSecret->value = (string)$this->onlyoffice_jwt_secret; $onlyOffice->add($onlyOfficeSecret);
		$inputfields->add($onlyOffice);

		$access = $fieldset(
			'access',
			$this->_('Access control'),
			'key',
			$this->_('Assign independent Files capabilities to ProcessWire roles.'),
			false
		);
		$permissionInfo = $this->wire('modules')->get('InputfieldMarkup');
		$permissionInfo->name = '_files_permission_information';
		$permissionInfo->label = $this->_('Permission model');
		$permissionInfo->description = $this->_('Every user needs files-use to enter the workspace. Add only the operational capabilities that the role should receive.');
		$permissionInfo->notes = $this->_('files-manage grants every Files capability and access to all owners’ items. Grant operational permissions explicitly to each role that should upload, download, organize, share, or delete files.');
		$permissionInfo->value = $this->permissionInformationMarkup();
		$access->add($permissionInfo);
		$inputfields->add($access);

		$mcp = $fieldset(
			'mcp',
			$this->_('MCP Server'),
			'plug',
			$this->_('Expose bounded Files tools through the authenticated MCP Server gateway.'),
			false
		);
		$mcpUser = $this->wire('modules')->get('InputfieldSelect');
		$mcpUser->name = 'mcp_service_user_id';
		$mcpUser->label = $this->_('MCP service user');
		$mcpUser->description = $this->_('Every Files MCP tool runs as this ProcessWire user and is limited by that user’s Files permissions and item ownership.');
		$mcpUser->notes = $this->_('Leave disabled until MCP Server is installed and its endpoint, client scopes, and audit policy have been reviewed. Use a dedicated least-privilege account; avoid a superuser.');
		$mcpUser->addOption(0, $this->_('Disabled'));
		foreach($this->wire('users')->find('id>0, include=all, sort=name, limit=500') as $candidate) {
			if(!$this->activeAccount($candidate)) continue;
			$label = (string)$candidate->name;
			if((string)$candidate->get('title') !== '') $label .= ' — ' . (string)$candidate->get('title');
			if($candidate->isSuperuser()) $label .= ' (' . $this->_('superuser — not recommended') . ')';
			$mcpUser->addOption((int)$candidate->id, $label);
		}
		$mcpUser->value = (int)$this->mcp_service_user_id;
		$mcp->add($mcpUser);
		$mcpStatus = $this->wire('modules')->get('InputfieldMarkup');
		$mcpStatus->name = '_files_mcp_status';
		$mcpStatus->label = $this->_('Integration status');
		$mcpStatus->value = $this->mcpConfigurationMarkup();
		$mcp->add($mcpStatus);
		$inputfields->add($mcp);

		$storageSection = $fieldset(
			'storage',
			$this->_('Private storage'),
			'database',
			$this->_('Choose the server directory where original file bytes are kept outside the public document root.')
		);
		$storage = $this->wire('modules')->get('InputfieldText');
		$storage->name = 'storage_path'; $storage->label = $this->_('Private storage path');
		$storage->description = $this->_('Use an absolute, writable server path. Leave empty to use the safe default directory beside the ProcessWire document root.');
		$storage->notes = sprintf($this->_('Resolved path: %s. Existing files are not moved automatically when this setting changes; move their bytes first or existing downloads will fail.'), $this->storageRoot());
		$storage->value = (string)$this->storage_path;
		$storageSection->add($storage);
		$inputfields->add($storageSection);
		$inputfields->add($server);
		return $inputfields;
	}

	public function canUse(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $this->activeAccount($user) && ($user->isSuperuser() || $user->hasPermission(self::PERMISSION_MANAGE) || $user->hasPermission(self::PERMISSION_USE));
	}

	public function canManage(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $this->activeAccount($user) && ($user->isSuperuser() || $user->hasPermission(self::PERMISSION_MANAGE));
	}
	public function canDownload(?User $user = null): bool { return $this->hasCapability(self::PERMISSION_DOWNLOAD, $user); }
	public function canUpload(?User $user = null): bool { return $this->hasCapability(self::PERMISSION_UPLOAD, $user); }
	public function canCreateFolders(?User $user = null): bool { return $this->hasCapability(self::PERMISSION_FOLDERS, $user); }
	public function canShare(?User $user = null): bool { return $this->hasCapability(self::PERMISSION_SHARE, $user); }
	public function canDelete(?User $user = null): bool { return $this->hasCapability(self::PERMISSION_DELETE, $user); }

	public function rootFolder(?User $actor = null): array {
		$this->requireUse($actor);
		return $this->rawItem(self::ROOT_ID);
	}

	public function item(int $id, ?User $actor = null): array {
		$this->requireUse($actor);
		$item = $this->rawItem($id);
		if(!$item || !$this->canAccessItem($item, $actor ?: $this->wire('user'))) return [];
		return $item;
	}

	public function children(int $folderId, array $filters = [], ?User $actor = null): array {
		$this->requireUse($actor); $user = $actor ?: $this->wire('user');
		$folder = $this->rawItem($folderId);
		if(!$folder || $folder['kind'] !== 'folder' || !$this->canAccessItem($folder, $user)) throw new Wire404Exception($this->_('Folder not found.'));
		$where = ['i.parent_id=:parent']; $params = [':parent' => $folderId];
		$q = trim((string)($filters['q'] ?? ''));
		if($q !== '') { $where[] = 'i.display_name LIKE :q'; $params[':q'] = '%' . $q . '%'; }
		$type = strtolower(trim((string)($filters['type'] ?? '')));
		if($type !== '') { $where[] = '(i.kind=\'folder\' OR i.extension=:type)'; $params[':type'] = $type; }
		if(!$this->canManage($user)) { $where[] = 'i.owner_user_id=:owner'; $params[':owner'] = (int)$user->id; }
		$sql = 'SELECT i.*, (SELECT COUNT(*) FROM `' . self::TABLE_SHARES . '` s WHERE s.item_id=i.id AND ' . $this->activeShareSql('s') . ') AS active_shares FROM `' . self::TABLE_ITEMS . '` i WHERE ' . implode(' AND ', $where) . ' ORDER BY i.kind=\'folder\' DESC, i.normalized_name ASC LIMIT 500';
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function breadcrumbs(int $itemId, ?User $actor = null): array {
		$this->requireUse($actor); if(!$this->item($itemId, $actor)) return [];
		$stmt = $this->wire('database')->prepare('SELECT i.* FROM `' . self::TABLE_TREE . '` t JOIN `' . self::TABLE_ITEMS . '` i ON i.id=t.ancestor_id WHERE t.descendant_id=:id ORDER BY t.depth DESC');
		$stmt->execute([':id' => $itemId]); return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function createFolder(int $parentId, string $name, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_FOLDERS, $actor); $user = $actor ?: $this->wire('user'); $parent = $this->item($parentId, $user);
		if(!$parent || $parent['kind'] !== 'folder') throw new Wire404Exception($this->_('Parent folder not found.'));
		if(!$this->canManage($user) && (int)$parent['owner_user_id'] !== (int)$user->id && $parentId !== self::ROOT_ID) throw new WirePermissionException($this->_('You cannot add items to this folder.'));
		return $this->createFolderInternal($parentId, $name, (int)$user->id);
	}

	public function storeUpload(array $upload, int $folderId = self::ROOT_ID, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_UPLOAD, $actor); $user = $actor ?: $this->wire('user'); $folder = $this->item($folderId, $user);
		if(!$folder || $folder['kind'] !== 'folder') throw new Wire404Exception($this->_('Folder not found.'));
		if(!$this->canManage($user) && (int)$folder['owner_user_id'] !== (int)$user->id && $folderId !== self::ROOT_ID) throw new WirePermissionException($this->_('You cannot upload to this folder.'));
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE); if($error !== UPLOAD_ERR_OK) throw new WireException($this->uploadError($error));
		$tmp = (string)($upload['tmp_name'] ?? ''); if($tmp === '' || !is_uploaded_file($tmp)) throw new WireException($this->_('The upload could not be verified.'));
		$name = $this->cleanItemName((string)($upload['name'] ?? 'file'), true); $size = (int)($upload['size'] ?? filesize($tmp));
		if($size < 1 || $size > max(1, (int)$this->max_upload_mb) * 1048576) throw new WireException($this->_('The file exceeds the configured size limit.'));
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION)); if(!$this->extensionAllowed($extension)) throw new WireException($this->_('This file type is not allowed.'));
		$normalized = $this->normalizedName($name); if($this->nameExists($folderId, $normalized)) throw new WireException($this->_('An item with this name already exists in the folder.'));
		$storageName = date('Y/m') . '/' . $this->randomUrlToken(32) . ($extension !== '' ? '.' . $extension : ''); $destination = $this->storageRoot() . $storageName;
		if(!is_dir(dirname($destination)) && !wireMkdir(dirname($destination), true)) throw new WireException($this->_('The private storage directory could not be created.'));
		if(!move_uploaded_file($tmp, $destination)) throw new WireException($this->_('The uploaded file could not be moved into private storage.')); @chmod($destination, 0600);
		try { return $this->registerStoredFile($destination,$storageName,$name,$normalized,$extension,$size,$folderId,$user); }
		catch(\Throwable $e) { @unlink($destination); throw $e; }
	}

	public function storeContent(string $name, string $content, int $folderId = self::ROOT_ID, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_UPLOAD, $actor); $user = $actor ?: $this->wire('user'); $folder = $this->item($folderId, $user);
		if(!$folder || $folder['kind'] !== 'folder') throw new Wire404Exception($this->_('Folder not found.'));
		if(!$this->canManage($user) && (int)$folder['owner_user_id'] !== (int)$user->id && $folderId !== self::ROOT_ID) throw new WirePermissionException($this->_('You cannot upload to this folder.'));
		$name = $this->cleanItemName($name, true); $size = strlen($content);
		if($size < 1 || $size > max(1, (int)$this->max_upload_mb) * 1048576) throw new WireException($this->_('The file exceeds the configured size limit.'));
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION)); if(!$this->extensionAllowed($extension)) throw new WireException($this->_('This file type is not allowed.'));
		$normalized = $this->normalizedName($name); if($this->nameExists($folderId, $normalized)) throw new WireException($this->_('An item with this name already exists in the folder.'));
		$storageName = date('Y/m') . '/' . $this->randomUrlToken(32) . ($extension !== '' ? '.' . $extension : ''); $destination = $this->storageRoot() . $storageName;
		if(!is_dir(dirname($destination)) && !wireMkdir(dirname($destination), true)) throw new WireException($this->_('The private storage directory could not be created.'));
		if(file_put_contents($destination, $content, LOCK_EX) !== $size) { @unlink($destination); throw new WireException($this->_('The file could not be written to private storage.')); } @chmod($destination, 0600);
		try { return $this->registerStoredFile($destination,$storageName,$name,$normalized,$extension,$size,$folderId,$user); }
		catch(\Throwable $e) { @unlink($destination); throw $e; }
	}

	/** Start a private, user-bound browser upload and return its chunk policy. */
	public function beginChunkedUpload(string $name, int $size, int $folderId = self::ROOT_ID, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_UPLOAD, $actor); $user = $actor ?: $this->wire('user');
		[$folder,$name,$normalized,$extension] = $this->validateUploadTarget($folderId,$name,$size,$user);
		$this->ensureStorageDirectory(); $this->cleanupChunkSessions(); $root=$this->chunkSessionRoot();
		if(!is_dir($root)&&!wireMkdir($root,true)) throw new WireException($this->_('The temporary upload directory could not be created.')); @chmod($root,0700);
		$id=$this->randomUrlToken(24);$meta=['owner_user_id'=>(int)$user->id,'folder_id'=>(int)$folder['id'],'name'=>$name,'normalized'=>$normalized,'extension'=>$extension,'size'=>$size,'created_at'=>time()];
		$json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false||file_put_contents($this->chunkMetaPath($id),$json,LOCK_EX)===false)throw new WireException($this->_('The upload session could not be created.'));
		@chmod($this->chunkMetaPath($id),0600);$handle=@fopen($this->chunkPartPath($id),'x+b');if($handle===false){@unlink($this->chunkMetaPath($id));throw new WireException($this->_('The upload session could not be created.'));}fclose($handle);@chmod($this->chunkPartPath($id),0600);
		return ['upload_id'=>$id,'chunk_size'=>$this->chunkUploadSize(),'offset'=>0,'total_size'=>$size];
	}

	/** Append exactly one sequential browser chunk and finalize on the last byte. */
	public function appendChunkedUpload(string $uploadId, int $offset, array $chunk, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_UPLOAD, $actor);$user=$actor?:$this->wire('user');$meta=$this->chunkSession($uploadId,$user);$error=(int)($chunk['error']??UPLOAD_ERR_NO_FILE);
		if($error!==UPLOAD_ERR_OK)throw new WireException($this->uploadError($error));$tmp=(string)($chunk['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new WireException($this->_('The upload chunk could not be verified.'));
		$chunkSize=(int)($chunk['size']??filesize($tmp));$limit=$this->chunkUploadSize();if($chunkSize<1||$chunkSize>$limit)throw new WireException($this->_('The upload chunk exceeds the server limit.'));
		$part=$this->chunkPartPath($uploadId);$target=@fopen($part,'c+b');$source=@fopen($tmp,'rb');if($target===false||$source===false){if(is_resource($target))fclose($target);if(is_resource($source))fclose($source);throw new WireException($this->_('The upload chunk could not be opened.'));}
		$locked=false;try{$locked=flock($target,LOCK_EX);if(!$locked)throw new WireException($this->_('The upload is busy. Please retry.'));$stat=fstat($target);$current=(int)($stat['size']??0);$total=(int)$meta['size'];if($offset!==$current){if($offset>=0&&$offset+$chunkSize<=$current)$next=$current;else throw new WireException(sprintf($this->_('Upload offset mismatch. Expected %d bytes.'),$current));}else{if($current+$chunkSize>$total)throw new WireException($this->_('The upload contains more bytes than expected.'));fseek($target,0,SEEK_END);$written=stream_copy_to_stream($source,$target);if($written!==$chunkSize)throw new WireException($this->_('The upload chunk could not be stored.'));fflush($target);if(function_exists('fsync'))fsync($target);$next=$current+$chunkSize;}}finally{fclose($source);if($locked)flock($target,LOCK_UN);fclose($target);}
		if($next<(int)$meta['size'])return ['complete'=>false,'offset'=>$next,'total_size'=>(int)$meta['size']];
		$folderId=(int)$meta['folder_id'];$this->validateUploadTarget($folderId,(string)$meta['name'],(int)$meta['size'],$user);$storageName=date('Y/m').'/'.$this->randomUrlToken(32).((string)$meta['extension']!==''?'.'.$meta['extension']:'');$destination=$this->storageRoot().$storageName;
		if(!is_dir(dirname($destination))&&!wireMkdir(dirname($destination),true))throw new WireException($this->_('The private storage directory could not be created.'));if(!rename($part,$destination))throw new WireException($this->_('The completed upload could not be moved into private storage.'));@chmod($destination,0600);
		try{$item=$this->registerStoredFile($destination,$storageName,(string)$meta['name'],(string)$meta['normalized'],(string)$meta['extension'],(int)$meta['size'],$folderId,$user);}catch(\Throwable $e){@rename($destination,$part);throw $e;}@unlink($this->chunkMetaPath($uploadId));return ['complete'=>true,'offset'=>$next,'total_size'=>(int)$meta['size'],'item'=>$item];
	}

	public function cancelChunkedUpload(string $uploadId, ?User $actor = null): bool {
		$this->requireCapability(self::PERMISSION_UPLOAD,$actor);$user=$actor?:$this->wire('user');$this->chunkSession($uploadId,$user);$part=$this->chunkPartPath($uploadId);$meta=$this->chunkMetaPath($uploadId);$removed=true;if(is_file($part)&&!unlink($part))$removed=false;if(is_file($meta)&&!unlink($meta))$removed=false;return$removed;
	}

	/** Safe per-request chunk size derived from PHP request limits. */
	public function chunkUploadSize(): int {
		$limit=self::MAX_CHUNK_BYTES;foreach([(string)ini_get('upload_max_filesize'),(string)ini_get('post_max_size')]as$value){$bytes=$this->iniBytes($value);if($bytes>0)$limit=min($limit,max(65536,(int)floor($bytes*.75)));}return max(65536,$limit);
	}

	public function createShare(int $itemId, array $options = [], ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_SHARE, $actor); $user = $actor ?: $this->wire('user'); $item = $this->item($itemId, $user);
		if(!$item) throw new Wire404Exception($this->_('Item not found.'));
		if(!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id) throw new WirePermissionException($this->_('You cannot share this item.'));
		$days = max(1, min(max(1, (int)$this->max_share_days), (int)($options['days'] ?? $this->default_share_days))); $maxDownloads = max(0, min(1000000, (int)($options['max_downloads'] ?? 0))); $password = (string)($options['password'] ?? '');
		if($password !== '' && strlen($password) < 8) throw new WireException($this->_('Share passwords must contain at least 8 characters.'));
		$recipientId = max(0, (int)($options['recipient_user_id'] ?? 0));
		if($recipientId > 0) { $recipient = $this->wire('users')->get($recipientId); if(!$this->activeAccount($recipient)) throw new WireException($this->_('Choose an active ProcessWire user.')); }
		$publicId = $this->randomUrlToken(15); $token = $this->randomUrlToken(32); $now = date('Y-m-d H:i:s');
		$stmt = $this->wire('database')->prepare('INSERT INTO `' . self::TABLE_SHARES . '` (item_id,created_by,recipient_user_id,permission,public_id,token_hash,token_ciphertext,token_hint,password_hash,expires_at,max_downloads,download_count,created_at) VALUES (:item,:actor,:recipient,\'read\',:public,:hash,:ciphertext,:hint,:password,:expires,:maximum,0,:created)');
		$stmt->execute([':item'=>$itemId, ':actor'=>(int)$user->id, ':recipient'=>$recipientId, ':public'=>$publicId, ':hash'=>hash('sha256',$token), ':ciphertext'=>$this->encryptShareToken($token,$publicId), ':hint'=>substr($token,-6), ':password'=>$password === '' ? '' : password_hash($password,PASSWORD_DEFAULT), ':expires'=>date('Y-m-d H:i:s',time()+$days*86400), ':maximum'=>$maxDownloads, ':created'=>$now]);
		return ['id'=>(int)$this->wire('database')->lastInsertId(), 'item_id'=>$itemId, 'url'=>$this->shareUrl($publicId,$token), 'direct_url'=>$this->shareDirectUrl($publicId,$token), 'recipient_user_id'=>$recipientId];
	}

	public function sharesForItem(int $itemId, ?User $actor = null): array {
		$this->requireCapability(self::PERMISSION_SHARE, $actor);
		$item = $this->item($itemId, $actor); if(!$item) return []; $user = $actor ?: $this->wire('user');
		if(!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id) throw new WirePermissionException($this->_('You cannot manage shares for this item.'));
		$stmt = $this->wire('database')->prepare('SELECT s.id,s.item_id,s.created_by,s.recipient_user_id,s.permission,s.public_id,s.token_ciphertext,s.token_hint,(s.password_hash<>\'\') AS password_protected,s.expires_at,s.max_downloads,s.download_count,s.revoked_at,s.created_at,s.last_download_at FROM `' . self::TABLE_SHARES . '` s WHERE s.item_id=:item ORDER BY s.created_at DESC');
		$stmt->execute([':item'=>$itemId]); $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		foreach($shares as &$share) {
			$recipient = (int)$share['recipient_user_id'] > 0 ? $this->wire('users')->get((int)$share['recipient_user_id']) : null;
			$share['recipient_name'] = $recipient && $recipient->id ? (string)$recipient->name : '';
			$share['recipient_email'] = $recipient && $recipient->id ? (string)$recipient->email : '';
			$token = $this->decryptShareToken((string)$share['token_ciphertext'], (string)$share['public_id']);
			$share['url'] = $token !== '' ? $this->shareUrl((string)$share['public_id'],$token) : '';
			$share['direct_url'] = $token !== '' ? $this->shareDirectUrl((string)$share['public_id'],$token) : '';
			unset($share['token_ciphertext']);
		}
		unset($share);
		return $shares;
	}

	public function sharedWithUser(?User $actor = null): array {
		$this->requireUse($actor); $user = $actor ?: $this->wire('user');
		$stmt = $this->wire('database')->prepare('SELECT s.id AS share_id,s.permission,s.expires_at,s.download_count,s.max_downloads,i.* FROM `' . self::TABLE_SHARES . '` s JOIN `' . self::TABLE_ITEMS . '` i ON i.id=s.item_id WHERE s.recipient_user_id=:user AND ' . $this->activeShareSql('s') . ' ORDER BY s.created_at DESC');
		$stmt->execute([':user'=>(int)$user->id]); return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function revokeShare(int $id, ?User $actor = null): bool {
		$this->requireCapability(self::PERMISSION_SHARE, $actor); $share = $this->shareById($id); if(!$share) return false; $item = $this->item((int)$share['item_id'], $actor); if(!$item) return false; $user = $actor ?: $this->wire('user');
		if(!$this->canManage($user) && (int)$share['created_by'] !== (int)$user->id) throw new WirePermissionException();
		$stmt = $this->wire('database')->prepare('UPDATE `' . self::TABLE_SHARES . '` SET revoked_at=:now WHERE id=:id AND revoked_at IS NULL'); $stmt->execute([':now'=>date('Y-m-d H:i:s'), ':id'=>$id]); return $stmt->rowCount() > 0;
	}

	public function deleteFile(int $id, ?User $actor = null): bool {
		$this->requireCapability(self::PERMISSION_DELETE, $actor);$item = $this->item($id, $actor); if(!$item || $item['kind'] !== 'file') return false; $user = $actor ?: $this->wire('user'); if(!$this->canManage($user) && (int)$item['owner_user_id'] !== (int)$user->id) throw new WirePermissionException();
		$path = $this->storageRoot() . (string)$item['storage_name']; $quarantine = $path . '.deleting-' . bin2hex(random_bytes(4)); if(is_file($path) && !rename($path, $quarantine)) throw new WireException($this->_('The stored file could not be prepared for deletion.'));
		$db = $this->wire('database'); $db->beginTransaction();
		try { $db->prepare('DELETE FROM `' . self::TABLE_SHARES . '` WHERE item_id=:id')->execute([':id'=>$id]); $db->prepare('DELETE FROM `' . self::TABLE_TREE . '` WHERE descendant_id=:id OR ancestor_id=:id')->execute([':id'=>$id]); $db->prepare('DELETE FROM `' . self::TABLE_ITEMS . '` WHERE id=:id')->execute([':id'=>$id]); $db->commit(); }
		catch(\Throwable $e) { $db->rollBack(); if(is_file($quarantine)) @rename($quarantine,$path); throw $e; }
		if(is_file($quarantine)) @unlink($quarantine); return true;
	}

	public function stats(?User $actor = null): array {
		$this->requireUse($actor); $user = $actor ?: $this->wire('user'); $where = $this->canManage($user) ? '1=1' : 'owner_user_id=' . (int)$user->id;
		$row = $this->wire('database')->query('SELECT SUM(kind=\'file\') files,COALESCE(SUM(size_bytes),0) bytes,SUM(kind=\'folder\')-' . ($this->canManage($user) ? '1' : '0') . ' folders FROM `' . self::TABLE_ITEMS . '` WHERE ' . $where)->fetch(\PDO::FETCH_ASSOC);
		$shareSql = 'SELECT COUNT(*) FROM `' . self::TABLE_SHARES . '` s';
		if(!$this->canManage($user)) $shareSql .= ' JOIN `' . self::TABLE_ITEMS . '` i ON i.id=s.item_id';
		$shareSql .= ' WHERE ' . $this->activeShareSql('s') . (!$this->canManage($user) ? ' AND i.owner_user_id=' . (int)$user->id : '');
		$shares = $this->wire('database')->query($shareSql)->fetchColumn();
		return ['files'=>(int)$row['files'], 'bytes'=>(int)$row['bytes'], 'folders'=>max(0,(int)$row['folders']), 'active_shares'=>(int)$shares];
	}

	public function descendants(int $folderId, bool $filesOnly = false): array {
		$sql = 'SELECT i.*,t.depth FROM `' . self::TABLE_TREE . '` t JOIN `' . self::TABLE_ITEMS . '` i ON i.id=t.descendant_id WHERE t.ancestor_id=:folder AND t.depth>0' . ($filesOnly ? ' AND i.kind=\'file\'' : '') . ' ORDER BY t.depth,i.kind=\'folder\' DESC,i.normalized_name';
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute([':folder'=>$folderId]); return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function shareUrl(string $publicId, string $token): string { return rtrim((string)$this->wire('config')->urls->httpRoot,'/') . $this->normalizedPublicPath() . rawurlencode($publicId) . '/' . rawurlencode($token) . '/'; }
	public function shareDirectUrl(string $publicId, string $token): string { return $this->shareUrl($publicId,$token) . '?dl=1'; }

	public function handleShareRequest(HookEvent $event): string {
		$token = (string)$event->arguments('token'); $share = $this->resolveShare((string)$event->arguments('id'), $token); if(isset($share['_response'])) return (string)$share['_response'];
		$item = $this->rawItem((int)$share['item_id']); if(!$item) return $this->publicError(404,$this->_('The shared item is unavailable.')); $passwordResponse = $this->requireSharePassword($share); if($passwordResponse !== null) return $passwordResponse;
		if($item['kind'] === 'file') {
			if((int)$this->wire('input')->get('dl') === 1) { if(!$this->claimDownload($share)) return $this->publicError(410,$this->_('This sharing link is no longer available.')); $this->streamItem($item); return ''; }
			$download=$this->shareDirectUrl((string)$share['public_id'],$token);$body='<p class="FilesPublic-path">'.$this->h($item['display_name']).'</p><p>'.$this->h($this->formatBytes((int)$item['size_bytes'])).' · '.$this->h((string)$item['mime_type']).'</p><a class="FilesPublic-download" href="'.$this->h($download).'">'.$this->_('Download').'</a>';
			return $this->publicPage((string)$item['display_name'],$body);
		}
		$files = $this->descendants((int)$item['id'], true); $base = $this->shareUrl((string)$share['public_id'], $token); $body = '<p class="FilesPublic-path">' . $this->h($item['display_name']) . '</p><ul class="FilesPublic-list">';
		foreach($files as $file) $body .= '<li><span>' . $this->h($this->relativePath((int)$item['id'], (int)$file['id'])) . '</span><small>' . $this->h($this->formatBytes((int)$file['size_bytes'])) . '</small><a href="' . $this->h($base . 'download/' . (int)$file['id'] . '/') . '">' . $this->_('Download') . '</a></li>';
		if(!$files) $body .= '<li>' . $this->_('This folder contains no files.') . '</li>'; return $this->publicPage($this->_('Shared folder'), $body . '</ul>');
	}

	public function handleFolderDownload(HookEvent $event): string {
		$share = $this->resolveShare((string)$event->arguments('id'), (string)$event->arguments('token')); if(isset($share['_response'])) return (string)$share['_response']; $target = $this->rawItem((int)$share['item_id']); $fileId = (int)$event->arguments('file');
		if(!$target || $target['kind'] !== 'folder' || !$this->isDescendant((int)$target['id'],$fileId)) return $this->publicError(404,$this->_('File not found in this shared folder.'));
		$passwordResponse = $this->requireSharePassword($share); if($passwordResponse !== null) return $passwordResponse; $file = $this->rawItem($fileId); if(!$file || $file['kind'] !== 'file') return $this->publicError(404,$this->_('File not found.'));
		if(!$this->claimDownload($share)) return $this->publicError(410,$this->_('This sharing link is no longer available.')); $this->streamItem($file); return '';
	}

	public function streamManagedFile(array $item, ?User $actor = null): void { $this->streamItem($this->managedFileForOwner((int)($item['id']??0),$actor)); }
	public function previewKind(int $itemId, ?User $actor = null): string {
		$item = $this->managedFileForOwner($itemId, $actor);
		return $this->previewKindForItem($item);
	}
	public function previewText(int $itemId, ?User $actor = null): array {
		$item = $this->managedFileForOwner($itemId, $actor);
		if($this->previewKindForItem($item) !== 'text') throw new Wire404Exception($this->_('A text preview is not available for this file.'));
		return $this->readTextPreview($item);
	}

	public function streamManagedPreview(int $itemId, ?User $actor = null): void {
		$item = $this->managedFileForOwner($itemId, $actor);
		$kind = $this->previewKindForItem($item);
		if($kind === '') throw new Wire404Exception($this->_('A browser preview is not available for this file type.'));

		$path = $this->storageRoot() . (string)$item['storage_name'];
		if(!is_file($path)) throw new Wire404Exception($this->_('Stored file not found.'));
		$extension = strtolower((string)$item['extension']);
		$mime = match($extension) {
			'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
			'pdf' => 'application/pdf',
			'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
			'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
			default => 'text/plain; charset=UTF-8',
		};

		while(ob_get_level()) ob_end_clean();
		header('Content-Type: ' . $mime);
		header('Content-Disposition: inline; filename="' . addcslashes($this->asciiFilename((string)$item['display_name']), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode((string)$item['display_name']));
		header('Cache-Control: private, no-store, max-age=0');
		header('Pragma: no-cache');
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: SAMEORIGIN');
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox");
		header('X-Robots-Tag: noindex, nofollow, noarchive');
		if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') exit;

		if($kind === 'text') {
			$preview = $this->readTextPreview($item);
			echo $preview['content'] . ($preview['truncated'] ? "\n\n[Preview truncated at 512 KiB. Download the file to view the rest.]" : '');
			exit;
		}

		header('Content-Length: ' . (int)filesize($path));
		readfile($path);
		exit;
	}
	public function onlyOfficeEditorConfig(int $itemId, ?User $actor = null): array {
		$item = $this->managedFileForOwner($itemId, $actor);
		if(!$this->onlyOfficeEnabled() || !$this->isOfficeItem($item)) throw new WireException($this->_('ONLYOFFICE preview is not configured for this file.'));
		$extension = strtolower((string)$item['extension']);
		$documentType = in_array($extension, ['xls','xlsx','ods'], true) ? 'cell' : (in_array($extension, ['ppt','pptx','odp'], true) ? 'slide' : 'word');
		$expires = time() + 3600;
		$documentUrl = $this->onlyOfficeSourceUrl($item, $expires);
		$user = $actor ?: $this->wire('user');
		$config = [
			'document' => [
				'fileType' => $extension,
				'key' => substr(hash('sha256', (int)$item['id'].'|'.$item['checksum_sha256'].'|'.$item['updated_at']), 0, 40),
				'title' => (string)$item['display_name'],
				'url' => $documentUrl,
				'permissions' => ['edit'=>false, 'download'=>false, 'print'=>true, 'copy'=>true],
			],
			'documentType' => $documentType,
			'editorConfig' => [
				'mode' => 'view',
				'user' => ['id'=>(string)(int)$user->id, 'name'=>(string)($user->get('title') ?: $user->name)],
			],
			'type' => 'desktop',
		];
		$config['token'] = $this->jwtEncode($config, trim((string)$this->onlyoffice_jwt_secret));
		return ['api_url'=>$this->onlyOfficeBaseUrl().'/web-apps/apps/api/documents/api.js', 'config'=>$config];
	}

	public function handleOnlyOfficeSource(HookEvent $event): string {
		$itemId = (int)$event->arguments('file');
		$expires = (int)$event->arguments('expires');
		$signature = strtolower((string)$event->arguments('signature'));
		$item = $this->rawItem($itemId);
		if(!$this->onlyOfficeEnabled() || !$item || !$this->isOfficeItem($item) || $expires < time() - 30 || $expires > time() + 3660) throw new Wire404Exception();
		$expected = $this->onlyOfficeSourceSignature($item, $expires);
		if(!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals($expected, $signature)) throw new Wire404Exception();
		$this->streamItem($item);
		return '';
	}
	public function sharedContent(int $shareId, ?User $actor = null): array { $this->requireUse($actor); $user=$actor?:$this->wire('user');$share=$this->shareById($shareId);if(!$share||(int)$share['recipient_user_id']!==(int)$user->id||!$this->shareIsActive($share))throw new WirePermissionException();$target=$this->rawItem((int)$share['item_id']);if(!$target)throw new Wire404Exception();return['share'=>$share,'target'=>$target,'items'=>$target['kind']==='folder'?$this->descendants((int)$target['id']):[$target]]; }
	public function streamSharedFile(int $shareId, int $fileId, ?User $actor = null): void { $this->requireCapability(self::PERMISSION_DOWNLOAD,$actor); $user = $actor ?: $this->wire('user'); $share = $this->shareById($shareId); if(!$share || (int)$share['recipient_user_id'] !== (int)$user->id || !$this->shareIsActive($share)) throw new WirePermissionException(); $target = $this->rawItem((int)$share['item_id']); if(!$target || ($target['kind'] === 'file' ? (int)$target['id'] !== $fileId : !$this->isDescendant((int)$target['id'],$fileId))) throw new Wire404Exception(); $file = $this->rawItem($fileId); if(!$file || $file['kind'] !== 'file') throw new Wire404Exception(); if(!$this->claimDownload($share)) throw new Wire404Exception(); $this->streamItem($file); }

	private function createSchema(): void {
		$db = $this->wire('database');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_ITEMS . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`parent_id` INT UNSIGNED NOT NULL DEFAULT 0,`kind` ENUM(\'file\',\'folder\') NOT NULL,`owner_user_id` INT UNSIGNED NOT NULL,`display_name` VARCHAR(255) NOT NULL,`normalized_name` VARCHAR(255) NOT NULL,`legacy_file_id` INT UNSIGNED NULL,`storage_name` VARCHAR(255) NULL,`mime_type` VARCHAR(190) NOT NULL DEFAULT \'\',`extension` VARCHAR(30) NOT NULL DEFAULT \'\',`size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,`checksum_sha256` CHAR(64) NOT NULL DEFAULT \'\',`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `parent_name` (`parent_id`,`normalized_name`(190)),UNIQUE KEY `legacy_file_id` (`legacy_file_id`),UNIQUE KEY `storage_name` (`storage_name`),KEY `parent_kind` (`parent_id`,`kind`),KEY `owner_updated` (`owner_user_id`,`updated_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_TREE . '` (`ancestor_id` INT UNSIGNED NOT NULL,`descendant_id` INT UNSIGNED NOT NULL,`depth` TINYINT UNSIGNED NOT NULL,PRIMARY KEY (`ancestor_id`,`descendant_id`),KEY `descendant_depth` (`descendant_id`,`depth`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_SHARES . '` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`item_id` INT UNSIGNED NOT NULL,`created_by` INT UNSIGNED NOT NULL,`recipient_user_id` INT UNSIGNED NOT NULL DEFAULT 0,`permission` ENUM(\'read\',\'read_write\') NOT NULL DEFAULT \'read\',`public_id` CHAR(20) NOT NULL,`token_hash` CHAR(64) NOT NULL,`token_ciphertext` TEXT NULL,`token_hint` CHAR(6) NOT NULL,`password_hash` VARCHAR(255) NOT NULL DEFAULT \'\',`expires_at` DATETIME NULL,`max_downloads` INT UNSIGNED NOT NULL DEFAULT 0,`download_count` INT UNSIGNED NOT NULL DEFAULT 0,`revoked_at` DATETIME NULL,`created_at` DATETIME NOT NULL,`last_download_at` DATETIME NULL,PRIMARY KEY (`id`),UNIQUE KEY `public_id` (`public_id`),KEY `item_created` (`item_id`,`created_at`),KEY `recipient_active` (`recipient_user_id`,`revoked_at`,`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_MCP_OPERATIONS . '` (`operation` VARCHAR(48) NOT NULL,`actor_user_id` INT UNSIGNED NOT NULL,`idempotency_key` VARCHAR(191) NOT NULL,`arguments_digest` CHAR(64) NOT NULL,`state` ENUM(\'pending\',\'completed\') NOT NULL DEFAULT \'pending\',`result_json` TEXT NOT NULL,`created_at` DATETIME NOT NULL,`expires_at` DATETIME NOT NULL,PRIMARY KEY (`operation`,`actor_user_id`,`idempotency_key`),KEY `expires_at` (`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_MCP_SHARE_PROPOSALS . '` (`proposal_id` CHAR(32) NOT NULL,`actor_user_id` INT UNSIGNED NOT NULL,`item_id` INT UNSIGNED NOT NULL,`item_revision` CHAR(64) NOT NULL,`recipient_user_id` INT UNSIGNED NOT NULL DEFAULT 0,`days` SMALLINT UNSIGNED NOT NULL,`max_downloads` INT UNSIGNED NOT NULL DEFAULT 0,`password_fingerprint` CHAR(64) NOT NULL,`arguments_digest` CHAR(64) NOT NULL,`published_share_id` INT UNSIGNED NULL,`created_at` DATETIME NOT NULL,`expires_at` DATETIME NOT NULL,PRIMARY KEY (`proposal_id`),KEY `actor_expiry` (`actor_user_id`,`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$this->ensureShareTokenColumn();
		$root = $db->prepare('INSERT IGNORE INTO `' . self::TABLE_ITEMS . '` (id,parent_id,kind,owner_user_id,display_name,normalized_name,storage_name,created_at,updated_at) VALUES (1,0,\'folder\',0,\'My Library\',\'my library\',NULL,:created,:updated)'); $now=date('Y-m-d H:i:s'); $root->execute([':created'=>$now,':updated'=>$now]); $db->exec('INSERT IGNORE INTO `' . self::TABLE_TREE . '` (ancestor_id,descendant_id,depth) VALUES (1,1,0)');
	}

	private function installPermissions(): void { foreach($this->permissionDefinitions() as $name=>$title) { $permission=$this->wire('permissions')->get($name); if($permission->id)continue; $permission=new Permission(); $permission->name=$name; $permission->title=$title; $permission->save(); } }
	private function migrateLegacyConfig(): void { $modules=$this->wire('modules'); $legacy=(array)$modules->getModuleConfigData('FileServer'); if(!$legacy)return; $current=(array)$modules->getModuleConfigData('Files'); foreach(array_keys(self::getDefaultConfig()) as $key)if(!array_key_exists($key,$current)&&array_key_exists($key,$legacy))$current[$key]=$legacy[$key]; $modules->saveModuleConfigData('Files',$current); }

	private function migrateLegacyData(): void {
		$db=$this->wire('database'); if(!$this->tableExists('fileserver_files'))return; $files=$db->query('SELECT * FROM `fileserver_files` ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)?:[];
		foreach($files as $legacy) { $existing=$db->prepare('SELECT id FROM `' . self::TABLE_ITEMS . '` WHERE legacy_file_id=:id'); $existing->execute([':id'=>(int)$legacy['id']]); if($existing->fetchColumn())continue; $parent=self::ROOT_ID; foreach(array_filter(explode('/',(string)$legacy['folder']))as $part)$parent=$this->legacyFolder((string)$part,$parent,(int)$legacy['owner_user_id']); $name=$this->uniqueLegacyName($parent,(string)$legacy['display_name']); $stmt=$db->prepare('INSERT INTO `' . self::TABLE_ITEMS . '` (parent_id,kind,owner_user_id,display_name,normalized_name,legacy_file_id,storage_name,mime_type,extension,size_bytes,checksum_sha256,created_at,updated_at) VALUES (:parent,\'file\',:owner,:name,:normalized,:legacy,:storage,:mime,:extension,:size,:checksum,:created,:updated)'); $stmt->execute([':parent'=>$parent,':owner'=>(int)$legacy['owner_user_id'],':name'=>$name,':normalized'=>$this->normalizedName($name),':legacy'=>(int)$legacy['id'],':storage'=>$legacy['storage_name'],':mime'=>$legacy['mime_type'],':extension'=>$legacy['extension'],':size'=>(int)$legacy['size_bytes'],':checksum'=>$legacy['checksum_sha256'],':created'=>$legacy['created_at'],':updated'=>$legacy['updated_at']]); $this->insertTreeLinks((int)$db->lastInsertId(),$parent); }
		if(!$this->tableExists('fileserver_shares'))return; $shares=$db->query('SELECT s.*,i.id AS new_item_id FROM `fileserver_shares` s JOIN `' . self::TABLE_ITEMS . '` i ON i.legacy_file_id=s.file_id ORDER BY s.id')->fetchAll(\PDO::FETCH_ASSOC)?:[]; $stmt=$db->prepare('INSERT IGNORE INTO `' . self::TABLE_SHARES . '` (id,item_id,created_by,recipient_user_id,permission,public_id,token_hash,token_hint,password_hash,expires_at,max_downloads,download_count,revoked_at,created_at,last_download_at) VALUES (:id,:item,:created_by,0,\'read\',:public,:token,:hint,:password,:expires,:maximum,:downloads,:revoked,:created,:last)'); foreach($shares as $s)$stmt->execute([':id'=>(int)$s['id'],':item'=>(int)$s['new_item_id'],':created_by'=>(int)$s['created_by'],':public'=>$s['public_id'],':token'=>$s['token_hash'],':hint'=>$s['token_hint'],':password'=>$s['password_hash'],':expires'=>$s['expires_at'],':maximum'=>(int)$s['max_downloads'],':downloads'=>(int)$s['download_count'],':revoked'=>$s['revoked_at'],':created'=>$s['created_at'],':last'=>$s['last_download_at']]);
	}

	private function legacyFolder(string $name,int $parent,int $owner): int { $normalized=$this->normalizedName($name); $stmt=$this->wire('database')->prepare('SELECT id FROM `' . self::TABLE_ITEMS . '` WHERE parent_id=:parent AND kind=\'folder\' AND normalized_name=:name'); $stmt->execute([':parent'=>$parent,':name'=>$normalized]); $id=(int)$stmt->fetchColumn(); return $id ?: (int)$this->createFolderInternal($parent,$name,$owner)['id']; }
	private function uniqueLegacyName(int $parent,string $name): string { $base=$this->cleanItemName($name,true); $candidate=$base; $n=1; while($this->nameExists($parent,$this->normalizedName($candidate))){$ext=pathinfo($base,PATHINFO_EXTENSION);$stem=$ext?substr($base,0,-strlen($ext)-1):$base;$candidate=$stem.' ('.++$n.')'.($ext?'.'.$ext:'');}return $candidate; }
	private function createFolderInternal(int $parentId,string $name,int $owner): array { $name=$this->cleanItemName($name,false);$normalized=$this->normalizedName($name);if($this->nameExists($parentId,$normalized))throw new WireException($this->_('An item with this name already exists in the folder.'));$depth=$this->wire('database')->prepare('SELECT MAX(depth) FROM `' . self::TABLE_TREE . '` WHERE descendant_id=:parent');$depth->execute([':parent'=>$parentId]);if((int)$depth->fetchColumn()>=self::MAX_DEPTH)throw new WireException($this->_('The maximum folder depth has been reached.'));$now=date('Y-m-d H:i:s');$db=$this->wire('database');$db->beginTransaction();try{$stmt=$db->prepare('INSERT INTO `' . self::TABLE_ITEMS . '` (parent_id,kind,owner_user_id,display_name,normalized_name,storage_name,created_at,updated_at) VALUES (:parent,\'folder\',:owner,:name,:normalized,NULL,:created,:updated)');$stmt->execute([':parent'=>$parentId,':owner'=>$owner,':name'=>$name,':normalized'=>$normalized,':created'=>$now,':updated'=>$now]);$id=(int)$db->lastInsertId();$this->insertTreeLinks($id,$parentId);$db->commit();}catch(\Throwable $e){$db->rollBack();throw $e;}return $this->rawItem($id); }
	private function insertTreeLinks(int $id,int $parent): void { $stmt=$this->wire('database')->prepare('INSERT INTO `' . self::TABLE_TREE . '` (ancestor_id,descendant_id,depth) SELECT ancestor_id,:child_a,depth+1 FROM `' . self::TABLE_TREE . '` WHERE descendant_id=:parent UNION ALL SELECT :child_b,:child_c,0');$stmt->execute([':child_a'=>$id,':parent'=>$parent,':child_b'=>$id,':child_c'=>$id]); }

	private function canAccessItem(array $item,User $user): bool { if($this->canManage($user)||(int)$item['id']===self::ROOT_ID||(int)$item['owner_user_id']===(int)$user->id)return true;$stmt=$this->wire('database')->prepare('SELECT 1 FROM `' . self::TABLE_SHARES . '` s JOIN `' . self::TABLE_TREE . '` t ON t.ancestor_id=s.item_id AND t.descendant_id=:item WHERE s.recipient_user_id=:user AND '.$this->activeShareSql('s').' LIMIT 1');$stmt->execute([':item'=>(int)$item['id'],':user'=>(int)$user->id]);return(bool)$stmt->fetchColumn(); }
	private function resolveShare(string $publicId,string $token): array { if(!preg_match('/^[A-Za-z0-9_-]{15,30}$/',$publicId)||!preg_match('/^[A-Za-z0-9_-]{32,60}$/',$token))return['_response'=>$this->publicError(404,$this->_('This sharing link is invalid.'))];$stmt=$this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_SHARES . '` WHERE public_id=:public');$stmt->execute([':public'=>$publicId]);$share=$stmt->fetch(\PDO::FETCH_ASSOC)?:[];if(!$share||!hash_equals((string)$share['token_hash'],hash('sha256',$token)))return['_response'=>$this->publicError(404,$this->_('This sharing link is invalid.'))];if(!$this->shareIsActive($share))return['_response'=>$this->publicError(410,$this->_('This sharing link is no longer available.'))];if((int)$share['recipient_user_id']>0){$user=$this->wire('user');if(!$user->isLoggedin()||(int)$user->id!==(int)$share['recipient_user_id'])return['_response'=>$this->publicError(403,$this->_('Sign in as the selected recipient to open this share.'))];}return $share; }
	private function requireSharePassword(array $share): ?string { if((string)$share['password_hash']===''||$this->sharePasswordGranted((int)$share['id']))return null;$session=$this->wire('session');$key='files_share_attempts_'.(int)$share['id'];$attempts=(array)$session->get($key);$now=time();$attempts=array_values(array_filter($attempts,static fn($time)=>(int)$time>$now-900));$error='';if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST'){if(count($attempts)>=10)return $this->publicError(429,$this->_('Too many password attempts. Try again later.'));if(!$session->CSRF->validate())return $this->publicError(403,$this->_('The request could not be verified.'));$password=(string)$this->wire('input')->post('share_password');if(password_verify($password,(string)$share['password_hash'])){$session->set('files_share_grant_'.(int)$share['id'],hash_hmac('sha256',(string)$share['token_hash'],(string)$this->wire('config')->userAuthSalt));return null;}$attempts[]=$now;$session->set($key,$attempts);$error='<p class="FilesPublic-error">'.$this->_('Incorrect password.').'</p>';}$csrfName=$session->CSRF->getTokenName();$csrfValue=$session->CSRF->getTokenValue();return $this->publicPage($this->_('Protected share'),$error.'<form method="post"><label for="share-password">'.$this->_('Password').'</label><input id="share-password" type="password" name="share_password" required autofocus><input type="hidden" name="'.$this->h($csrfName).'" value="'.$this->h($csrfValue).'"><button type="submit">'.$this->_('Open share').'</button></form>'); }
	private function sharePasswordGranted(int $id): bool { $share=$this->shareById($id);if(!$share)return false;$grant=(string)$this->wire('session')->get('files_share_grant_'.$id);return hash_equals(hash_hmac('sha256',(string)$share['token_hash'],(string)$this->wire('config')->userAuthSalt),$grant); }
	private function shareById(int $id): array { $stmt=$this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_SHARES . '` WHERE id=:id');$stmt->execute([':id'=>$id]);return$stmt->fetch(\PDO::FETCH_ASSOC)?:[]; }
	private function shareIsActive(array $s): bool { return empty($s['revoked_at'])&&(empty($s['expires_at'])||strtotime((string)$s['expires_at'])>time())&&((int)$s['max_downloads']===0||(int)$s['download_count']<(int)$s['max_downloads']); }
	private function activeShareSql(string $a): string { return "$a.revoked_at IS NULL AND ($a.expires_at IS NULL OR $a.expires_at>NOW()) AND ($a.max_downloads=0 OR $a.download_count<$a.max_downloads)"; }
	private function claimDownload(array $s): bool { if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='HEAD')return true;$stmt=$this->wire('database')->prepare('UPDATE `' . self::TABLE_SHARES . '` SET download_count=download_count+1,last_download_at=:now WHERE id=:id AND '.$this->activeShareSql('files_shares'));$stmt->execute([':now'=>date('Y-m-d H:i:s'),':id'=>(int)$s['id']]);return$stmt->rowCount()===1; }
	private function isDescendant(int $ancestor,int $descendant): bool { $stmt=$this->wire('database')->prepare('SELECT 1 FROM `' . self::TABLE_TREE . '` WHERE ancestor_id=:ancestor AND descendant_id=:descendant AND depth>0');$stmt->execute([':ancestor'=>$ancestor,':descendant'=>$descendant]);return(bool)$stmt->fetchColumn(); }
	private function ensureShareTokenColumn(): void { if($this->columnExists(self::TABLE_SHARES,'token_ciphertext'))return;$this->wire('database')->exec('ALTER TABLE `' . self::TABLE_SHARES . '` ADD `token_ciphertext` TEXT NULL AFTER `token_hash`'); }
	private function columnExists(string $table,string $column): bool { $stmt=$this->wire('database')->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :column');$stmt->execute([':column'=>$column]);return(bool)$stmt->fetchColumn(); }
	private function encryptShareToken(string $token,string $publicId): string { if(!function_exists('sodium_crypto_secretbox'))throw new WireException($this->_('The Sodium PHP extension is required to create recoverable sharing links.'));$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$key=hash_hmac('sha256','Files share token|'.$publicId,(string)$this->wire('config')->userAuthSalt,true);$binary=$nonce.sodium_crypto_secretbox($token,$nonce,$key);return's1:'.rtrim(strtr(base64_encode($binary),'+/','-_'),'='); }
	private function decryptShareToken(string $ciphertext,string $publicId): string { if($ciphertext===''||!str_starts_with($ciphertext,'s1:')||!function_exists('sodium_crypto_secretbox_open'))return'';$encoded=substr($ciphertext,3);$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);$binary=base64_decode(strtr($encoded,'-_','+/'),true);if($binary===false||strlen($binary)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return'';$nonce=substr($binary,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$key=hash_hmac('sha256','Files share token|'.$publicId,(string)$this->wire('config')->userAuthSalt,true);$token=sodium_crypto_secretbox_open(substr($binary,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);return is_string($token)?$token:''; }

	private function streamItem(array $item): void { $path=$this->storageRoot().(string)$item['storage_name'];if($item['kind']!=='file'||!is_file($path))throw new Wire404Exception($this->_('Stored file not found.'));while(ob_get_level())ob_end_clean();header('Content-Type: '.((string)$item['mime_type']?:'application/octet-stream'));header('Content-Disposition: attachment; filename="'.addcslashes($this->asciiFilename((string)$item['display_name']),'"\\').'"; filename*=UTF-8\'\''.rawurlencode((string)$item['display_name']));header('Content-Length: '.(int)$item['size_bytes']);header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='HEAD')readfile($path);exit; }
	private function managedFileForOwner(int $itemId, ?User $actor = null): array { $this->requireCapability(self::PERMISSION_DOWNLOAD,$actor);$user=$actor?:$this->wire('user');$item=$this->rawItem($itemId);if(!$item||$item['kind']!=='file')throw new Wire404Exception();if(!$this->canManage($user)&&(int)$item['owner_user_id']!==(int)$user->id)throw new WirePermissionException();return$item; }
	private function previewKindForItem(array $item): string { $extension=strtolower((string)($item['extension']??''));$mime=strtolower((string)($item['mime_type']??''));if($this->onlyOfficeEnabled()&&$this->isOfficeItem($item))return'office';if(in_array($extension,['jpg','jpeg','png','gif','webp'],true)&&str_starts_with($mime,'image/'))return'image';if($extension==='pdf'&&$mime==='application/pdf')return'pdf';if(in_array($extension,['txt','md','csv','json','xml'],true)&&(str_starts_with($mime,'text/')||in_array($mime,['application/json','application/xml','text/json'],true)))return'text';if(in_array($extension,['mp3','wav','m4a'],true)&&str_starts_with($mime,'audio/'))return'audio';if(in_array($extension,['mp4','webm','mov'],true)&&str_starts_with($mime,'video/'))return'video';return''; }
	private function readTextPreview(array $item): array { $path=$this->storageRoot().(string)$item['storage_name'];if(!is_file($path))throw new Wire404Exception($this->_('Stored file not found.'));$limit=524288;$handle=fopen($path,'rb');if($handle===false)throw new Wire404Exception($this->_('Stored file not found.'));$content=(string)fread($handle,$limit+1);fclose($handle);$truncated=strlen($content)>$limit;if($truncated)$content=substr($content,0,$limit);if(function_exists('mb_check_encoding')&&!mb_check_encoding($content,'UTF-8'))$content=mb_convert_encoding($content,'UTF-8','UTF-8, Windows-1252, ISO-8859-1');return['content'=>$content,'truncated'=>$truncated]; }
	private function onlyOfficeEnabled(): bool { return $this->onlyOfficeBaseUrl()!==''&&trim((string)$this->onlyoffice_jwt_secret)!==''; }
	private function onlyOfficeBaseUrl(): string { $url=rtrim(trim((string)$this->onlyoffice_url),'/');return filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)?$url:''; }
	private function isOfficeItem(array $item): bool { return ($item['kind']??'')==='file'&&in_array(strtolower((string)($item['extension']??'')),['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'],true); }
	private function onlyOfficeSourceUrl(array $item,int $expires): string { $base=rtrim((string)$this->wire('config')->urls->httpRoot,'/').$this->normalizedPublicPath();return$base.'onlyoffice/'.(int)$item['id'].'/'.$expires.'/'.$this->onlyOfficeSourceSignature($item,$expires).'/'; }
	private function onlyOfficeSourceSignature(array $item,int $expires): string { return hash_hmac('sha256','Files ONLYOFFICE|'.(int)$item['id'].'|'.$expires.'|'.(string)$item['checksum_sha256'],hash_hmac('sha256',trim((string)$this->onlyoffice_jwt_secret),(string)$this->wire('config')->userAuthSalt)); }
	private function jwtEncode(array $payload,string $secret): string { $encode=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');$header=$encode('{"alg":"HS256","typ":"JWT"}');$body=$encode((string)json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return$header.'.'.$body.'.'.$encode(hash_hmac('sha256',$header.'.'.$body,$secret,true)); }
	private function relativePath(int $root,int $item): string { $stmt=$this->wire('database')->prepare('SELECT i.display_name FROM `' . self::TABLE_TREE . '` t JOIN `' . self::TABLE_TREE . '` scope ON scope.ancestor_id=:root AND scope.descendant_id=t.ancestor_id JOIN `' . self::TABLE_ITEMS . '` i ON i.id=t.ancestor_id WHERE t.descendant_id=:item AND t.ancestor_id<>:root ORDER BY t.depth DESC');$stmt->execute([':item'=>$item,':root'=>$root]);return implode('/',array_column($stmt->fetchAll(\PDO::FETCH_ASSOC)?:[],'display_name')); }
	private function rawItem(int $id): array { $stmt=$this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_ITEMS . '` WHERE id=:id');$stmt->execute([':id'=>$id]);return$stmt->fetch(\PDO::FETCH_ASSOC)?:[]; }
	private function nameExists(int $parent,string $normalized): bool { $stmt=$this->wire('database')->prepare('SELECT 1 FROM `' . self::TABLE_ITEMS . '` WHERE parent_id=:parent AND normalized_name=:name LIMIT 1');$stmt->execute([':parent'=>$parent,':name'=>$normalized]);return(bool)$stmt->fetchColumn(); }
	private function tableExists(string $name): bool { $stmt=$this->wire('database')->prepare('SHOW TABLES LIKE :name');$stmt->execute([':name'=>$name]);return(bool)$stmt->fetchColumn(); }
	private function normalizedName(string $name): string { $name=class_exists('Normalizer')?\Normalizer::normalize($name,\Normalizer::FORM_C):$name;return mb_strtolower(trim((string)$name),'UTF-8'); }
	private function cleanItemName(string $name,bool $file): string { $name=$file?basename(str_replace('\\','/',$name)):trim($name);$name=preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u',' ',(string)$name);$name=trim((string)$this->wire('sanitizer')->text($name));if($name===''||$name==='.'||$name==='..')throw new WireException($this->_('Enter a valid name.'));return mb_substr($name,0,255); }
	private function extensionAllowed(string $extension): bool { $allowed=preg_split('/[\s,]+/',strtolower(trim((string)$this->allowed_extensions)),-1,PREG_SPLIT_NO_EMPTY)?:[];return$extension!==''&&in_array($extension,$allowed,true); }
	private function configOverviewMarkup(): string {
		$storage=$this->storageRoot();$ready=is_dir($storage)&&is_writable($storage);$extensions=preg_split('/[\s,]+/',trim((string)$this->allowed_extensions),-1,PREG_SPLIT_NO_EMPTY)?:[];$library=(string)$this->wire('config')->urls->admin.'setup/files/';
		$status=[[$this->_('Storage'),$ready?$this->_('Ready'):$this->_('Needs attention'),$ready?'ready':'warning','database'],[$this->_('File types'),sprintf($this->_n('%d extension','%d extensions',count($extensions)),count($extensions)),'neutral','file-o'],[$this->_('Maximum file size'),$this->formatBytes(max(1,(int)$this->max_upload_mb)*1048576),'neutral','upload'],[$this->_('Default share'),sprintf($this->_('%d days'),(int)$this->default_share_days),'neutral','clock-o']];
		$cards='';foreach($status as[$label,$value,$state,$icon])$cards.='<article class="FilesConfigStatus" data-state="'.$this->h($state).'"><span class="FilesConfigStatus__icon"><i class="fa fa-'.$this->h($icon).'" aria-hidden="true"></i></span><span><small>'.$this->h($label).'</small><strong>'.$this->h($value).'</strong></span></article>';
		$links=[['#wrap_Inputfield_files_config_uploads',$this->_('Uploads'),'upload'],['#wrap_Inputfield_files_config_sharing',$this->_('Sharing links'),'share-alt'],['#wrap_Inputfield_files_config_onlyoffice',$this->_('ONLYOFFICE'),'file-word-o'],['#wrap_Inputfield_files_config_access',$this->_('Access control'),'key'],['#wrap_Inputfield_files_config_mcp',$this->_('MCP Server'),'plug'],['#wrap_Inputfield_files_config_storage',$this->_('Private storage'),'database'],['#wrap_Inputfield__files_server_information',$this->_('Server information'),'server']];$nav='';foreach($links as[$url,$label,$icon])$nav.='<a href="'.$url.'"><i class="fa fa-'.$icon.'" aria-hidden="true"></i>'.$this->h($label).'</a>';
		return'<div class="FilesConfigIntro"><div><p class="FilesConfigEyebrow">'.$this->_('Private file library').'</p><h2>'.$this->_('Storage and sharing control center').'</h2><p>'.$this->_('Files keeps originals outside the public web root. Use these settings to define upload policy and sharing boundaries; day-to-day folders and links are managed in the library.').'</p></div><a class="FilesConfigLibraryButton" href="'.$this->h($library).'"><i class="fa fa-folder-open" aria-hidden="true"></i>'.$this->_('Open Files library').'</a></div><div class="FilesConfigStatusGrid">'.$cards.'</div><nav class="FilesConfigNav" aria-label="'.$this->_('Settings sections').'">'.$nav.'</nav>';
	}
	private function effectiveUploadLimit(): int { $module=max(1,(int)$this->max_upload_mb)*1048576;$limits=array_filter([$module,$this->iniBytes((string)ini_get('upload_max_filesize')),$this->iniBytes((string)ini_get('post_max_size'))],static fn($value)=>$value>0);return$limits?min($limits):$module; }
	private function serverInformationMarkup(): string {
		$storage=$this->storageRoot();$storageReady=is_dir($storage);$writable=$storageReady&&is_writable($storage);$diskPath=$storageReady?$storage:(string)$this->wire('config')->paths->root;$free=@disk_free_space($diskPath);$total=@disk_total_space($diskPath);$used=$this->tableExists(self::TABLE_ITEMS)?(int)$this->wire('database')->query('SELECT COALESCE(SUM(size_bytes),0) FROM `' . self::TABLE_ITEMS . '` WHERE kind=\'file\'')->fetchColumn():0;$files=$this->tableExists(self::TABLE_ITEMS)?(int)$this->wire('database')->query('SELECT COUNT(*) FROM `' . self::TABLE_ITEMS . '` WHERE kind=\'file\'')->fetchColumn():0;$folders=$this->tableExists(self::TABLE_ITEMS)?max(0,(int)$this->wire('database')->query('SELECT COUNT(*)-1 FROM `' . self::TABLE_ITEMS . '` WHERE kind=\'folder\'')->fetchColumn()):0;$shares=$this->tableExists(self::TABLE_SHARES)?(int)$this->wire('database')->query('SELECT COUNT(*) FROM `' . self::TABLE_SHARES . '` s WHERE '.$this->activeShareSql('s'))->fetchColumn():0;
		$upload=(string)ini_get('upload_max_filesize');$post=(string)ini_get('post_max_size');$memory=(string)ini_get('memory_limit');$execution=(string)ini_get('max_execution_time');$maximum=max(1,(int)$this->max_upload_mb)*1048576;$chunk=$this->chunkUploadSize();$diskFree=$free===false?$this->_('Unavailable'):$this->formatBytes((int)$free);$diskTotal=$total===false?$this->_('Unavailable'):$this->formatBytes((int)$total);$storageState=$writable?$this->_('Writable'):($storageReady?$this->_('Not writable'):$this->_('Missing'));$storageClass=$writable?'good':'bad';$opcache=function_exists('opcache_get_status')&&(bool)ini_get('opcache.enable');$software=trim((string)($_SERVER['SERVER_SOFTWARE']??php_sapi_name()));$host=function_exists('gethostname')?(string)gethostname():'';
		$cards=[[$this->_('Files'),(string)$files,$this->_('managed objects'),''],[$this->_('Storage used'),$this->formatBytes($used),sprintf($this->_('%d folders'),$folders),''],[$this->_('Active shares'),(string)$shares,$this->_('public and recipient links'),''],[$this->_('Disk free'),$diskFree,$diskTotal.' '.$this->_('total'),$free!==false&&$free<2147483648?'warn':'good'],[$this->_('Maximum file size'),$this->formatBytes($maximum),sprintf($this->_('AJAX chunks up to %s'),$this->formatBytes($chunk)),$maximum<33554432?'warn':'good'],[$this->_('Private storage'),$storageState,$storage,$storageClass]];$cardHtml='';foreach($cards as[$label,$value,$note,$class])$cardHtml.='<article class="FilesServerCard'.($class?' is-'.$class:'').'"><strong>'.$this->h($value).'</strong><span>'.$this->h($label).'</span><small>'.$this->h($note).'</small></article>';
		$pdoExtension=match($this->wire('database')->dialect()->name()){'sqlite'=>'pdo_sqlite','postgresql'=>'pdo_pgsql',default=>'pdo_mysql'};
		$extensions=['fileinfo'=>true,'mbstring'=>true,$pdoExtension=>true,'sodium'=>true,'intl'=>false,'zip'=>false];$pills='';foreach($extensions as $extension=>$required){$loaded=extension_loaded($extension);$class=$loaded?'good':($required?'bad':'neutral');$status=$loaded?'✓':($required?'✕':'—');$pills.='<span class="FilesServerPill is-'.$class.'"><b>'.$status.'</b> '.$this->h($extension).'</span>';}
		$rows=[[$this->_('PHP version'),PHP_VERSION.' · '.PHP_SAPI],[$this->_('ProcessWire version'),(string)$this->wire('config')->version],[$this->_('Web server'),$software?:'—'],[$this->_('Host'),$host?:'—'],['memory_limit',$memory],['max_execution_time',$execution==='0'?$this->_('Unlimited'):$execution.'s'],['upload_max_filesize',$upload],['post_max_size',$post],[$this->_('OPcache'),$opcache?$this->_('Enabled'):$this->_('Disabled')],[$this->_('Storage path'),$storage]];$rowHtml='';foreach($rows as[$label,$value])$rowHtml.='<tr><td>'.$this->h($label).'</td><td><strong>'.$this->h($value).'</strong></td></tr>';
		return'<section class="FilesServerInfo"><p class="FilesServerIntro">'.$this->_('Live diagnostics from this server. Files enforces the configured maximum file size, while AJAX divides each upload into requests sized safely below the PHP upload and post limits.').'</p><div class="FilesServerCards">'.$cardHtml.'</div><div class="FilesServerGrid"><div class="FilesServerBox"><h3><i class="fa fa-server"></i> '.$this->_('Runtime').'</h3><table class="FilesServerTable">'.$rowHtml.'</table></div><div class="FilesServerBox"><h3><i class="fa fa-puzzle-piece"></i> '.$this->_('PHP extensions').'</h3><div class="FilesServerPills">'.$pills.'</div><p class="FilesServerPath">'.$this->_('fileinfo, mbstring, the PDO driver for the configured database, and Sodium are required by Files. intl and zip are optional.').'</p></div></div></section>';
	}
	private function iniBytes(string $value): int { $value=trim($value);if($value===''||$value==='-1')return-1;$unit=strtolower(substr($value,-1));$number=(float)$value;return(int)round($number*match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1}); }
	private function permissionDefinitions(): array { return [self::PERMISSION_USE=>$this->_('Open the Files workspace and browse accessible metadata'),self::PERMISSION_DOWNLOAD=>$this->_('Preview and download accessible files'),self::PERMISSION_UPLOAD=>$this->_('Upload files to permitted folders'),self::PERMISSION_FOLDERS=>$this->_('Create folders in permitted locations'),self::PERMISSION_SHARE=>$this->_('Create and revoke shares for owned items'),self::PERMISSION_DELETE=>$this->_('Permanently delete owned files'),self::PERMISSION_MANAGE=>$this->_('Manage all Files content, permissions, and shares')]; }
	private function permissionInformationMarkup(): string { $rows='';foreach($this->permissionDefinitions() as $name=>$title)$rows.='<tr><td><code>'.$this->h($name).'</code></td><td>'.$this->h($title).'</td></tr>';$roles=(string)$this->wire('config')->urls->admin.'access/roles/';return'<div class="FilesPermissionInfo"><table class="FilesServerTable"><thead><tr><th>'.$this->_('Permission').'</th><th>'.$this->_('Capability').'</th></tr></thead><tbody>'.$rows.'</tbody></table><a class="FilesConfigLibraryButton" href="'.$this->h($roles).'"><i class="fa fa-users" aria-hidden="true"></i>'.$this->_('Manage role permissions').'</a></div>'; }
	private function validateUploadTarget(int $folderId,string $name,int $size,User $user): array {
		$folder=$this->item($folderId,$user);if(!$folder||$folder['kind']!=='folder')throw new Wire404Exception($this->_('Folder not found.'));if(!$this->canManage($user)&&(int)$folder['owner_user_id']!==(int)$user->id&&$folderId!==self::ROOT_ID)throw new WirePermissionException($this->_('You cannot upload to this folder.'));
		$name=$this->cleanItemName($name,true);if($size<1||$size>max(1,(int)$this->max_upload_mb)*1048576)throw new WireException($this->_('The file exceeds the configured size limit.'));$extension=strtolower((string)pathinfo($name,PATHINFO_EXTENSION));if(!$this->extensionAllowed($extension))throw new WireException($this->_('This file type is not allowed.'));$normalized=$this->normalizedName($name);if($this->nameExists($folderId,$normalized))throw new WireException($this->_('An item with this name already exists in the folder.'));return[$folder,$name,$normalized,$extension];
	}
	private function registerStoredFile(string $destination,string $storageName,string $name,string $normalized,string $extension,int $size,int $folderId,User $user): array {
		if(!is_file($destination)||(int)filesize($destination)!==$size)throw new WireException($this->_('The stored file size does not match the upload.'));$finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=substr((string)$finfo->file($destination),0,190)?:'application/octet-stream';$checksum=hash_file('sha256',$destination);if($checksum===false)throw new WireException($this->_('The stored file checksum could not be calculated.'));$now=date('Y-m-d H:i:s');$db=$this->wire('database');$db->beginTransaction();
		try{$stmt=$db->prepare('INSERT INTO `'.self::TABLE_ITEMS.'` (parent_id,kind,owner_user_id,display_name,normalized_name,storage_name,mime_type,extension,size_bytes,checksum_sha256,created_at,updated_at) VALUES (:parent,\'file\',:owner,:name,:normalized,:storage,:mime,:extension,:size,:checksum,:created,:updated)');$stmt->execute([':parent'=>$folderId,':owner'=>(int)$user->id,':name'=>$name,':normalized'=>$normalized,':storage'=>$storageName,':mime'=>$mime,':extension'=>$extension,':size'=>$size,':checksum'=>$checksum,':created'=>$now,':updated'=>$now]);$id=(int)$db->lastInsertId();$this->insertTreeLinks($id,$folderId);$db->commit();}catch(\Throwable $e){$db->rollBack();throw$e;}return$this->item($id,$user);
	}
	private function chunkSessionRoot(): string { return $this->storageRoot().'.uploads/'; }
	private function chunkMetaPath(string $id): string { return $this->chunkSessionRoot().$id.'.json'; }
	private function chunkPartPath(string $id): string { return $this->chunkSessionRoot().$id.'.part'; }
	private function chunkSession(string $id,User $user): array {
		if(!preg_match('/^[A-Za-z0-9_-]{32}$/D',$id))throw new Wire404Exception($this->_('Upload session not found.'));$path=$this->chunkMetaPath($id);if(!is_file($path))throw new Wire404Exception($this->_('Upload session not found.'));$raw=file_get_contents($path,false,null,0,16384);$meta=is_string($raw)?json_decode($raw,true):null;if(!is_array($meta)||(int)($meta['owner_user_id']??0)!==(int)$user->id)throw new Wire404Exception($this->_('Upload session not found.'));if((int)($meta['created_at']??0)<time()-self::CHUNK_SESSION_TTL){@unlink($this->chunkPartPath($id));@unlink($path);throw new WireException($this->_('The upload session expired. Start the upload again.'));}return$meta;
	}
	private function cleanupChunkSessions(): void {
		$root=$this->chunkSessionRoot();if(!is_dir($root))return;$cutoff=time()-self::CHUNK_SESSION_TTL;$checked=0;foreach((array)glob($root.'*.json')as$meta){if(++$checked>200)break;if((int)@filemtime($meta)>=$cutoff)continue;$id=basename($meta,'.json');if(preg_match('/^[A-Za-z0-9_-]{32}$/D',$id))@unlink($this->chunkPartPath($id));@unlink($meta);}foreach((array)glob($root.'*.part')as$part){if(++$checked>400)break;if((int)@filemtime($part)<$cutoff&&!is_file($root.basename($part,'.part').'.json'))@unlink($part);}
	}
	private function storageRoot(): string { $custom=trim((string)$this->storage_path);$path=$custom!==''?$custom:dirname(rtrim((string)$this->wire('config')->paths->root,'/')).'/.'.basename(rtrim((string)$this->wire('config')->paths->root,'/')).'-files';return rtrim($path,'/').'/'; }
	private function ensureStorageDirectory(): void { $root=$this->storageRoot();if(!is_dir($root)&&!wireMkdir($root,true))throw new WireException('Unable to create Files storage directory.');@chmod($root,0700); }
	private function normalizedPublicPath(): string { $path='/'.trim((string)$this->public_path,'/').'/';return$path==='//'?'/files/share/':$path; }
	private function randomUrlToken(int $bytes): string { return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'='); }
	private function asciiFilename(string $name): string { $ascii=(string)$this->wire('sanitizer')->filename($name,true);return$ascii!==''?$ascii:'download'; }
	private function uploadError(int $error): string { $messages=[UPLOAD_ERR_INI_SIZE=>$this->_('The file exceeds the server upload limit.'),UPLOAD_ERR_FORM_SIZE=>$this->_('The file exceeds the form upload limit.'),UPLOAD_ERR_PARTIAL=>$this->_('The file was only partially uploaded.'),UPLOAD_ERR_NO_FILE=>$this->_('Choose a file to upload.')];return$messages[$error]??$this->_('The upload failed.'); }
	private function requireUse(?User $actor=null): void { if(!$this->canUse($actor))throw new WirePermissionException($this->_('You cannot use Files.')); }
	private function activeAccount($user): bool { return $user instanceof User && $user->id && !$user->isGuest() && !$user->isUnpublished() && !$user->isTrash() && !$user->hasStatus(Page::statusLocked); }
	private function hasCapability(string $permission,?User $actor=null): bool { $user=$actor?:$this->wire('user');return$this->canUse($user)&&($this->canManage($user)||$user->hasPermission($permission)); }
	private function requireCapability(string $permission,?User $actor=null): void { if(!$this->hasCapability($permission,$actor))throw new WirePermissionException($this->_('You do not have permission for this Files action.')); }
	private function formatBytes(int $bytes): string { $units=['B','KB','MB','GB','TB'];$value=max(0,$bytes);$unit=0;while($value>=1024&&$unit<count($units)-1){$value/=1024;$unit++;}return($unit===0?(string)(int)$value:number_format($value,$value>=10?1:2)).' '.$units[$unit]; }
	private function publicError(int $status,string $message): string { http_response_code($status);return$this->publicPage($this->_('Share unavailable'),'<p>'.$this->h($message).'</p>'); }
	private function publicPage(string $title,string $body): string { header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->h($title).'</title><style>body{margin:0;background:#f3f5f7;color:#222;font:16px system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}.box{width:min(52rem,calc(100% - 2rem));background:#fff;border:1px solid #d9dde2;border-radius:.35rem;padding:2rem;box-sizing:border-box}h1{font-size:1.35rem;margin:0 0 1rem}label{display:block;font-weight:600;margin:.75rem 0 .35rem}input{width:100%;padding:.75rem;border:1px solid #adb5bd;box-sizing:border-box}button,.FilesPublic-list a,.FilesPublic-download{display:inline-block;margin-top:1rem;background:#d91f55;color:#fff;border:0;padding:.6rem .85rem;font-weight:600;text-decoration:none;cursor:pointer}.FilesPublic-list{list-style:none;padding:0}.FilesPublic-list li{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:1rem;padding:.7rem 0;border-top:1px solid #e3e6e8}.FilesPublic-list a{margin:0}.FilesPublic-list small,.FilesPublic-path{color:#69727a}.FilesPublic-error{color:#b42318}</style><body><main class="box"><h1>'.$this->h($title).'</h1>'.$body.'</main></body></html>'; }
	private function h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
