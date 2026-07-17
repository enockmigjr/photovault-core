(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const config = window.PhotoVaultUpload || {};
        const form = document.getElementById('pv-upload-form');
        const input = document.getElementById('pv-media-files');
        const list = document.getElementById('pv-upload-list');
        const summary = document.getElementById('pv-upload-summary');
        const template = document.getElementById('pv-media-editor-template');
		const submitButton = form ? form.querySelector('button[type="submit"]') : null;
        let selectedRows = [];
		let isUploading = false;
        if (!form || !input || !list || !template || !config.uploadUrl) return;
		if (submitButton) submitButton.disabled = true;

        function formatBytes(bytes) {
            if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' Ko';
            return (bytes / 1024 / 1024).toFixed(1) + ' Mo';
        }

        function createRow(file) {
            const item = document.createElement('li');
			item.className = 'pv-upload-item is-pending-selection';
            const heading = document.createElement('div');
            heading.className = 'pv-upload-file';
            const name = document.createElement('strong');
            name.textContent = file.name;
            const size = document.createElement('span');
            size.textContent = formatBytes(file.size);
            const status = document.createElement('span');
            status.className = 'pv-upload-status';
            status.textContent = 'En attente';
            const progress = document.createElement('progress');
            progress.max = 100;
            progress.value = 0;
            heading.append(name, size, status);
            item.append(heading, progress);
            list.appendChild(item);
            return { item, status, progress };
        }

        function renderSelection() {
            const files = Array.from(input.files || []);
			list.querySelectorAll('.is-pending-selection').forEach(function (item) { item.remove(); });
            selectedRows = files.map(createRow);
            summary.textContent = files.length ? files.length + ' fichier(s) pret(s) a importer.' : '';
			if (submitButton) submitButton.disabled = !files.length;
        }

		function lockQueue(locked) {
			input.disabled = locked;
			form.querySelectorAll('select, input[type="checkbox"]').forEach(function (control) {
				control.disabled = locked;
			});
			if (submitButton) {
				submitButton.disabled = locked || !input.files.length;
				submitButton.textContent = locked ? 'Import en cours...' : 'Demarrer l import';
			}
		}

        function uploadFile(file, row) {
            return new Promise(function (resolve) {
                const data = new FormData();
                data.append('media_file', file, file.name);
                data.append('visibility', document.getElementById('pv-default-visibility').value);
                data.append('is_protected', document.getElementById('pv-default-protected').checked ? '1' : '0');
                const request = new XMLHttpRequest();
                request.open('POST', config.uploadUrl);
                request.setRequestHeader('X-WP-Nonce', config.nonce);
                request.upload.addEventListener('progress', function (event) {
                    if (event.lengthComputable) {
                        row.progress.value = Math.round((event.loaded / event.total) * 100);
                        row.status.textContent = 'Transfert ' + row.progress.value + '%';
                    }
                });
                request.addEventListener('load', function () {
                    let payload = {};
                    try { payload = JSON.parse(request.responseText); } catch (error) { payload = {}; }
                    if (request.status < 200 || request.status >= 300) {
                        row.item.classList.add('is-error');
                        row.status.textContent = payload.message || 'Echec de l import';
                        resolve(false);
                        return;
                    }
                    row.progress.value = 100;
                    row.item.classList.add('is-success');
                    row.status.textContent = 'Import termine';
                    attachEditor(row.item, payload);
                    resolve(true);
                });
                request.addEventListener('error', function () {
                    row.item.classList.add('is-error');
                    row.status.textContent = 'Connexion interrompue';
                    resolve(false);
                });
                request.send(data);
            });
        }

        function attachEditor(item, media) {
            const editor = template.content.cloneNode(true);
            const editorForm = editor.querySelector('form');
            editorForm.dataset.mediaId = String(media.id);
            editorForm.elements.title.value = media.title || '';
            editorForm.elements.description.value = media.description || '';
            editorForm.elements.folder.value = String(media.folder || 0);
            editorForm.elements.category.value = String(media.category || 0);
            editorForm.elements.visibility.value = media.visibility || 'private';
            editorForm.elements.tags.value = media.tags || '';
            editorForm.elements.is_protected.checked = Boolean(media.is_protected);
            const image = editorForm.querySelector('img');
            image.src = media.image || '';
            image.alt = media.title || '';
            const editLink = editorForm.querySelector('.pv-full-edit');
            editLink.href = media.edit_url || '#';
            if (!media.edit_url) editLink.hidden = true;
            editorForm.addEventListener('submit', saveMetadata);
            item.appendChild(editor);
        }

        async function saveMetadata(event) {
            event.preventDefault();
            const editor = event.currentTarget;
            const button = editor.querySelector('button[type="submit"]');
            const status = editor.querySelector('.pv-editor-status');
            const fields = editor.elements;
            button.disabled = true;
            status.textContent = 'Enregistrement...';
            try {
                const response = await fetch(config.mediaUrl.replace(/\/$/, '') + '/' + encodeURIComponent(editor.dataset.mediaId), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                    body: JSON.stringify({
                        title: fields.title.value,
                        description: fields.description.value,
                        folder: Number(fields.folder.value),
                        category: Number(fields.category.value),
                        visibility: fields.visibility.value,
                        tags: fields.tags.value,
                        is_protected: fields.is_protected.checked
                    })
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Echec de l enregistrement');
                status.textContent = 'Metadonnees enregistrees';
                editor.closest('.pv-upload-item').classList.add('is-saved');
            } catch (error) {
                status.textContent = error.message || 'Echec de l enregistrement';
            } finally {
                button.disabled = false;
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
			if (isUploading) return;
            const files = Array.from(input.files || []);
            if (!files.length) return;
            if (files.length > Number(config.maxFiles || 20)) {
                summary.textContent = 'Selection limitee a ' + config.maxFiles + ' fichiers.';
                return;
            }
            if (selectedRows.length !== files.length) renderSelection();
            let succeeded = 0;
			isUploading = true;
			selectedRows.forEach(function (row) {
				row.item.classList.remove('is-pending-selection');
				row.item.classList.add('is-uploading');
			});
			lockQueue(true);
            summary.textContent = 'Import de ' + files.length + ' fichier(s)...';
			try {
				for (const [index, file] of files.entries()) {
					const row = selectedRows[index];
					if (file.size > Number(config.maxBytes || 0)) {
						row.item.classList.add('is-error');
						row.status.textContent = 'Fichier trop volumineux';
						continue;
					}
					if (await uploadFile(file, row)) succeeded += 1;
				}
				summary.textContent = succeeded + ' sur ' + files.length + ' fichier(s) importe(s).';
			} finally {
				isUploading = false;
				selectedRows.forEach(function (row) { row.item.classList.remove('is-uploading'); });
				input.value = '';
				selectedRows = [];
				lockQueue(false);
			}
        });

        input.addEventListener('change', renderSelection);
    });
}());
