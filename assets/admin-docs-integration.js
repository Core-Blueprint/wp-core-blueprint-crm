(() => {
	'use strict';

	const config = window.cbCrmDocs || {};

	const post = async (data) => {
		const body = new URLSearchParams({ nonce: config.nonce || '', ...data });
		const response = await fetch(config.ajaxUrl || '', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
		});
		const payload = await response.json();
		if (!response.ok || !payload.success) {
			throw new Error(payload?.data?.code || 'request_failed');
		}
		return payload.data;
	};

	const initPanel = (panel) => {
		const view = panel.dataset.view || 'owner';
		const linked = panel.querySelector('[data-cb-crm-docs-linked]');
		const results = panel.querySelector('[data-cb-crm-docs-results]');
		const status = panel.querySelector('[data-cb-crm-docs-status]');
		const search = panel.querySelector('[data-cb-crm-docs-search]');
		const searchButton = panel.querySelector('[data-cb-crm-docs-search-button]');
		const ownerTypeSelect = panel.querySelector('[data-cb-crm-docs-owner-type]');
		const context = panel.querySelector('[data-cb-crm-docs-context]');
		let controller = 0;

		if (!linked || !results || !search || !searchButton || !config.ajaxUrl) {
			return;
		}

		const setStatus = (message = '') => {
			if (status) {
				status.textContent = message;
			}
		};

		const clearResults = () => {
			results.replaceChildren();
		};

		const ownerContext = () => ({
			ownerType: panel.dataset.ownerType || '',
			ownerId: Number.parseInt(panel.dataset.ownerId || '0', 10) || 0,
			documentId: Number.parseInt(panel.dataset.documentId || '0', 10) || 0,
		});

		const actionLabel = (type) => {
			if (type === 'contact') {
				return config.i18n?.addContact || 'Add Contact';
			}
			if (type === 'organization') {
				return config.i18n?.addOrganization || 'Add Organization';
			}
			return config.i18n?.addDocs || 'Add Docs';
		};

		const renderResults = (items) => {
			clearResults();
			if (!Array.isArray(items) || items.length === 0) {
				setStatus(view === 'document' ? (config.i18n?.emptyCrm || '') : (config.i18n?.emptyDocs || ''));
				return;
			}

			const list = document.createElement('ul');
			items.forEach((item) => {
				const li = document.createElement('li');
				const title = document.createElement('strong');
				title.textContent = item.label || '';
				li.appendChild(title);

				if (item.meta) {
					const meta = document.createElement('small');
					meta.textContent = item.meta;
					li.append(document.createElement('br'), meta);
				}

				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'button button-small';
				button.textContent = actionLabel(item.type);
				button.dataset.cbCrmDocsLink = '1';
				button.dataset.resultId = String(item.id || 0);
				button.dataset.resultType = item.type || '';
				li.append(document.createElement('br'), button);
				list.appendChild(li);
			});
			results.appendChild(list);
			setStatus('');
		};

		const runSearch = async () => {
			const term = search.value.trim();
			if (term.length < 2) {
				clearResults();
				setStatus('');
				return;
			}

			const current = ++controller;
			setStatus(config.i18n?.searching || '');
			try {
				const data = await post({
					action: config.actions?.search || '',
					view,
					term,
					owner_type: ownerTypeSelect?.value || '',
				});
				if (current === controller) {
					renderResults(data);
				}
			} catch (error) {
				if (current === controller) {
					clearResults();
					setStatus('');
				}
			}
		};

		const refreshLinked = (html) => {
			if (typeof html === 'string') {
				linked.innerHTML = html;
			}
			clearResults();
			search.value = '';
			setStatus('');
		};

		const linkResult = async (button) => {
			const base = ownerContext();
			const id = Number.parseInt(button.dataset.resultId || '0', 10) || 0;
			const type = button.dataset.resultType || '';
			const payload = {
				action: config.actions?.link || '',
				view,
				owner_type: view === 'document' ? type : base.ownerType,
				owner_id: String(view === 'document' ? id : base.ownerId),
				document_id: String(view === 'document' ? base.documentId : id),
				relation_type: context?.value?.trim() || '',
			};

			button.disabled = true;
			try {
				const data = await post(payload);
				refreshLinked(data?.html || '');
			} catch (error) {
				setStatus('');
			} finally {
				button.disabled = false;
			}
		};

		const unlink = async (button) => {
			button.disabled = true;
			try {
				const data = await post({
					action: config.actions?.unlink || '',
					view,
					owner_type: button.dataset.ownerType || '',
					owner_id: button.dataset.ownerId || '0',
					document_id: button.dataset.documentId || '0',
				});
				refreshLinked(data?.html || '');
			} catch (error) {
				setStatus('');
			} finally {
				button.disabled = false;
			}
		};

		searchButton.addEventListener('click', runSearch);
		search.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				runSearch();
			}
		});
		ownerTypeSelect?.addEventListener('change', () => {
			clearResults();
			setStatus('');
		});

		panel.addEventListener('click', (event) => {
			const target = event.target;
			if (!(target instanceof Element)) {
				return;
			}
			const linkButton = target.closest('[data-cb-crm-docs-link]');
			if (linkButton instanceof HTMLButtonElement) {
				linkResult(linkButton);
				return;
			}
			const unlinkButton = target.closest('[data-cb-crm-docs-unlink]');
			if (unlinkButton instanceof HTMLButtonElement) {
				unlink(unlinkButton);
			}
		});
	};

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('[data-cb-crm-docs-panel]').forEach(initPanel);
	});
})();
