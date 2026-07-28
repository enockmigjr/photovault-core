(function() {
	'use strict';

	function surface() {
		return document.querySelector('.pv-admin');
	}

	function setBusy(form, busy) {
		form.toggleAttribute('aria-busy', busy);
		form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
			button.disabled = busy;
			button.classList.toggle('is-busy', busy);
		});
	}

	function submitterPayload(form, submitter) {
		const data = new FormData(form);
		if (submitter && submitter.name) {
			data.set(submitter.name, submitter.value);
		}
		return data;
	}

	function toast(message, success) {
		document.querySelectorAll('[data-pv-admin-toast]').forEach(function(item) {
			item.remove();
		});
		const notice = document.createElement('div');
		notice.className = 'pv-admin-runtime-toast ' + (success ? 'is-success' : 'is-error');
		notice.dataset.pvAdminToast = '';
		notice.setAttribute('role', success ? 'status' : 'alert');

		const text = document.createElement('span');
		text.textContent = message;
		notice.appendChild(text);

		const close = document.createElement('button');
		close.type = 'button';
		close.setAttribute('aria-label', 'Fermer la notification');
		close.textContent = '\u00d7';
		close.addEventListener('click', function() {
			notice.remove();
		});
		notice.appendChild(close);
		document.body.appendChild(notice);
		window.setTimeout(function() {
			notice.remove();
		}, 7000);
	}

	async function refresh(url, options, historyMode) {
		const current = surface();
		const response = await fetch(url, Object.assign({
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}, options || {}));
		const html = await response.text();
		const parsed = new DOMParser().parseFromString(html, 'text/html');
		const next = parsed.querySelector('.pv-admin');
		if (!response.ok || !current || !next) {
			throw new Error('L ecran PhotoVault n a pas pu etre actualise.');
		}

		current.replaceWith(next);
		if (parsed.title) document.title = parsed.title;
		if (historyMode === 'push') window.history.pushState({ photovaultAdmin: true }, '', response.url);
		if (historyMode === 'replace') window.history.replaceState({ photovaultAdmin: true }, '', response.url);
		const message = next.querySelector('.notice p, .updated p, .error p');
		const heading = next.querySelector('h1');
		if (heading) {
			heading.tabIndex = -1;
			heading.focus({ preventScroll: true });
		}
		return message ? message.textContent.trim() : 'Modification enregistree.';
	}

	document.addEventListener('submit', async function(event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || (!form.closest('.pv-admin') && !form.matches('[data-pv-admin-settings]'))) return;
		event.preventDefault();
		if (form.getAttribute('aria-busy') === 'true') return;

		setBusy(form, true);
		const method = (form.method || 'GET').toUpperCase();
		try {
			let url = new URL(form.getAttribute('action') || window.location.href, window.location.href).href;
			let options = {};
			let historyMode = 'replace';
			const payload = submitterPayload(form, event.submitter);
			if (form.matches('[data-pv-admin-settings]')) {
				const response = await fetch(url, {
					method: method,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
					body: payload
				});
				if (!response.ok) throw new Error('Les reglages n ont pas pu etre enregistres.');
				setBusy(form, false);
				toast('Reglages enregistres.', true);
				return;
			}
			if (method === 'GET') {
				url = url.split('?')[0] + '?' + new URLSearchParams(payload).toString();
				historyMode = 'push';
			} else {
				options = { method: method, body: payload };
			}
			const message = await refresh(url, options, historyMode);
			toast(message, true);
		} catch (error) {
			setBusy(form, false);
			toast(error.message || 'L operation n a pas pu etre terminee.', false);
		}
	});

	document.addEventListener('click', async function(event) {
		if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		const link = event.target.closest('.pv-admin-pagination a, .pv-access-status-tabs a');
		if (!link) return;
		event.preventDefault();
		try {
			await refresh(link.href, {}, 'push');
		} catch (error) {
			toast(error.message || 'La page n a pas pu etre chargee.', false);
		}
	});

	window.addEventListener('popstate', async function() {
		if (!surface()) return;
		try {
			await refresh(window.location.href, {}, '');
		} catch (error) {
			window.location.reload();
		}
	});
})();
