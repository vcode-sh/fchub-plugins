/**
 * FCHub Multi-Currency — Currencies tab.
 *
 * Which currencies the store displays and how each one is formatted:
 * add/remove, drag-to-reorder (SortableJS), symbol, decimals, separators
 * and symbol position per currency.
 */

(() => {
	const { __ } = window.wp.i18n;
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	const catalogue = window.fchubMcAdmin?.currency_catalogue || [];
	const catalogueMap = {};
	catalogue.forEach((c) => {
		catalogueMap[c.code] = c;
	});

	components.CurrencySettings = {
		name: "CurrencySettings",
		props: { settings: { type: Object, required: true } },
		data: () => ({ pickerValue: "", catalogueMap: catalogueMap }),
		computed: {
			availableCurrencies: function () {
				var added = {};
				(this.settings.display_currencies || []).forEach((d) => {
					added[d.code] = true;
				});
				return catalogue.filter((c) => !added[c.code]);
			},
		},
		mounted: function () {
			this.$nextTick(this.initSortable);
		},
		updated: function () {
			this.$nextTick(this.initSortable);
		},
		beforeUnmount: function () {
			if (this._sortable) {
				this._sortable.destroy();
				this._sortable = null;
			}
		},
		methods: {
			initSortable: function () {
				var el = this.$refs.sortableBody;
				if (!el) return;
				if (!window.Sortable) {
					console.warn("[fchub-mc] SortableJS not loaded — drag-and-drop disabled");
					return;
				}
				if (this._sortable) return;
				this._sortable = window.Sortable.create(el, {
					handle: ".fchub-mc-drag-handle",
					animation: 200,
					ghostClass: "fchub-mc-ghost",
					chosenClass: "fchub-mc-chosen",
					onEnd: (evt) => {
						if (evt.oldIndex === evt.newIndex) return;
						var item = evt.item;
						var from = evt.from;
						from.removeChild(item);
						from.insertBefore(item, from.children[evt.oldIndex] || null);
						var arr = this.settings.display_currencies;
						var moved = arr.splice(evt.oldIndex, 1)[0];
						arr.splice(evt.newIndex, 0, moved);
					},
				});
			},
			onPick: function (code) {
				if (!code) return;
				var entry = catalogueMap[code];
				if (!entry) return;
				if (!this.settings.display_currencies) {
					this.settings.display_currencies = [];
				}
				this.settings.display_currencies.push({
					code: entry.code,
					name: entry.name,
					symbol: entry.symbol,
					decimals: entry.decimals,
					position: "left",
					decimal_separator: "",
					thousand_separator: "",
				});
				this.pickerValue = "";
				if (this._sortable) {
					this._sortable.destroy();
					this._sortable = null;
				}
			},
			removeCurrency: function (index) {
				var removed = this.settings.display_currencies.splice(index, 1)[0];
				if (removed && removed.code === this.settings.default_display_currency) {
					this.settings.default_display_currency = this.settings.base_currency;
				}
				if (this._sortable) {
					this._sortable.destroy();
					this._sortable = null;
				}
			},
		},
		template:
			'\
<div>\
    <div style="margin-bottom:20px">\
        <el-select\
            v-model="pickerValue"\
            filterable\
            placeholder="' +
			__("Search and add a currency…", "fchub-multi-currency") +
			'"\
            style="width:100%;max-width:420px"\
            @change="onPick"\
        >\
            <el-option\
                v-for="c in availableCurrencies"\
                :key="c.code"\
                :label="c.flag + \' \' + c.code + \' \\u2014 \' + c.name"\
                :value="c.code"\
            />\
        </el-select>\
    </div>\
    <div v-if="settings.display_currencies && settings.display_currencies.length" class="fchub-mc-currency-list">\
        <div class="fchub-mc-currency-header">\
            <div class="fchub-mc-col-handle"></div>\
            <div class="fchub-mc-col-currency">' +
			__("Currency", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-symbol">' +
			__("Symbol", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-decimals">' +
			__("Decimals", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-separator">' +
			__("Decimal", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-separator">' +
			__("Thousands", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-position">' +
			__("Position", "fchub-multi-currency") +
			'</div>\
            <div class="fchub-mc-col-action"></div>\
        </div>\
        <div ref="sortableBody" class="fchub-mc-currency-body">\
            <div v-for="(row, index) in settings.display_currencies" :key="row.code" class="fchub-mc-currency-row">\
                <div class="fchub-mc-col-handle">\
                    <span class="fchub-mc-drag-handle" title="' +
			__("Drag to reorder", "fchub-multi-currency") +
			'">\
                        <svg width="10" height="16" viewBox="0 0 10 16" fill="currentColor"><circle cx="2" cy="2" r="1.5"/><circle cx="8" cy="2" r="1.5"/><circle cx="2" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="2" cy="14" r="1.5"/><circle cx="8" cy="14" r="1.5"/></svg>\
                    </span>\
                </div>\
                <div class="fchub-mc-col-currency">\
                    <span class="fchub-mc-flag">{{ (catalogueMap[row.code] || {}).flag }}</span>\
                    <strong>{{ row.code }}</strong>\
                    <span class="fchub-mc-currency-name">{{ row.name }}</span>\
                </div>\
                <div class="fchub-mc-col-symbol">\
                    <el-input v-model="row.symbol" size="small" autocomplete="one-time-code" />\
                </div>\
                <div class="fchub-mc-col-decimals">\
                    <el-input-number v-model="row.decimals" size="small" :min="0" :max="4" />\
                </div>\
                <div class="fchub-mc-col-separator">\
                    <el-select v-model="row.decimal_separator" size="small">\
                        <el-option label="' +
			__("Auto", "fchub-multi-currency") +
			'" value="" />\
                        <el-option label="' +
			__("Dot (.)", "fchub-multi-currency") +
			'" value="." />\
                        <el-option label="' +
			__("Comma (,)", "fchub-multi-currency") +
			'" value="," />\
                    </el-select>\
                </div>\
                <div class="fchub-mc-col-separator">\
                    <el-select v-model="row.thousand_separator" size="small">\
                        <el-option label="' +
			__("Auto", "fchub-multi-currency") +
			'" value="" />\
                        <el-option label="' +
			__("Comma (,)", "fchub-multi-currency") +
			'" value="," />\
                        <el-option label="' +
			__("Dot (.)", "fchub-multi-currency") +
			'" value="." />\
                        <el-option label="' +
			__("Space", "fchub-multi-currency") +
			'" value=" " />\
                        <el-option label="' +
			__("None", "fchub-multi-currency") +
			'" value="none" />\
                    </el-select>\
                </div>\
                <div class="fchub-mc-col-position">\
                    <el-select v-model="row.position" size="small">\
                        <el-option label="' +
			__("Left ($100)", "fchub-multi-currency") +
			'" value="left" />\
                        <el-option label="' +
			__("Right (100$)", "fchub-multi-currency") +
			'" value="right" />\
                        <el-option label="' +
			__("Left space ($ 100)", "fchub-multi-currency") +
			'" value="left_space" />\
                        <el-option label="' +
			__("Right space (100 $)", "fchub-multi-currency") +
			'" value="right_space" />\
                    </el-select>\
                </div>\
                <div class="fchub-mc-col-action">\
                    <el-button type="danger" size="small" text @click="removeCurrency(index)">&times;</el-button>\
                </div>\
            </div>\
        </div>\
    </div>\
    <div v-else style="padding:40px;text-align:center;color:#909399">\
        ' +
			__(
				"No display currencies added yet. Use the picker above to add currencies.",
				"fchub-multi-currency",
			) +
			"\
    </div>\
</div>",
	};
})();
