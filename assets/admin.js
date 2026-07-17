(function () {
	'use strict';
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.pv-admin')) return;
		if (form.dataset.pvSubmitting === 'true') {
			event.preventDefault();
			return;
		}
		var submitter = event.submitter;
		if (submitter && submitter.name) {
			form.querySelectorAll('input[data-pv-submitter]').forEach(function (input) { input.remove(); });
			var submittedValue = document.createElement('input');
			submittedValue.type = 'hidden';
			submittedValue.name = submitter.name;
			submittedValue.value = submitter.value;
			submittedValue.dataset.pvSubmitter = 'true';
			form.appendChild(submittedValue);
		}
		form.dataset.pvSubmitting = 'true';
		form.setAttribute('aria-busy', 'true');
		form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
			button.disabled = true;
			button.classList.add('is-busy');
		});
	});
	window.addEventListener('pageshow', function () {
		document.querySelectorAll('.pv-admin form[data-pv-submitting="true"]').forEach(function (form) {
			delete form.dataset.pvSubmitting;
			form.removeAttribute('aria-busy');
			form.querySelectorAll('button, input[type="submit"]').forEach(function (button) {
				button.disabled = false;
				button.classList.remove('is-busy');
			});
		});
	});
}());
