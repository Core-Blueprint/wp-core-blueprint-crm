(() => {
	'use strict';

	const syncTaxMode = (select) => {
		const scope = select.closest('[data-cb-crm-service-card], #cb-crm-panel-service-pricing');
		if (!scope) {
			return;
		}
		const fields = scope.querySelectorAll('[data-cb-crm-tax-rate-field]');
		if (fields.length === 0) {
			return;
		}
		const target = select.closest('.cb-crm-custom-pricing')
			? select.closest('.cb-crm-custom-pricing').querySelector('[data-cb-crm-tax-rate-field]')
			: scope.querySelector(':scope > .inside [data-cb-crm-tax-rate-field], [data-cb-crm-tax-rate-field]');
		if (target) {
			target.hidden = select.value === 'exempt';
		}
	};

	const syncPricingMode = (select) => {
		const card = select.closest('[data-cb-crm-service-card]');
		if (!card) {
			return;
		}
		const custom = card.querySelector('[data-cb-crm-custom-pricing]');
		if (custom) {
			custom.hidden = select.value !== 'custom';
		}
	};

	const syncServiceSummary = (select) => {
		const card = select.closest('[data-cb-crm-service-card]');
		if (!card) {
			return;
		}
		const summary = card.querySelector('[data-cb-crm-service-price-summary]');
		const pricingMode = card.querySelector('[data-cb-crm-pricing-mode]');
		if (!summary || (pricingMode && pricingMode.value === 'custom')) {
			return;
		}
		const option = select.options[select.selectedIndex];
		summary.textContent = option?.dataset?.priceSummary || '';
	};

	document.addEventListener('change', (event) => {
		const taxMode = event.target.closest('[data-cb-crm-tax-mode]');
		if (taxMode) {
			syncTaxMode(taxMode);
		}
		const pricingMode = event.target.closest('[data-cb-crm-pricing-mode]');
		if (pricingMode) {
			syncPricingMode(pricingMode);
		}
		const service = event.target.closest('[data-cb-crm-service-select]');
		if (service) {
			syncServiceSummary(service);
		}
	});

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('[data-cb-crm-tax-mode]').forEach(syncTaxMode);
		document.querySelectorAll('[data-cb-crm-pricing-mode]').forEach(syncPricingMode);
	});
})();
