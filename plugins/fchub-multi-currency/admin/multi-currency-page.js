/**
 * FCHub Multi-Currency — Admin settings page for FluentCart's SPA.
 *
 * Entry file: registers the /multi-currency route via the fluent_cart_routes
 * filter, owns the REST plumbing and page shell (tab strip + save), and
 * injects the settings-sidebar item. The per-tab views live in
 * admin/components/*.js and attach to window.FchubMcAdmin.components; the
 * WordPress dependency chain loads them before this file runs.
 *
 * No build step — Options API with runtime template strings and the Element
 * Plus components FluentCart registers globally. The tab strip is deliberately
 * plugin-owned: el-tabs positions its underline by measuring nav items from
 * watcher/resize callbacks, which lags one tab behind inside this runtime
 * context, so the active state here is a single class binding instead.
 */

(() => {
	/* ------------------------------------------------------------------ */
	/*  REST helper                                                        */
	/* ------------------------------------------------------------------ */

	var config = window.fchubMcAdmin || {};
	var __ = window.wp.i18n.__;

	function restUrl(path) {
		return (config.rest_url || "/wp-json/fchub-mc/v1/") + path;
	}

	function request(method, path, body) {
		var opts = {
			method: method === "PUT" ? "POST" : method,
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.nonce || "",
			},
			credentials: "same-origin",
		};
		if (method === "PUT") {
			opts.headers["X-HTTP-Method-Override"] = "PUT";
		}
		if (body) {
			opts.body = JSON.stringify(body);
		}
		return fetch(restUrl(path), opts)
			.catch(() => {
				throw {
					message: __("Network error. Please check your connection.", "fchub-multi-currency"),
				};
			})
			.then((res) =>
				res.json().then((json) => {
					if (!res.ok) throw json;
					return json.data || json;
				}),
			);
	}

	/* ------------------------------------------------------------------ */
	/*  Main page component                                                */
	/* ------------------------------------------------------------------ */

	var components = window.FchubMcAdmin?.components || {};

	var MultiCurrencyPage = {
		name: "MultiCurrencyPage",
		components: {
			GeneralSettings: components.GeneralSettings,
			CurrencySettings: components.CurrencySettings,
			SwitcherSettings: components.SwitcherSettings,
			RateSettings: components.RateSettings,
			CheckoutSettings: components.CheckoutSettings,
			CrmSettings: components.CrmSettings,
			DiagnosticsView: components.DiagnosticsView,
		},
		data: () => ({
			activeTab: "general",
			i18n: {
				save: __("Save", "fchub-multi-currency"),
				saving: __("Saving...", "fchub-multi-currency"),
			},
			tabs: [
				{ name: "general", label: __("General", "fchub-multi-currency") },
				{ name: "currencies", label: __("Currencies", "fchub-multi-currency") },
				{ name: "rates", label: __("Exchange Rates", "fchub-multi-currency") },
				{ name: "switcher", label: __("Switcher", "fchub-multi-currency") },
				{ name: "checkout", label: __("Checkout", "fchub-multi-currency") },
				{ name: "crm", label: __("CRM", "fchub-multi-currency") },
				{ name: "diagnostics", label: __("Diagnostics", "fchub-multi-currency") },
			],
			loading: true,
			saving: false,
			ratesLoading: false,
			manualRatesSaving: false,
			diagLoading: false,
			crmFieldsCreating: false,
			settings: {},
			rates: [],
			manualRates: {},
			quoteCurrencies: [],
			savedRateProvider: "",
			diagnostics: {},
		}),
		mounted: function () {
			this.loadSettings();
			this.loadRates();
			if (typeof this.changeTitle === "function") {
				this.changeTitle(__("Multi-Currency", "fchub-multi-currency"));
			}
			document.addEventListener("keydown", this.onKeyDown);
		},
		beforeUnmount: function () {
			document.removeEventListener("keydown", this.onKeyDown);
		},
		watch: {
			activeTab: function (tab) {
				if (tab === "diagnostics" && !this.diagnostics.plugin_version) {
					this.loadDiagnostics();
				}
			},
		},
		methods: {
			loadSettings: function () {
				this.loading = true;
				request("GET", "admin/settings")
					.then((data) => {
						this.settings = data.settings || data;
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Failed to load settings.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.loading = false;
					});
			},
			loadRates: function () {
				this.ratesLoading = true;
				return request("GET", "admin/rates")
					.then((data) => {
						this.rates = data.rates || [];
						this.quoteCurrencies = data.quote_currencies || [];
						this.savedRateProvider = data.provider || "";
						var currentRates = {};
						this.rates.forEach((rate) => {
							currentRates[rate.quote_currency] = String(rate.rate || "");
						});
						var manualRates = {};
						this.quoteCurrencies.forEach((code) => {
							manualRates[code] = currentRates[code] || "";
						});
						this.manualRates = manualRates;
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Failed to load exchange rates.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.ratesLoading = false;
					});
			},
			loadDiagnostics: function () {
				this.diagLoading = true;
				request("GET", "admin/diagnostics")
					.then((data) => {
						this.diagnostics = data;
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Failed to load diagnostics.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.diagLoading = false;
					});
			},
			createCrmFields: function () {
				this.crmFieldsCreating = true;
				return request("POST", "admin/diagnostics/crm-fields")
					.then((data) => {
						this.diagnostics = Object.assign({}, this.diagnostics, {
							fluentcrm_fields_status: data.fluentcrm_fields_status,
						});
						this.$message.success(__("FluentCRM fields created.", "fchub-multi-currency"));
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Could not create the FluentCRM fields.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.crmFieldsCreating = false;
					});
			},
			refreshRates: function () {
				this.ratesLoading = true;
				request("POST", "admin/rates/refresh")
					.then(() => {
						this.$message.success(__("Rates refreshed.", "fchub-multi-currency"));
						this.loadRates();
					})
					.catch((err) => {
						this.$message.error(err?.message || __("Rate refresh failed.", "fchub-multi-currency"));
						this.ratesLoading = false;
					});
			},
			saveManualRates: function () {
				this.manualRatesSaving = true;
				return request("POST", "admin/rates/manual", { rates: this.manualRates })
					.then((data) => {
						this.rates = data.rates || [];
						var savedRates = {};
						this.rates.forEach((rate) => {
							savedRates[rate.quote_currency] = String(rate.rate || "");
						});
						this.manualRates = savedRates;
						this.$message.success(__("Manual rates saved.", "fchub-multi-currency"));
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Manual rates could not be saved.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.manualRatesSaving = false;
					});
			},
			saveSettings: function () {
				this.saving = true;
				request("POST", "admin/settings", this.settings)
					.then((data) => {
						this.settings = data.settings || data;
						this.$message.success(__("Settings saved.", "fchub-multi-currency"));
						return this.loadRates();
					})
					.catch((err) => {
						this.$message.error(
							err?.message || __("Failed to save settings.", "fchub-multi-currency"),
						);
					})
					.finally(() => {
						this.saving = false;
					});
			},
			onKeyDown: function (e) {
				if ((e.metaKey || e.ctrlKey) && e.key === "s") {
					e.preventDefault();
					this.saveSettings();
				}
			},
		},
		template:
			'\
<div class="setting-wrap fchub-mc-page">\
    <div class="fct-setting-header">\
        <div class="fct-setting-header-content">\
            <h3 class="fct-setting-head-title">' +
			__("Multi-Currency", "fchub-multi-currency") +
			'</h3>\
        </div>\
        <div class="fct-setting-header-action">\
            <el-button type="primary" size="small" :loading="saving" @click="saveSettings">\
                <span v-if="!saving" class="cmd">⌘S</span>\
                {{ saving ? i18n.saving : i18n.save }}\
            </el-button>\
        </div>\
    </div>\
    <div class="setting-wrap-inner">\
        <div class="fchub-mc-tabs" role="tablist" aria-label="' +
			__("Multi-Currency settings", "fchub-multi-currency") +
			'">\
            <button v-for="tab in tabs" :key="tab.name" type="button" role="tab" :aria-selected="activeTab === tab.name ? \'true\' : \'false\'" :class="[\'fchub-mc-tabs__item\', { \'is-active\': activeTab === tab.name }]" @click="activeTab = tab.name">{{ tab.label }}</button>\
        </div>\
        <div v-show="activeTab === \'general\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                <general-settings :settings="settings" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'currencies\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                <currency-settings :settings="settings" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'rates\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body">\
                <rate-settings :settings="settings" :rates="rates" :rates-loading="ratesLoading" :manual-rates="manualRates" :manual-rates-saving="manualRatesSaving" :quote-currencies="quoteCurrencies" :saved-rate-provider="savedRateProvider" @refresh-rates="refreshRates" @save-manual-rates="saveManualRates" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'switcher\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                <switcher-settings :settings="settings" :rates="rates" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'checkout\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                <checkout-settings :settings="settings" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'crm\'" role="tabpanel">\
            <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                <crm-settings :settings="settings" />\
            </div></div></div>\
        </div>\
        <div v-show="activeTab === \'diagnostics\'" role="tabpanel">\
            <diagnostics-view :diagnostics="diagnostics" :loading="diagLoading" :crm-fields-creating="crmFieldsCreating" @create-crm-fields="createCrmFields" />\
        </div>\
    </div>\
</div>',
	};

	/* ------------------------------------------------------------------ */
	/*  Route registration                                                 */
	/* ------------------------------------------------------------------ */

	window.fluent_cart_admin.hooks.addFilter(
		"fluent_cart_routes",
		"fchub_multi_currency",
		(routes) => {
			if (routes.settings?.children) {
				routes.settings.children.push({
					name: "multi_currency",
					path: "multi-currency",
					component: MultiCurrencyPage,
					meta: {
						active_menu: "settings",
						title: __("Multi-Currency", "fchub-multi-currency"),
					},
				});
			}
			return routes;
		},
	);

	/* ------------------------------------------------------------------ */
	/*  Inject "Multi-Currency" into the settings sidebar (DOM)            */
	/*  Inserts a tab item before the Tax Settings entry so it groups      */
	/*  logically with other financial settings.                           */
	/* ------------------------------------------------------------------ */

	var MC_HASH = "#/settings/multi-currency";
	var MC_ICON =
		'<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="8.25" stroke="currentColor" stroke-width="1.5"/><path d="M2 10h16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M10 2a12.24 12.24 0 0 1 3.2 8 12.24 12.24 0 0 1-3.2 8 12.24 12.24 0 0 1-3.2-8A12.24 12.24 0 0 1 10 2z" stroke="currentColor" stroke-width="1.5"/></svg>';
	var MC_CHEVRON =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 14" fill="none"><path d="M1 13L6.29289 7.70711C6.62623 7.37377 6.79289 7.20711 6.79289 7C6.79289 6.79289 6.62623 6.62623 6.29289 6.29289L1 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	function isMultiCurrencyRoute() {
		return window.location.hash.indexOf("/settings/multi-currency") !== -1;
	}

	function updateActiveState(navItem) {
		if (isMultiCurrencyRoute()) {
			navItem.classList.add("fct-settings-nav-item-active");
		} else {
			navItem.classList.remove("fct-settings-nav-item-active");
		}
	}

	function injectSettingsSidebarItem() {
		var navList = document.querySelector(".fct-settings-nav");
		if (!navList) return false;

		if (navList.querySelector(".fct-settings-nav-item-multi-currency")) return true;

		// Build: <li class="fct-settings-nav-item">
		var navItem = document.createElement("li");
		navItem.className = "fct-settings-nav-item fct-settings-nav-item-multi-currency";
		if (isMultiCurrencyRoute()) {
			navItem.classList.add("fct-settings-nav-item-active");
		}

		// Build: <a class="fct-settings-nav-link" href="#/settings/multi-currency">
		var link = document.createElement("a");
		link.className = "fct-settings-nav-link";
		link.href = MC_HASH;

		// Icon wrapper: <div class="icon">SVG</div>
		var iconDiv = document.createElement("div");
		iconDiv.className = "icon";
		iconDiv.innerHTML = MC_ICON;

		// Label + chevron: <span class="fct-settings-nav-link-text">Multi-Currency <div class="icon fct-settings-nav-link-icon">chevron</div></span>
		var labelSpan = document.createElement("span");
		labelSpan.className = "fct-settings-nav-link-text";
		labelSpan.textContent = __("Multi-Currency", "fchub-multi-currency");

		var chevronDiv = document.createElement("div");
		chevronDiv.className = "icon fct-settings-nav-link-icon";
		chevronDiv.innerHTML = MC_CHEVRON;
		labelSpan.appendChild(chevronDiv);

		link.appendChild(iconDiv);
		link.appendChild(labelSpan);
		navItem.appendChild(link);

		// Insert before the Tax Settings entry, matched by its route href so
		// translated admins resolve it too. Unknown layouts append at the end.
		let inserted = false;
		const items = navList.querySelectorAll(":scope > .fct-settings-nav-item");
		for (let i = 0; i < items.length; i++) {
			const itemLink = items[i].querySelector(".fct-settings-nav-link");
			const href = itemLink?.getAttribute("href") || "";
			if (href.indexOf("/settings/tax_settings") !== -1) {
				navList.insertBefore(navItem, items[i]);
				inserted = true;
				break;
			}
		}
		if (!inserted) {
			navList.appendChild(navItem);
		}

		// Active state tracking
		window.addEventListener("hashchange", () => {
			updateActiveState(navItem);
		});

		return true;
	}

	var sidebarRetries = 0;
	function tryInjectSidebar() {
		if (!injectSettingsSidebarItem() && sidebarRetries++ < 100) {
			requestAnimationFrame(tryInjectSidebar);
		}
	}

	// Start injection when navigating to settings
	function onHashChange() {
		if (window.location.hash.indexOf("#/settings") === 0) {
			tryInjectSidebar();
		}
	}

	window.addEventListener("hashchange", onHashChange);

	if (window.location.hash.indexOf("#/settings") === 0) {
		if (document.readyState === "loading") {
			document.addEventListener("DOMContentLoaded", () => {
				tryInjectSidebar();
			});
		} else {
			tryInjectSidebar();
		}
	}
})();
