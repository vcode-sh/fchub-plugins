(function (blocks, blockEditor, components, element, i18n) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var createBlock = blocks.createBlock;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var Notice = components.Notice;

	var editorConfig = window.fchubMcBlockEditor || {};
	var pluginSettings = editorConfig.settings || {};
	var displayCurrencies = Array.isArray(editorConfig.displayCurrencies) ? editorConfig.displayCurrencies : [];
	var catalogue = Array.isArray(editorConfig.catalogue) ? editorConfig.catalogue : [];
	var sampleCurrencies = displayCurrencies.length
		? displayCurrencies.map(function (currency) {
			var match = catalogue.find(function (item) {
				return item.code === currency.code;
			});
			return {
				code: currency.code,
				name: currency.name || (match && match.name) || currency.code,
				symbol: currency.symbol || (match && match.symbol) || currency.code,
				flag: (match && match.flag) || currency.code,
			};
		})
		: [
			{ code: "USD", name: "US Dollar", symbol: "$", flag: "🇺🇸" },
			{ code: "EUR", name: "Euro", symbol: "€", flag: "🇪🇺" },
			{ code: "GBP", name: "British Pound", symbol: "£", flag: "🇬🇧" },
		];

	var globalDefaults = editorConfig.switcherDefaults || {};

	function parseBool(value, fallbackValue) {
		if (typeof value === "boolean") {
			return value;
		}

		if (typeof value === "string") {
			var normalized = value.toLowerCase().trim();
			if (["no", "false", "0", "off"].indexOf(normalized) >= 0) {
				return false;
			}
			if (["yes", "true", "1", "on"].indexOf(normalized) >= 0) {
				return true;
			}
		}

		return fallbackValue;
	}

	function sanitizeEnum(value, allowed, fallbackValue) {
		return allowed.indexOf(value) >= 0 ? value : fallbackValue;
	}

	function normalizeLabelPosition(value) {
		switch (value) {
			case "inline":
				return "before";
			case "stacked":
				return "above";
			case "before":
			case "after":
			case "above":
			case "below":
				return value;
			default:
				return "before";
		}
	}

	function normalizePreset(value) {
		return sanitizeEnum(value || "default", ["default", "pill", "minimal", "subtle", "glass", "contrast"], "default");
	}

	function normalizeCurrencyList(value) {
		if (typeof value === "string") {
			value = value
				.split(",")
				.map(function (item) {
					return item.trim().toUpperCase();
				})
				.filter(function (item) {
					return /^[A-Z]{3}$/.test(item);
				});
		}

		if (!Array.isArray(value)) {
			return [];
		}

		var codes = [];
		value.forEach(function (item) {
			if (typeof item !== "string") {
				return;
			}
			var code = item.trim().toUpperCase();
			if (/^[A-Z]{3}$/.test(code) && codes.indexOf(code) === -1) {
				codes.push(code);
			}
		});
		return codes;
	}

	function normalizeDefaults(defaults) {
		return {
			preset: normalizePreset(defaults.preset || "default"),
			labelPosition: normalizeLabelPosition(defaults.label_position || "before"),
			showFlag: parseBool(defaults.show_flag, true),
			showCode: parseBool(defaults.show_code, true),
			showSymbol: parseBool(defaults.show_symbol, false),
			showName: parseBool(defaults.show_name, false),
			showOptionFlags: parseBool(defaults.show_option_flags, true),
			showOptionCodes: parseBool(defaults.show_option_codes, true),
			showOptionSymbols: parseBool(defaults.show_option_symbols, false),
			showOptionNames: parseBool(defaults.show_option_names, true),
			showActiveIndicator: parseBool(defaults.show_active_indicator, true),
			showRateBadge: parseBool(defaults.show_rate_badge, true),
			showRateValue: parseBool(defaults.show_rate_value, false),
			showContextNote: parseBool(defaults.show_context_note, false),
			searchMode: sanitizeEnum(defaults.search_mode || "off", ["off", "inline"], "off"),
			favoriteCurrencies: normalizeCurrencyList(defaults.favorite_currencies || []),
			showFavoritesFirst: parseBool(defaults.show_favorites_first, true),
			size: sanitizeEnum(defaults.size || "md", ["sm", "md", "lg"], "md"),
			widthMode: sanitizeEnum(defaults.width_mode || "auto", ["auto", "full"], "auto"),
			dropdownPosition: sanitizeEnum(defaults.dropdown_position || "auto", ["auto", "start", "end"], "auto"),
			dropdownDirection: sanitizeEnum(defaults.dropdown_direction || "auto", ["auto", "down", "up"], "auto"),
		};
	}

	function normalizeAttributes(attributes) {
		var defaults = normalizeDefaults(globalDefaults);
		var normalized = {
			useGlobalDefaults: parseBool(attributes.useGlobalDefaults, true),
			preset: normalizePreset(attributes.preset || defaults.preset),
			label: attributes.label || "",
			align: sanitizeEnum(attributes.align || "left", ["left", "center", "right"], "left"),
			labelPosition: normalizeLabelPosition(attributes.labelPosition || defaults.labelPosition),
			showFlag: parseBool(attributes.showFlag, defaults.showFlag),
			showCode: parseBool(attributes.showCode, defaults.showCode),
			showSymbol: parseBool(attributes.showSymbol, defaults.showSymbol),
			showName: parseBool(attributes.showName, defaults.showName),
			showRateBadge: parseBool(attributes.showRateBadge, defaults.showRateBadge),
			showOptionFlags: parseBool(attributes.showOptionFlags, defaults.showOptionFlags),
			showOptionCodes: parseBool(attributes.showOptionCodes, defaults.showOptionCodes),
			showOptionSymbols: parseBool(attributes.showOptionSymbols, defaults.showOptionSymbols),
			showOptionNames: parseBool(attributes.showOptionNames, defaults.showOptionNames),
			showActiveIndicator: parseBool(attributes.showActiveIndicator, defaults.showActiveIndicator),
			showContextNote: parseBool(attributes.showContextNote, defaults.showContextNote),
			showRateValue: parseBool(attributes.showRateValue, defaults.showRateValue),
			searchMode: sanitizeEnum(attributes.searchMode || defaults.searchMode, ["off", "inline"], "off"),
			favoriteCurrencies: normalizeCurrencyList(attributes.favoriteCurrencies || defaults.favoriteCurrencies),
			showFavoritesFirst: parseBool(attributes.showFavoritesFirst, defaults.showFavoritesFirst),
			size: sanitizeEnum(attributes.size || defaults.size, ["sm", "md", "lg"], "md"),
			widthMode: sanitizeEnum(attributes.widthMode || defaults.widthMode, ["auto", "full"], "auto"),
			dropdownPosition: sanitizeEnum(attributes.dropdownPosition || defaults.dropdownPosition, ["auto", "start", "end"], "auto"),
			dropdownDirection: sanitizeEnum(attributes.dropdownDirection || defaults.dropdownDirection, ["auto", "down", "up"], "auto"),
		};

		if (normalized.useGlobalDefaults) {
			normalized = Object.assign({}, defaults, {
				useGlobalDefaults: true,
				label: attributes.label || "",
				align: sanitizeEnum(attributes.align || "left", ["left", "center", "right"], "left"),
			});
		}

		if (!normalized.showCode && !normalized.showSymbol && !normalized.showName) {
			normalized.showCode = true;
		}

		if (!normalized.showOptionCodes && !normalized.showOptionSymbols && !normalized.showOptionNames) {
			normalized.showOptionCodes = true;
		}

		return normalized;
	}

	function prioritizeCurrencies(currencies, favoriteCurrencies, showFavoritesFirst) {
		if (!showFavoritesFirst || !favoriteCurrencies.length) {
			return currencies.slice();
		}

		var favoriteMap = {};
		favoriteCurrencies.forEach(function (code) {
			favoriteMap[code] = true;
		});

		return currencies.slice().sort(function (left, right) {
			var leftFavorite = !!favoriteMap[left.code];
			var rightFavorite = !!favoriteMap[right.code];
			if (leftFavorite === rightFavorite) {
				return 0;
			}
			return leftFavorite ? -1 : 1;
		});
	}

	function buildStageClassName(attributes) {
		var classes = [
			"fchub-mc-switcher-stage",
			"fchub-mc-switcher-stage--" + attributes.align,
			"fchub-mc-switcher-stage--label-" + attributes.labelPosition,
		];

		if (attributes.dropdownDirection === "up") {
			classes.push("fchub-mc-switcher-stage--preview-up");
		}

		return classes.join(" ");
	}

	function buildWidgetClassName(attributes) {
		var classes = [
			"fchub-mc-switcher",
			"fchub-mc-switcher--preset-" + normalizePreset(attributes.preset),
			"fchub-mc-switcher--size-" + attributes.size,
			"fchub-mc-switcher--width-" + attributes.widthMode,
			"fchub-mc-switcher--dropdown-" + attributes.dropdownPosition,
			"fchub-mc-switcher--direction-" + attributes.dropdownDirection,
			"fchub-mc-switcher--preview-open",
		];

		if (attributes.showName) {
			classes.push("fchub-mc-switcher--show-name");
		}

		if (attributes.showSymbol) {
			classes.push("fchub-mc-switcher--show-symbol");
		}

		if (!attributes.showFlag) {
			classes.push("fchub-mc-switcher--hide-flag");
		}

		return classes.join(" ");
	}

	function renderTrigger(attributes, currentCurrency) {
		return el(
			"button",
			{
				type: "button",
				className: "fchub-mc-switcher__trigger",
				disabled: true,
			},
			attributes.showFlag
				? el("span", { className: "fchub-mc-switcher__flag", "aria-hidden": "true" }, currentCurrency.flag)
				: null,
			attributes.showCode
				? el("span", { className: "fchub-mc-switcher__code" }, currentCurrency.code)
				: null,
			attributes.showSymbol
				? el("span", { className: "fchub-mc-switcher__symbol" }, currentCurrency.symbol)
				: null,
			attributes.showName
				? el("span", { className: "fchub-mc-switcher__name" }, currentCurrency.name)
				: null,
			el("span", { className: "fchub-mc-switcher__caret", "aria-hidden": "true" }, "▼")
		);
	}

	function renderOption(attributes, currency, isActive) {
		var fragments = [];

		if (attributes.showOptionFlags) {
			fragments.push(
				el("span", { key: "flag", className: "fchub-mc-switcher__flag", "aria-hidden": "true" }, currency.flag)
			);
		}

		if (attributes.showOptionCodes) {
			fragments.push(
				el("span", { key: "code", className: "fchub-mc-switcher__option-code" }, currency.code)
			);
		}

		if (attributes.showOptionSymbols) {
			fragments.push(
				el("span", { key: "symbol", className: "fchub-mc-switcher__option-symbol" }, currency.symbol)
			);
		}

		if ((attributes.showOptionCodes || attributes.showOptionSymbols) && attributes.showOptionNames) {
			fragments.push(
				el("span", { key: "sep", className: "fchub-mc-switcher__option-sep", "aria-hidden": "true" }, "—")
			);
		}

		if (attributes.showOptionNames) {
			fragments.push(
				el("span", { key: "name", className: "fchub-mc-switcher__option-name" }, currency.name)
			);
		}

		if (attributes.showActiveIndicator) {
			fragments.push(
				el("span", { key: "check", className: "fchub-mc-switcher__option-check", "aria-hidden": "true" }, isActive ? "✓" : "")
			);
		}

		return el(
			"span",
			{
				key: currency.code,
				className: "fchub-mc-switcher__option" + (isActive ? " fchub-mc-switcher__option--active" : ""),
				role: "option",
				"aria-selected": isActive ? "true" : "false",
			},
			fragments
		);
	}

	function renderOptions(attributes, currencies) {
		return currencies.map(function (currency, index) {
			return renderOption(attributes, currency, index === 0);
		});
	}

	function setAttribute(props, key, value) {
		props.setAttributes((function () {
			var next = {};
			next[key] = value;
			return next;
		})());
	}

	function setFavoriteCurrencies(props, value) {
		setAttribute(props, "favoriteCurrencies", normalizeCurrencyList(value));
	}

	function buildVariation(name, title, description, attributes) {
		return {
			name: name,
			title: title,
			description: description,
			attributes: attributes,
			isActive: function (blockAttributes) {
				return blockAttributes.preset === attributes.preset && blockAttributes.useGlobalDefaults === attributes.useGlobalDefaults;
			},
		};
	}

	function Edit(props) {
		var attributes = normalizeAttributes(props.attributes);
		var blockProps = useBlockProps({
			className: "fchub-mc-switcher-editor-host",
		});
		var renderLabelFirst = ["before", "above"].indexOf(attributes.labelPosition) >= 0;
		var labelNode = attributes.label
			? el("span", { className: "fchub-mc-switcher__label" }, attributes.label)
			: null;
		var defaultDisplayCode = typeof pluginSettings.default_display_currency === "string"
			? pluginSettings.default_display_currency.toUpperCase()
			: null;
		var currentCurrency = sampleCurrencies.find(function (currency) {
			return currency.code === defaultDisplayCode;
		}) || sampleCurrencies[0];
		var previewCurrencies = prioritizeCurrencies(sampleCurrencies, attributes.favoriteCurrencies, attributes.showFavoritesFirst);

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __("Source", "fchub-multi-currency"), initialOpen: true },
					el(ToggleControl, {
						label: __("Use global switcher defaults", "fchub-multi-currency"),
						checked: attributes.useGlobalDefaults,
						onChange: function (value) {
							setAttribute(props, "useGlobalDefaults", value);
						},
					}),
					el(TextControl, {
						label: __("Label", "fchub-multi-currency"),
						value: attributes.label,
						onChange: function (value) {
							setAttribute(props, "label", value);
						},
					}),
					attributes.useGlobalDefaults
						? el(Notice, { status: "info", isDismissible: false },
							__("This block inherits its switcher behavior from the global defaults in Multi-Currency settings. Only the label stays instance-specific.", "fchub-multi-currency")
						)
						: null
				),
				!attributes.useGlobalDefaults
					? el(
						Fragment,
						null,
						el(
							PanelBody,
							{ title: __("Preset", "fchub-multi-currency"), initialOpen: true },
							el(SelectControl, {
								label: __("Preset", "fchub-multi-currency"),
								value: attributes.preset,
								options: [
									{ label: __("Default", "fchub-multi-currency"), value: "default" },
									{ label: __("Pill", "fchub-multi-currency"), value: "pill" },
									{ label: __("Minimal", "fchub-multi-currency"), value: "minimal" },
									{ label: __("Subtle", "fchub-multi-currency"), value: "subtle" },
									{ label: __("Glass", "fchub-multi-currency"), value: "glass" },
									{ label: __("Contrast", "fchub-multi-currency"), value: "contrast" },
								],
								onChange: function (value) {
									setAttribute(props, "preset", normalizePreset(value));
								},
							})
						),
						el(
							PanelBody,
							{ title: __("Trigger", "fchub-multi-currency"), initialOpen: true },
							el(SelectControl, {
								label: __("Label position", "fchub-multi-currency"),
								value: attributes.labelPosition,
								options: [
									{ label: __("Before", "fchub-multi-currency"), value: "before" },
									{ label: __("After", "fchub-multi-currency"), value: "after" },
									{ label: __("Above", "fchub-multi-currency"), value: "above" },
									{ label: __("Below", "fchub-multi-currency"), value: "below" },
								],
								onChange: function (value) {
									setAttribute(props, "labelPosition", normalizeLabelPosition(value));
								},
							}),
							el(ToggleControl, {
								label: __("Show trigger flag", "fchub-multi-currency"),
								checked: attributes.showFlag,
								onChange: function (value) {
									setAttribute(props, "showFlag", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show trigger code", "fchub-multi-currency"),
								checked: attributes.showCode,
								onChange: function (value) {
									setAttribute(props, "showCode", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show trigger symbol", "fchub-multi-currency"),
								checked: attributes.showSymbol,
								onChange: function (value) {
									setAttribute(props, "showSymbol", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show trigger name", "fchub-multi-currency"),
								checked: attributes.showName,
								onChange: function (value) {
									setAttribute(props, "showName", value);
								},
							})
						),
						el(
							PanelBody,
							{ title: __("Dropdown", "fchub-multi-currency"), initialOpen: false },
							el(ToggleControl, {
								label: __("Show option flags", "fchub-multi-currency"),
								checked: attributes.showOptionFlags,
								onChange: function (value) {
									setAttribute(props, "showOptionFlags", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show option codes", "fchub-multi-currency"),
								checked: attributes.showOptionCodes,
								onChange: function (value) {
									setAttribute(props, "showOptionCodes", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show option symbols", "fchub-multi-currency"),
								checked: attributes.showOptionSymbols,
								onChange: function (value) {
									setAttribute(props, "showOptionSymbols", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show option names", "fchub-multi-currency"),
								checked: attributes.showOptionNames,
								onChange: function (value) {
									setAttribute(props, "showOptionNames", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show active indicator", "fchub-multi-currency"),
								checked: attributes.showActiveIndicator,
								onChange: function (value) {
									setAttribute(props, "showActiveIndicator", value);
								},
							}),
							el(SelectControl, {
								label: __("Search", "fchub-multi-currency"),
								value: attributes.searchMode,
								options: [
									{ label: __("Off", "fchub-multi-currency"), value: "off" },
									{ label: __("Inline search", "fchub-multi-currency"), value: "inline" },
								],
								onChange: function (value) {
									setAttribute(props, "searchMode", sanitizeEnum(value, ["off", "inline"], "off"));
								},
							}),
							el(TextControl, {
								label: __("Favorite currencies", "fchub-multi-currency"),
								help: __("Comma-separated ISO codes, for example: EUR, USD, GBP", "fchub-multi-currency"),
								value: attributes.favoriteCurrencies.join(", "),
								onChange: function (value) {
									setFavoriteCurrencies(props, value);
								},
							}),
							el(ToggleControl, {
								label: __("Show favorites first", "fchub-multi-currency"),
								checked: attributes.showFavoritesFirst,
								onChange: function (value) {
									setAttribute(props, "showFavoritesFirst", value);
								},
							}),
							el(SelectControl, {
								label: __("Dropdown position", "fchub-multi-currency"),
								value: attributes.dropdownPosition,
								options: [
									{ label: __("Auto", "fchub-multi-currency"), value: "auto" },
									{ label: __("Start", "fchub-multi-currency"), value: "start" },
									{ label: __("End", "fchub-multi-currency"), value: "end" },
								],
								onChange: function (value) {
									setAttribute(props, "dropdownPosition", sanitizeEnum(value, ["auto", "start", "end"], "auto"));
								},
							}),
							el(SelectControl, {
								label: __("Dropdown direction", "fchub-multi-currency"),
								value: attributes.dropdownDirection,
								options: [
									{ label: __("Auto", "fchub-multi-currency"), value: "auto" },
									{ label: __("Down", "fchub-multi-currency"), value: "down" },
									{ label: __("Up", "fchub-multi-currency"), value: "up" },
								],
								onChange: function (value) {
									setAttribute(props, "dropdownDirection", sanitizeEnum(value, ["auto", "down", "up"], "auto"));
								},
							})
						),
						el(
							PanelBody,
							{ title: __("Footer Context", "fchub-multi-currency"), initialOpen: false },
							el(ToggleControl, {
								label: __("Show freshness badge", "fchub-multi-currency"),
								checked: attributes.showRateBadge,
								onChange: function (value) {
									setAttribute(props, "showRateBadge", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show rate value", "fchub-multi-currency"),
								checked: attributes.showRateValue,
								onChange: function (value) {
									setAttribute(props, "showRateValue", value);
								},
							}),
							el(ToggleControl, {
								label: __("Show checkout context note", "fchub-multi-currency"),
								checked: attributes.showContextNote,
								onChange: function (value) {
									setAttribute(props, "showContextNote", value);
								},
							})
						),
						el(
							PanelBody,
							{ title: __("Layout", "fchub-multi-currency"), initialOpen: true },
							el(SelectControl, {
								label: __("Alignment", "fchub-multi-currency"),
								value: attributes.align,
								options: [
									{ label: __("Left", "fchub-multi-currency"), value: "left" },
									{ label: __("Center", "fchub-multi-currency"), value: "center" },
									{ label: __("Right", "fchub-multi-currency"), value: "right" },
								],
								onChange: function (value) {
									setAttribute(props, "align", sanitizeEnum(value, ["left", "center", "right"], "left"));
								},
							}),
							el(SelectControl, {
								label: __("Size", "fchub-multi-currency"),
								value: attributes.size,
								options: [
									{ label: __("Small", "fchub-multi-currency"), value: "sm" },
									{ label: __("Medium", "fchub-multi-currency"), value: "md" },
									{ label: __("Large", "fchub-multi-currency"), value: "lg" },
								],
								onChange: function (value) {
									setAttribute(props, "size", sanitizeEnum(value, ["sm", "md", "lg"], "md"));
								},
							}),
							el(SelectControl, {
								label: __("Width", "fchub-multi-currency"),
								value: attributes.widthMode,
								options: [
									{ label: __("Auto", "fchub-multi-currency"), value: "auto" },
									{ label: __("Full width", "fchub-multi-currency"), value: "full" },
								],
								onChange: function (value) {
									setAttribute(props, "widthMode", sanitizeEnum(value, ["auto", "full"], "auto"));
								},
							})
						)
					)
					: null
			),
			el(
				"div",
				blockProps,
				el(
					Notice,
					{ status: "info", isDismissible: false },
					__("Editor preview uses your actual configured currencies and current global switcher defaults.", "fchub-multi-currency")
				),
				el(
					"div",
					{ className: "fchub-mc-switcher-editor-preview-shell" },
					el(
						"div",
						{ className: buildStageClassName(attributes) },
						renderLabelFirst ? labelNode : null,
						el(
							"span",
							{
								className: buildWidgetClassName(attributes),
								"data-fchub-mc-switcher": "preview",
							},
							renderTrigger(attributes, currentCurrency),
							el(
								"span",
								{
									className: "fchub-mc-switcher__dropdown",
								},
								attributes.searchMode === "inline"
									? el(
										"span",
										{ className: "fchub-mc-switcher__search-wrap" },
										el("input", {
											type: "search",
											className: "fchub-mc-switcher__search",
											disabled: true,
											value: "",
											placeholder: __("Search currency", "fchub-multi-currency"),
										})
									)
									: null,
								el(
									"span",
									{
										className: "fchub-mc-switcher__list",
										role: "listbox",
									},
									renderOptions(attributes, previewCurrencies)
								),
								(attributes.showRateBadge || attributes.showRateValue || attributes.showContextNote)
									? el(
										"span",
										{ className: "fchub-mc-switcher__footer" },
										attributes.showRateBadge
											? el(
												"span",
												{ className: "fchub-mc-rate-badge" },
												el("span", { className: "fchub-mc-rate-badge__dot", "aria-hidden": "true" }),
												__("Rates updated 2 hours ago", "fchub-multi-currency")
											)
											: null,
										attributes.showRateValue
											? el("span", { className: "fchub-mc-rate-context" }, __("1 EUR = 1.1000 USD", "fchub-multi-currency"))
											: null,
										attributes.showContextNote
											? el("span", { className: "fchub-mc-rate-context" }, __("Display prices only. Checkout is charged in EUR.", "fchub-multi-currency"))
											: null
									)
									: null
							)
						),
						!renderLabelFirst ? labelNode : null
					)
				)
			)
		);
	}

	registerBlockType("fchub-multi-currency/switcher", {
		edit: Edit,
		save: function () {
			return null;
		},
		variations: [
			buildVariation("header-compact", __("Header Compact", "fchub-multi-currency"), __("Flag and code only, tuned for narrow header space.", "fchub-multi-currency"), {
				useGlobalDefaults: false,
				preset: "pill",
				showFlag: true,
				showCode: true,
				showSymbol: false,
				showName: false,
				showRateBadge: false,
				showRateValue: false,
				showContextNote: false,
				size: "sm",
				widthMode: "auto",
				labelPosition: "before",
				dropdownPosition: "auto",
				dropdownDirection: "auto",
			}),
			buildVariation("footer-descriptive", __("Footer Descriptive", "fchub-multi-currency"), __("A more descriptive switcher for footer or utility content.", "fchub-multi-currency"), {
				useGlobalDefaults: false,
				preset: "subtle",
				showFlag: true,
				showCode: true,
				showName: true,
				showRateBadge: true,
				showContextNote: true,
				size: "md",
				labelPosition: "above",
				dropdownPosition: "auto",
				dropdownDirection: "up",
			}),
			buildVariation("mobile-full-width", __("Mobile Full Width", "fchub-multi-currency"), __("Full-width mobile-friendly switcher with room for more context.", "fchub-multi-currency"), {
				useGlobalDefaults: false,
				preset: "default",
				showFlag: true,
				showCode: true,
				showName: true,
				showRateBadge: false,
				showContextNote: false,
				size: "md",
				widthMode: "full",
				labelPosition: "above",
				dropdownPosition: "auto",
				dropdownDirection: "auto",
			}),
		],
		transforms: {
			from: [
				{
					type: "shortcode",
					tag: "fchub_currency_switcher",
					transform: function (atts) {
						var named = atts && atts.named ? atts.named : {};
						return createBlock("fchub-multi-currency/switcher", normalizeAttributes({
							useGlobalDefaults: false,
							preset: named.preset || "default",
							label: named.label || "",
							align: named.align || "left",
							labelPosition: named.label_position || "before",
							showFlag: parseBool(named.show_flag, true),
							showCode: parseBool(named.show_code, true),
							showSymbol: parseBool(named.show_symbol, false),
							showName: parseBool(named.show_name, false),
							showRateBadge: parseBool(named.show_rate_badge, true),
							showOptionFlags: parseBool(named.show_option_flags, true),
							showOptionCodes: parseBool(named.show_option_codes, true),
							showOptionSymbols: parseBool(named.show_option_symbols, false),
							showOptionNames: parseBool(named.show_option_names, true),
							showActiveIndicator: parseBool(named.show_active_indicator, true),
							showContextNote: parseBool(named.show_context_note, false),
							showRateValue: parseBool(named.show_rate_value, false),
							searchMode: named.search_mode || "off",
							favoriteCurrencies: normalizeCurrencyList(named.favorite_currencies || []),
							showFavoritesFirst: parseBool(named.show_favorites_first, true),
							size: named.size || "md",
							widthMode: named.width_mode || "auto",
							dropdownPosition: named.dropdown_position || "auto",
							dropdownDirection: named.dropdown_direction || "auto",
						}));
					},
				},
			],
		},
	});

	registerBlockType("fchub-multi-currency/current-currency", {
		edit: function (props) {
			var attributes = props.attributes || {};
			var displayMode = sanitizeEnum(attributes.displayMode || "flag_code", ["code", "symbol", "name", "flag_code", "flag_name", "symbol_code"], "flag_code");
			var currentCurrency = sampleCurrencies[0];
			var content = displayMode === "code"
				? currentCurrency.code
				: displayMode === "symbol"
					? currentCurrency.symbol
					: displayMode === "name"
						? currentCurrency.name
						: displayMode === "flag_name"
							? currentCurrency.flag + " " + currentCurrency.name
							: displayMode === "symbol_code"
								? currentCurrency.symbol + " " + currentCurrency.code
								: currentCurrency.flag + " " + currentCurrency.code;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __("Display", "fchub-multi-currency"), initialOpen: true },
						el(SelectControl, {
							label: __("Mode", "fchub-multi-currency"),
							value: displayMode,
							options: [
								{ label: __("Flag + Code", "fchub-multi-currency"), value: "flag_code" },
								{ label: __("Flag + Name", "fchub-multi-currency"), value: "flag_name" },
								{ label: __("Code", "fchub-multi-currency"), value: "code" },
								{ label: __("Symbol", "fchub-multi-currency"), value: "symbol" },
								{ label: __("Name", "fchub-multi-currency"), value: "name" },
								{ label: __("Symbol + Code", "fchub-multi-currency"), value: "symbol_code" },
							],
							onChange: function (value) {
								setAttribute(props, "displayMode", value);
							},
						})
					)
				),
				el("div", useBlockProps({ className: "fchub-mc-inline-block fchub-mc-inline-block--current" }), content)
			);
		},
		save: function () {
			return null;
		},
	});

	registerBlockType("fchub-multi-currency/exchange-rate", {
		edit: function (props) {
			var attributes = props.attributes || {};
			var format = sanitizeEnum(attributes.format || "compact", ["compact", "sentence"], "compact");
			var precision = Math.max(0, Math.min(8, Number(attributes.precision || 4)));
			var hideWhenBaseDisplay = parseBool(attributes.hideWhenBaseDisplay, false);
			var text = format === "sentence"
				? "Current rate: 1 EUR = " + Number(1.1).toFixed(precision) + " USD"
				: "1 EUR = " + Number(1.1).toFixed(precision) + " USD";
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __("Display", "fchub-multi-currency"), initialOpen: true },
						el(SelectControl, {
							label: __("Format", "fchub-multi-currency"),
							value: format,
							options: [
								{ label: __("Compact", "fchub-multi-currency"), value: "compact" },
								{ label: __("Sentence", "fchub-multi-currency"), value: "sentence" },
							],
							onChange: function (value) {
								setAttribute(props, "format", value);
							},
						}),
						el(TextControl, {
							label: __("Precision", "fchub-multi-currency"),
							value: String(precision),
							onChange: function (value) {
								setAttribute(props, "precision", Math.max(0, Math.min(8, Number(value || 4))));
							},
						}),
						el(ToggleControl, {
							label: __("Hide when base currency is displayed", "fchub-multi-currency"),
							checked: hideWhenBaseDisplay,
							onChange: function (value) {
								setAttribute(props, "hideWhenBaseDisplay", value);
							},
						})
					)
				),
				el("div", useBlockProps({ className: "fchub-mc-inline-block fchub-mc-inline-block--rate" }), text)
			);
		},
		save: function () {
			return null;
		},
	});

	registerBlockType("fchub-multi-currency/context-notice", {
		edit: function (props) {
			var attributes = props.attributes || {};
			var mode = sanitizeEnum(attributes.mode || "compact", ["compact", "checkout", "full"], "compact");
			var hideWhenBaseDisplay = parseBool(attributes.hideWhenBaseDisplay, true);
			var text = mode === "checkout"
				? "Your payment will be processed in EUR."
				: mode === "full"
					? "Prices shown in USD are approximate. Checkout is charged in EUR."
					: "Viewing prices in USD. Checkout in EUR.";
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __("Display", "fchub-multi-currency"), initialOpen: true },
						el(SelectControl, {
							label: __("Mode", "fchub-multi-currency"),
							value: mode,
							options: [
								{ label: __("Compact", "fchub-multi-currency"), value: "compact" },
								{ label: __("Checkout disclosure", "fchub-multi-currency"), value: "checkout" },
								{ label: __("Full note", "fchub-multi-currency"), value: "full" },
							],
							onChange: function (value) {
								setAttribute(props, "mode", value);
							},
						}),
						el(ToggleControl, {
							label: __("Hide when base currency is displayed", "fchub-multi-currency"),
							checked: hideWhenBaseDisplay,
							onChange: function (value) {
								setAttribute(props, "hideWhenBaseDisplay", value);
							},
						})
					)
				),
				el("div", useBlockProps({ className: "fchub-mc-inline-block fchub-mc-inline-block--notice" }), text)
			);
		},
		save: function () {
			return null;
		},
	});

	registerBlockType("fchub-multi-currency/selector-buttons", {
		edit: function (props) {
			var attributes = props.attributes || {};
			var favoriteCurrencies = normalizeCurrencyList(attributes.favoriteCurrencies || []);
			var showFavoritesFirst = parseBool(attributes.showFavoritesFirst, true);
			var previewCurrencies = prioritizeCurrencies(sampleCurrencies, favoriteCurrencies, showFavoritesFirst);

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __("Layout", "fchub-multi-currency"), initialOpen: true },
						el(TextControl, {
							label: __("Favorite currencies", "fchub-multi-currency"),
							help: __("Comma-separated ISO codes, for example: EUR, USD, GBP", "fchub-multi-currency"),
							value: favoriteCurrencies.join(", "),
							onChange: function (value) {
								setAttribute(props, "favoriteCurrencies", normalizeCurrencyList(value));
							},
						}),
						el(ToggleControl, {
							label: __("Show favorites first", "fchub-multi-currency"),
							checked: showFavoritesFirst,
							onChange: function (value) {
								setAttribute(props, "showFavoritesFirst", value);
							},
						})
					)
				),
				el(
					"div",
					useBlockProps({ className: "fchub-mc-selector-buttons" }),
					previewCurrencies.map(function (currency, index) {
						return el(
							"button",
							{
								key: currency.code,
								type: "button",
								className: "fchub-mc-selector-buttons__button" + (index === 0 ? " is-active" : ""),
								disabled: true,
							},
							el("span", { className: "fchub-mc-selector-buttons__flag" }, currency.flag),
							el("span", { className: "fchub-mc-selector-buttons__label" }, currency.code)
						);
					})
				)
			);
		},
		save: function () {
			return null;
		},
	});
})( 
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n
);
