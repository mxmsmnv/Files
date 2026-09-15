(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback);
		else callback();
	}

	ready(function () {
		var form = document.getElementById('files-upload-form');
		if (!form) return;

		var input = document.getElementById('files-upload');
		var zone = form.querySelector('.FilesDropzone');
		var selected = document.getElementById('files-selected-file');
		var selectedName = document.getElementById('files-selected-name');
		var selectedSize = document.getElementById('files-selected-size');
		var remove = document.getElementById('files-remove-selection');
		var progress = document.getElementById('files-upload-progress');
		var meter = document.getElementById('files-upload-meter');
		var percent = document.getElementById('files-upload-percent');
		var status = document.getElementById('files-upload-status');
		var error = document.getElementById('files-upload-error');
		var submit = document.getElementById('files-upload-submit');
		var csrf = form.querySelector('input[type="hidden"]:not([name="folder_id"])');
		var activeUpload = '';
		var busy = false;
		var originalSubmit = submit ? submit.innerHTML : '';

		function bytes(value) {
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var unit = 0;
			while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
			return (unit === 0 ? Math.round(value) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
		}

		function updateSelection() {
			var file = input && input.files ? input.files[0] : null;
			selected.hidden = !file;
			zone.classList.toggle('has-file', !!file);
			if (file) {
				selectedName.textContent = file.name;
				selectedSize.textContent = bytes(file.size);
			}
		}

		function formData(fields) {
			var data = new FormData();
			Object.keys(fields).forEach(function (key) { data.append(key, fields[key]); });
			if (csrf) data.append(csrf.name, csrf.value);
			return data;
		}

		async function request(url, body, retries) {
			var lastError;
			for (var attempt = 0; attempt <= retries; attempt++) {
				try {
					var response = await fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
					var result;
					try { result = await response.json(); }
					catch (parseError) { throw new Error('The server returned an invalid upload response.'); }
					if (!response.ok || !result.ok) throw new Error(result.error || 'The upload failed.');
					return result;
				} catch (caught) {
					lastError = caught;
					if (attempt >= retries) throw caught;
					status.textContent = form.dataset.labelRetry;
					await new Promise(function (resolve) { window.setTimeout(resolve, 600 * (attempt + 1)); });
				}
			}
			throw lastError;
		}

		function showProgress(offset, total, label) {
			var value = total > 0 ? Math.min(100, Math.round(offset / total * 100)) : 0;
			progress.hidden = false;
			meter.value = value;
			meter.textContent = value + '%';
			percent.textContent = value + '%';
			status.textContent = label;
		}

		function setBusy(value) {
			busy = value;
			input.disabled = value;
			if (remove) remove.disabled = value;
			if (submit) {
				submit.disabled = value;
				submit.innerHTML = value ? '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i><span>' + form.dataset.labelUploading + '</span>' : originalSubmit;
			}
		}

		async function cancelTemporaryUpload() {
			if (!activeUpload) return;
			var id = activeUpload;
			activeUpload = '';
			try { await request(form.dataset.uploadCancel, formData({ upload_id: id }), 0); }
			catch (ignored) {}
		}

		input.addEventListener('change', function () { error.hidden = true; updateSelection(); });
		['dragenter', 'dragover'].forEach(function (event) { zone.addEventListener(event, function () { zone.classList.add('is-dragging'); }); });
		['dragleave', 'drop'].forEach(function (event) { zone.addEventListener(event, function () { zone.classList.remove('is-dragging'); }); });
		if (remove) remove.addEventListener('click', function () { if (busy) return; input.value = ''; updateSelection(); input.focus(); });

		form.addEventListener('submit', async function (event) {
			if (busy) { event.preventDefault(); return; }
			if (!window.fetch || !window.FormData || !window.Blob) return;
			event.preventDefault();
			var file = input.files && input.files[0];
			if (!file) { input.focus(); return; }
			var maximum = Number(form.dataset.maxBytes || 0);
			if (maximum > 0 && file.size > maximum) {
				error.textContent = 'The file exceeds the configured size limit (' + bytes(maximum) + ').';
				error.hidden = false;
				return;
			}

			setBusy(true);
			error.hidden = true;
			showProgress(0, file.size, form.dataset.labelUploading);
			try {
				var started = await request(form.dataset.uploadStart, formData({ file_name: file.name, file_size: String(file.size), folder_id: form.elements.folder_id.value }), 0);
				activeUpload = started.upload_id;
				var offset = Number(started.offset || 0);
				var chunkSize = Number(started.chunk_size || 0);
				if (!chunkSize) throw new Error('The server did not provide a valid chunk size.');

				while (offset < file.size) {
					var end = Math.min(file.size, offset + chunkSize);
					var body = formData({ upload_id: activeUpload, offset: String(offset) });
					body.append('chunk', file.slice(offset, end), file.name + '.part');
					var result = await request(form.dataset.uploadChunk, body, 2);
					offset = Number(result.offset);
					showProgress(offset, file.size, result.complete ? form.dataset.labelFinishing : form.dataset.labelUploading);
					if (result.complete) {
						activeUpload = '';
						showProgress(file.size, file.size, form.dataset.labelComplete);
						window.location.assign(result.item_url || (form.dataset.itemBase + result.item_id));
						return;
					}
				}
				throw new Error('The server did not finish the upload.');
			} catch (caught) {
				await cancelTemporaryUpload();
				error.textContent = caught && caught.message ? caught.message : 'The upload failed.';
				error.hidden = false;
				progress.hidden = true;
				setBusy(false);
			}
		});
	});
})();
