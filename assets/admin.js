(() => {
	'use strict';

	const config = window.cbCrmAdmin || {};

	const initRepeaters = () => {
		document.querySelectorAll('[data-cb-crm-repeater]').forEach((repeater) => {
			const rows = repeater.querySelector('[data-cb-crm-rows]');
			const template = repeater.querySelector('template');
			const addButton = repeater.querySelector('[data-cb-crm-add-row]');
			if (!rows || !template || !addButton) {
				return;
			}

			addButton.addEventListener('click', () => {
				const index = Number.parseInt(repeater.dataset.nextIndex || '0', 10);
				rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
				repeater.dataset.nextIndex = String(index + 1);
			});

			repeater.addEventListener('click', (event) => {
				const button = event.target.closest('[data-cb-crm-remove-row]');
				if (!button) {
					return;
				}
				const row = button.closest('[data-cb-crm-row]');
				if (row) {
					row.remove();
				}
			});
		});
	};

	const initSourceOverrides = () => {
		document.querySelectorAll('[data-cb-crm-source-override]').forEach((toggle) => {
			const targetId = toggle.dataset.target || '';
			const input = targetId ? document.getElementById(targetId) : null;
			if (!input) {
				return;
			}

			const sync = () => {
				if (toggle.checked) {
					input.readOnly = false;
					if (input.dataset.overrideValue) {
						input.value = input.dataset.overrideValue;
					}
					return;
				}
				if (!input.readOnly && input.value !== (input.dataset.externalValue || '')) {
					input.dataset.overrideValue = input.value;
				}
				input.readOnly = true;
				input.value = input.dataset.externalValue || '';
			};

			input.addEventListener('input', () => {
				if (toggle.checked) {
					input.dataset.overrideValue = input.value;
				}
			});
			toggle.addEventListener('change', sync);
			sync();
		});
	};

	const initUserPicker = () => {
		const picker = document.querySelector('[data-cb-crm-user-picker]');
		if (!picker || !config.ajaxUrl) {
			return;
		}

		const hidden = picker.querySelector('[data-cb-crm-user-id]');
		const search = picker.querySelector('[data-cb-crm-user-search]');
		const results = picker.querySelector('[data-cb-crm-user-results]');
		const selected = picker.querySelector('[data-cb-crm-user-selected]');
		const selectedName = picker.querySelector('[data-cb-crm-user-selected-name]');
		const selectedEmail = picker.querySelector('[data-cb-crm-user-selected-email]');
		const remove = picker.querySelector('[data-cb-crm-user-remove]');
		const emailMode = document.querySelector('[data-cb-crm-email-mode]');
		const wpEmailOption = emailMode ? emailMode.querySelector('option[value="wp_user"]') : null;
		let timer = 0;
		let controller = null;

		const syncEmailOption = () => {
			const hasUser = hidden && Number.parseInt(hidden.value || '0', 10) > 0;
			if (wpEmailOption) {
				wpEmailOption.disabled = !hasUser;
			}
			if (!hasUser && emailMode && emailMode.value === 'wp_user') {
				emailMode.value = 'crm';
			}
		};

		const clearResults = () => {
			if (!results) {
				return;
			}
			results.innerHTML = '';
			results.hidden = true;
		};

		const selectUser = (user) => {
			if (!hidden || !selected || !selectedName || !selectedEmail) {
				return;
			}
			hidden.value = String(user.id);
			selectedName.textContent = user.name || user.login || '';
			selectedEmail.textContent = user.email || '';
			selected.hidden = false;
			if (search) {
				search.value = '';
			}
			clearResults();
			syncEmailOption();
		};

		const renderResults = (users) => {
			if (!results) {
				return;
			}
			results.innerHTML = '';
			if (!Array.isArray(users) || users.length === 0) {
				const empty = document.createElement('div');
				empty.className = 'cb-crm-user-result-empty';
				empty.textContent = config.i18n?.noUsers || 'No matching WordPress users found.';
				results.appendChild(empty);
				results.hidden = false;
				return;
			}

			users.forEach((user) => {
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'cb-crm-user-result';
				const name = document.createElement('strong');
				name.textContent = user.name || user.login || '';
				const email = document.createElement('span');
				email.textContent = user.email || '';
				button.append(name, email);
				button.addEventListener('click', () => selectUser(user));
				results.appendChild(button);
			});
			results.hidden = false;
		};

		const runSearch = async (term) => {
			if (controller) {
				controller.abort();
			}
			controller = new AbortController();
			if (results) {
				results.innerHTML = `<div class="cb-crm-user-result-empty">${config.i18n?.searching || 'Searching…'}</div>`;
				results.hidden = false;
			}
			const body = new URLSearchParams({
				action: 'cb_crm_search_users',
				nonce: config.nonce || '',
				term,
				contact_id: String(config.contactId || 0),
			});
			try {
				const response = await fetch(config.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: body.toString(),
					credentials: 'same-origin',
					signal: controller.signal,
				});
				const payload = await response.json();
				if (!response.ok || !payload.success) {
					throw new Error('search_failed');
				}
				renderResults(payload.data);
			} catch (error) {
				if (error.name === 'AbortError') {
					return;
				}
				if (results) {
					results.innerHTML = '';
					const message = document.createElement('div');
					message.className = 'cb-crm-user-result-empty';
					message.textContent = config.i18n?.error || 'WordPress user search failed. Try again.';
					results.appendChild(message);
					results.hidden = false;
				}
			}
		};

		if (search) {
			search.addEventListener('input', () => {
				window.clearTimeout(timer);
				const term = search.value.trim();
				if (term.length < 2) {
					clearResults();
					return;
				}
				timer = window.setTimeout(() => runSearch(term), 250);
			});
		}

		if (remove) {
			remove.addEventListener('click', () => {
				if (hidden) {
					hidden.value = '0';
				}
				if (selected) {
					selected.hidden = true;
				}
				syncEmailOption();
			});
		}

		document.addEventListener('click', (event) => {
			if (!picker.contains(event.target)) {
				clearResults();
			}
		});

		syncEmailOption();
	};

	document.addEventListener('DOMContentLoaded', () => {
		initRepeaters();
		initSourceOverrides();
		initUserPicker();
	});
})();
