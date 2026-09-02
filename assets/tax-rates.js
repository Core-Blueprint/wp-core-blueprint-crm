(() => {
	'use strict';

	const form = document.querySelector('[data-cb-crm-tax-rate-form]');
	if (!form) {
		return;
	}

	const label = form.querySelector('[data-cb-crm-tax-label]');
	const code = form.querySelector('[data-cb-crm-tax-code]');
	if (!label || !code) {
		return;
	}

	const slugify = (value) => {
		let normalized = String(value || '').trim().toLowerCase();
		if (typeof normalized.normalize === 'function') {
			normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}
		return normalized
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.slice(0, 64);
	};

	let customCode = code.value.trim() !== '';

	const syncCode = () => {
		if (!customCode) {
			code.value = slugify(label.value);
		}
	};

	label.addEventListener('input', syncCode);
	code.addEventListener('input', () => {
		customCode = code.value.trim() !== '';
		if (!customCode) {
			syncCode();
		}
	});

	syncCode();
})();
