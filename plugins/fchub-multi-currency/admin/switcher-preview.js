/**
 * FCHub Multi-Currency — Switcher Preview Component
 *
 * Standalone Vue component that renders a live preview of the currency
 * switcher widget using the same CSS classes as the frontend. Reacts
 * instantly to setting changes — no save-and-reload cycle needed.
 *
 * Reads flag assets and display currencies from the fchubMcAdmin config
 * object; the footer (freshness badge, rate value, checkout note) is derived
 * from the rates the settings page already loaded, so the preview reports
 * real state instead of sample copy. Exposes window.FchubMcSwitcherPreview.
 */

(() => {
	var config = window.fchubMcAdmin || {};
	var flagBaseUrl = config.flag_base_url || "";
	var flagMap = config.flag_map || {};
	var initialDisplayCurrencies = config.display_currencies || [];

	var PREVIEW_MAX = 4;

	var FALLBACK_CURRENCIES = [
		{ code: "USD", name: "US Dollar", symbol: "$", flagUrl: `${flagBaseUrl}us.svg` },
		{ code: "EUR", name: "Euro", symbol: "€", flagUrl: `${flagBaseUrl}eu.svg` },
		{ code: "GBP", name: "British Pound", symbol: "£", flagUrl: `${flagBaseUrl}gb.svg` },
	];

	function mapCurrency(c) {
		var countryCode = flagMap[c.code] || null;
		return {
			code: c.code,
			name: c.name,
			symbol: c.symbol,
			flagUrl: countryCode ? `${flagBaseUrl + countryCode}.svg` : "",
		};
	}

	// The rates REST payload timestamps rows as UTC "YYYY-MM-DD HH:MM:SS".
	function parseFetchedAt(row) {
		var ts = Date.parse(`${String(row?.fetched_at || "").replace(" ", "T")}Z`);
		return Number.isNaN(ts) ? null : ts;
	}

	// Mirrors human_time_diff: one-minute floor, largest sensible unit.
	var TIME_TIERS = [
		[3600, 60, "minute"],
		[86400, 3600, "hour"],
		[604800, 86400, "day"],
		[Infinity, 604800, "week"],
	];

	function timeAgo(ageSeconds) {
		var tier = TIME_TIERS.find(([limit]) => ageSeconds < limit);
		var count = Math.max(1, Math.round(ageSeconds / tier[1]));
		return `${count} ${tier[2]}${count === 1 ? "" : "s"}`;
	}

	window.FchubMcSwitcherPreview = {
		name: "SwitcherPreview",
		props: {
			settings: { type: Object, required: true },
			currencies: { type: Array, default: () => initialDisplayCurrencies },
			rates: { type: Array, default: () => [] },
		},
		computed: {
			s: function () {
				return this.settings;
			},
			previewCurrencies: function () {
				var src = this.currencies?.length ? this.currencies : initialDisplayCurrencies;
				if (!src?.length) return FALLBACK_CURRENCIES;
				return src.map(mapCurrency);
			},
			currentCurrency: function () {
				return this.previewCurrencies[0] || FALLBACK_CURRENCIES[0];
			},
			sortedCurrencies: function () {
				let all = this.previewCurrencies.slice();
				const favs = this.s.favorite_currencies || [];
				if (this.s.show_favorites_first === "yes" && favs.length) {
					const favSet = new Set(favs);
					const top = [];
					const rest = [];
					all.forEach((c) => {
						if (favSet.has(c.code)) top.push(c);
						else rest.push(c);
					});
					all = top.concat(rest);
				}
				return all.slice(0, PREVIEW_MAX);
			},
			totalCurrencyCount: function () {
				var src = this.currencies?.length ? this.currencies : initialDisplayCurrencies;
				return src?.length ? src.length : FALLBACK_CURRENCIES.length;
			},
			isTruncated: function () {
				return this.totalCurrencyCount > PREVIEW_MAX;
			},
			widgetClass: function () {
				var cls = "fchub-mc-switcher fchub-mc-switcher--preview-open";
				var preset = this.s.preset || "default";
				if (preset !== "default") cls += ` fchub-mc-switcher--preset-${preset}`;
				cls += ` fchub-mc-switcher--size-${this.s.size || "md"}`;
				if (this.s.width_mode === "full") cls += " fchub-mc-switcher--width-full";
				var pos = this.s.dropdown_position || "auto";
				if (pos !== "auto") cls += ` fchub-mc-switcher--dropdown-${pos}`;
				if (this.s.dropdown_direction === "up") cls += " fchub-mc-switcher--direction-up";
				return cls;
			},
			stageClass: function () {
				var cls = "fchub-mc-admin-preview__stage fchub-mc-switcher-stage";
				cls += " fchub-mc-switcher-stage--center";
				var lp = this.s.label_position || "before";
				cls += ` fchub-mc-switcher-stage--label-${lp}`;
				return cls;
			},
			label: () => "",
			labelFirst: function () {
				var lp = this.s.label_position || "before";
				return lp === "before" || lp === "above";
			},
			hasSep: function () {
				return (
					(this.s.show_option_codes === "yes" || this.s.show_option_symbols === "yes") &&
					this.s.show_option_names === "yes"
				);
			},
			rateAgeText: function () {
				var newest = null;
				(this.rates || []).forEach((row) => {
					var ts = parseFetchedAt(row);
					if (ts !== null && (newest === null || ts > newest)) newest = ts;
				});
				if (newest === null) return null;
				var age = Math.max(0, Math.round((Date.now() - newest) / 1000));
				return `Rates updated ${timeAgo(age)} ago`;
			},
			rateLine: function () {
				var row = (this.rates || [])[0];
				if (!row?.base_currency || !row.rate || !row.quote_currency) return null;
				return `1 ${row.base_currency} = ${row.rate} ${row.quote_currency}`;
			},
			contextNote: function () {
				var base = (this.rates || [])[0]?.base_currency;
				return base
					? `Display prices only. Checkout charged in ${base}.`
					: "Display prices only. Checkout is charged in the store currency.";
			},
			hasFooter: function () {
				return (
					(this.s.show_rate_badge === "yes" && this.rateAgeText !== null) ||
					(this.s.show_rate_value === "yes" && this.rateLine !== null) ||
					this.s.show_context_note === "yes"
				);
			},
		},
		methods: {
			optionClass: (_c, i) => {
				var cls = "fchub-mc-switcher__option";
				if (i === 0) cls += " fchub-mc-switcher__option--active";
				return cls;
			},
		},
		template:
			'\
<div class="fchub-mc-admin-preview">\
	<div class="fchub-mc-admin-preview__header">Preview</div>\
	<div v-if="isTruncated" class="fchub-mc-admin-preview__note">Showing {{ sortedCurrencies.length }} of {{ totalCurrencyCount }} currencies. The full list appears on the frontend — this is just a taste.</div>\
	<div :class="stageClass">\
		<span v-if="label && labelFirst" class="fchub-mc-switcher__label">{{ label }}</span>\
		<span :class="widgetClass">\
			<button class="fchub-mc-switcher__trigger" type="button" disabled>\
				<span v-if="s.show_flag===\'yes\' && currentCurrency.flagUrl" class="fchub-mc-switcher__flag"><img :src="currentCurrency.flagUrl" class="fchub-mc-flag" :alt="currentCurrency.code" width="20" height="15" /></span>\
				<span v-if="s.show_code===\'yes\'" class="fchub-mc-switcher__code">{{ currentCurrency.code }}</span>\
				<span v-if="s.show_symbol===\'yes\'" class="fchub-mc-switcher__symbol">{{ currentCurrency.symbol }}</span>\
				<span v-if="s.show_name===\'yes\'" class="fchub-mc-switcher__name">{{ currentCurrency.name }}</span>\
				<span class="fchub-mc-switcher__caret">▼</span>\
			</button>\
			<span class="fchub-mc-switcher__dropdown">\
				<span v-if="s.search_mode===\'inline\'" class="fchub-mc-switcher__search-wrap">\
					<input type="search" class="fchub-mc-switcher__search" disabled placeholder="Search currency" />\
				</span>\
				<span class="fchub-mc-switcher__list" role="listbox">\
					<span v-for="(c, i) in sortedCurrencies" :key="c.code" :class="optionClass(c, i)" role="option">\
						<span v-if="s.show_option_flags===\'yes\' && c.flagUrl" class="fchub-mc-switcher__flag"><img :src="c.flagUrl" class="fchub-mc-flag" :alt="c.code" width="20" height="15" /></span>\
						<span v-if="s.show_option_codes===\'yes\'" class="fchub-mc-switcher__option-code">{{ c.code }}</span>\
						<span v-if="s.show_option_symbols===\'yes\'" class="fchub-mc-switcher__option-symbol">{{ c.symbol }}</span>\
						<span v-if="hasSep" class="fchub-mc-switcher__option-sep">—</span>\
						<span v-if="s.show_option_names===\'yes\'" class="fchub-mc-switcher__option-name">{{ c.name }}</span>\
						<span v-if="s.show_active_indicator===\'yes\'" class="fchub-mc-switcher__option-check">{{ i === 0 ? \'✓\' : \'\' }}</span>\
					</span>\
				</span>\
				<span v-if="hasFooter" class="fchub-mc-switcher__footer">\
					<span v-if="s.show_rate_badge===\'yes\' && rateAgeText" class="fchub-mc-rate-badge">\
						<span class="fchub-mc-rate-badge__dot"></span> {{ rateAgeText }}\
					</span>\
					<span v-if="s.show_rate_value===\'yes\' && rateLine" class="fchub-mc-rate-context">{{ rateLine }}</span>\
					<span v-if="s.show_context_note===\'yes\'" class="fchub-mc-rate-context">{{ contextNote }}</span>\
				</span>\
			</span>\
		</span>\
		<span v-if="label && !labelFirst" class="fchub-mc-switcher__label">{{ label }}</span>\
	</div>\
</div>',
	};
})();
