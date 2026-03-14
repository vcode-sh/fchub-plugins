(function () {
    'use strict';

    var root = document.getElementById('cartshift-app');
    if (!root) return;

    var restUrl = cartshift.restUrl;
    var nonce = cartshift.nonce;
    var pollingInterval = null;

    // ── Theme switcher (syncs with FluentCart fcart_admin_theme) ──
    var STORAGE_KEY = 'fcart_admin_theme';
    var DARK_TARGETS = ['body', '#wpbody-content', '.wp-toolbar', '#wpfooter'];
    var themeMode = 'system';

    var THEME_ICONS = {
        light: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
        dark: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
        system: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>'
    };

    function getSystemTheme() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function readSavedMode() {
        var raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return 'system';
        if (raw === 'light' || raw === 'dark') return raw;
        if (raw.indexOf('system') === 0) return 'system';
        return 'system';
    }

    function applyDark(isDark) {
        DARK_TARGETS.forEach(function (sel) {
            var el = sel === 'body' ? document.body : document.querySelector(sel);
            if (el) el.classList.toggle('dark', isDark);
        });
    }

    function applyTheme(mode) {
        themeMode = mode;
        var resolved = mode === 'system' ? getSystemTheme() : mode;
        applyDark(resolved === 'dark');
        if (mode === 'system') {
            localStorage.setItem(STORAGE_KEY, 'system:' + resolved);
        } else {
            localStorage.setItem(STORAGE_KEY, mode);
        }
    }

    function changeTheme(mode) {
        applyTheme(mode);
        render();
        window.dispatchEvent(new CustomEvent('onFluentCartThemeChange', {
            detail: { theme: mode === 'system' ? getSystemTheme() : mode }
        }));
    }

    // Page header helper — title on left, theme switcher on right
    function pageHeader(title) {
        var html = '<div class="cartshift-page-header">';
        html += '<h1>' + title + '</h1>';
        html += '<div class="cartshift-theme-switcher">';
        html += '<button class="cartshift-theme-btn" id="cartshift-theme-btn" title="Theme: ' + themeMode + '">' + THEME_ICONS[themeMode] + '</button>';
        html += '<div class="cartshift-theme-dropdown" id="cartshift-theme-dropdown" style="display:none;">';
        html += '<button class="cartshift-theme-option' + (themeMode === 'light' ? ' active' : '') + '" data-theme="light">' + THEME_ICONS.light + ' Light</button>';
        html += '<button class="cartshift-theme-option' + (themeMode === 'dark' ? ' active' : '') + '" data-theme="dark">' + THEME_ICONS.dark + ' Dark</button>';
        html += '<button class="cartshift-theme-option' + (themeMode === 'system' ? ' active' : '') + '" data-theme="system">' + THEME_ICONS.system + ' System</button>';
        html += '</div></div></div>';
        return html;
    }

    // Event delegation for theme switcher (survives re-renders)
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('#cartshift-theme-btn');
        if (btn) {
            e.stopPropagation();
            var dd = document.getElementById('cartshift-theme-dropdown');
            if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
            return;
        }
        var opt = e.target.closest('.cartshift-theme-option');
        if (opt) {
            changeTheme(opt.getAttribute('data-theme'));
            return;
        }
    });

    // Close dropdown on outside click
    document.addEventListener('click', function () {
        var dd = document.getElementById('cartshift-theme-dropdown');
        if (dd) dd.style.display = 'none';
    });

    // Sync with FluentCart theme changes
    window.addEventListener('onFluentCartThemeChange', function () {
        themeMode = readSavedMode();
        applyTheme(themeMode);
        render();
    });

    // React to OS preference changes
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    mq.addEventListener('change', function () {
        if (themeMode === 'system') applyTheme('system');
    });

    // Initialize theme
    themeMode = readSavedMode();
    applyTheme(themeMode);

    // FIX F1: clear polling interval on page unload.
    window.addEventListener('beforeunload', function () {
        stopPolling();
    });

    // State
    var state = {
        screen: 'preflight', // preflight | select | progress | results
        preflight: null,
        counts: null,
        selectedEntities: [],
        progress: null,
        log: [],
        logPage: 1,
        logPages: 1,
        loading: false,
        migrating: false, // FIX F2: separate flag for migrate button
        batchError: null, // Track last batch error for retry
        error: null,
        finalized: false,
        finalizing: false,
        finalizeStats: null
    };

    // API helper
    // FIX F3: check Content-Type before calling res.json(), handle non-JSON gracefully.
    function api(method, endpoint, body) {
        var opts = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            }
        };
        if (body) {
            opts.body = JSON.stringify(body);
        }
        return fetch(restUrl + endpoint, opts).then(function (res) {
            var contentType = res.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') === -1) {
                if (!res.ok) {
                    throw new Error('Server returned non-JSON response (HTTP ' + res.status + '). The server may have timed out or encountered a fatal error.');
                }
                // Try parsing anyway — some servers omit Content-Type.
                return res.text().then(function (text) {
                    try {
                        var data = JSON.parse(text);
                        return data;
                    } catch (e) {
                        throw new Error('Server returned non-JSON response. Check PHP error logs for details.');
                    }
                });
            }
            return res.json().then(function (data) {
                if (!res.ok) throw new Error(data.message || data.data && data.data.message || 'Request failed');
                return data;
            });
        });
    }

    // Render
    function render() {
        switch (state.screen) {
            case 'preflight':
                renderPreflight();
                break;
            case 'select':
                renderSelect();
                break;
            case 'progress':
                renderProgress();
                break;
            case 'results':
                renderResults();
                break;
        }
    }

    function renderPreflight() {
        var html = pageHeader('CartShift');
        html += '<p>Migrate your WooCommerce data to FluentCart.</p>';

        if (state.loading) {
            html += '<p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span> Running preflight checks...</p>';
            root.innerHTML = html;
            return;
        }

        if (state.error) {
            html += '<div class="notice notice-error"><p>' + escHtml(state.error) + '</p></div>';
        }

        if (!state.preflight) {
            html += '<button class="button button-primary" id="cartshift-run-preflight">Run Preflight Checks</button>';
            root.innerHTML = html;
            document.getElementById('cartshift-run-preflight').addEventListener('click', runPreflight);
            return;
        }

        var checks = state.preflight.checks;

        html += '<h2>Preflight Checks</h2>';
        html += '<table class="widefat striped cartshift-checks">';
        html += '<thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';

        for (var key in checks) {
            var c = checks[key];
            var icon = c.pass ? '<span class="cartshift-pass">&#10003;</span>' : '<span class="cartshift-fail">&#10007;</span>';
            if (c.warning) icon = '<span class="cartshift-warn">&#9888;</span>';
            html += '<tr>';
            html += '<td><strong>' + escHtml(c.label) + '</strong></td>';
            html += '<td>' + icon + '</td>';
            html += '<td>' + escHtml(c.message) + '</td>';
            html += '</tr>';
        }

        html += '</tbody></table>';

        if (state.counts) {
            html += '<h2>WooCommerce Data Counts</h2>';
            html += '<table class="widefat striped"><thead><tr><th>Entity</th><th>Count</th></tr></thead><tbody>';
            for (var entity in state.counts) {
                html += '<tr><td>' + capitalize(entity) + '</td><td>' + state.counts[entity] + '</td></tr>';
            }
            html += '</tbody></table>';
        }

        if (checks.fc_data && checks.fc_data.warning) {
            html += '<h2>Existing FluentCart Data</h2>';
            html += '<div class="notice notice-warning inline"><p>FluentCart already contains data. Review counts below before proceeding.</p></div>';
            html += '<table class="widefat striped"><thead><tr><th>Entity</th><th>Count</th></tr></thead><tbody>';
            for (var fce in checks.fc_data.counts) {
                html += '<tr><td>' + capitalize(fce) + '</td><td>' + checks.fc_data.counts[fce] + '</td></tr>';
            }
            html += '</tbody></table>';
        }

        if (state.preflight.ready) {
            html += '<p style="margin-top:20px;">';
            html += '<button class="button button-primary button-hero" id="cartshift-proceed">Proceed to Migration</button> ';
            html += '<button class="button" id="cartshift-recheck">Re-run Checks</button>';
            html += '</p>';
        } else {
            html += '<div class="notice notice-error inline"><p>Please resolve the failing checks above before migrating.</p></div>';
            html += '<p><button class="button" id="cartshift-recheck">Re-run Checks</button></p>';
        }

        root.innerHTML = html;

        var proceedBtn = document.getElementById('cartshift-proceed');
        if (proceedBtn) proceedBtn.addEventListener('click', function () { state.screen = 'select'; render(); });
        var recheckBtn = document.getElementById('cartshift-recheck');
        if (recheckBtn) recheckBtn.addEventListener('click', runPreflight);
    }

    function renderSelect() {
        var html = pageHeader('Select Entities to Migrate');
        html += '<p>Choose which WooCommerce data to migrate to FluentCart. Dependencies are respected automatically.</p>';

        var entities = [
            { key: 'products', label: 'Products', count: state.counts ? state.counts.products : '?', dep: '' },
            { key: 'customers', label: 'Customers', count: state.counts ? state.counts.customers : '?', dep: '' },
            { key: 'coupons', label: 'Coupons', count: state.counts ? state.counts.coupons : '?', dep: '' },
            { key: 'orders', label: 'Orders', count: state.counts ? state.counts.orders : '?', dep: 'Requires: Products, Customers' },
            { key: 'subscriptions', label: 'Subscriptions', count: state.counts ? state.counts.subscriptions : '?', dep: 'Requires: Products, Customers, Orders' }
        ];

        var wcsActive = state.preflight && state.preflight.checks.wc_subscriptions && state.preflight.checks.wc_subscriptions.active;

        html += '<table class="widefat striped"><thead><tr><th></th><th>Entity</th><th>Count</th><th>Dependencies</th></tr></thead><tbody>';

        for (var i = 0; i < entities.length; i++) {
            var e = entities[i];
            var disabled = (e.key === 'subscriptions' && !wcsActive) ? ' disabled' : '';
            var checked = state.selectedEntities.indexOf(e.key) !== -1 ? ' checked' : '';
            html += '<tr>';
            html += '<td><input type="checkbox" class="cartshift-entity-cb" data-entity="' + e.key + '"' + checked + disabled + '></td>';
            html += '<td><strong>' + e.label + '</strong></td>';
            html += '<td>' + e.count + '</td>';
            html += '<td>' + (e.dep ? '<em>' + e.dep + '</em>' : '-') + '</td>';
            html += '</tr>';
        }

        html += '</tbody></table>';

        if (!wcsActive) {
            html += '<p class="description">Subscription migration is disabled because WooCommerce Subscriptions is not active.</p>';
        }

        html += '<div class="cartshift-option-box">';
        html += '<label><input type="checkbox" id="cartshift-dry-run"> <strong>Dry run</strong> &mdash; validate data mapping without writing to FluentCart. Shows what would be migrated.</label>';
        html += '</div>';

        html += '<p style="margin-top:15px;">';
        // FIX F2: disable button when migrating.
        var btnDisabled = state.migrating ? ' disabled' : '';
        html += '<button class="button button-primary button-hero" id="cartshift-start"' + btnDisabled + '>Start Migration</button> ';
        html += '<button class="button" id="cartshift-back-preflight">Back</button>';
        html += '</p>';

        root.innerHTML = html;

        // Bind checkboxes
        var cbs = document.querySelectorAll('.cartshift-entity-cb');
        for (var j = 0; j < cbs.length; j++) {
            cbs[j].addEventListener('change', function () {
                state.selectedEntities = [];
                var all = document.querySelectorAll('.cartshift-entity-cb:checked');
                for (var k = 0; k < all.length; k++) {
                    state.selectedEntities.push(all[k].getAttribute('data-entity'));
                }
            });
        }

        document.getElementById('cartshift-start').addEventListener('click', startMigration);
        document.getElementById('cartshift-back-preflight').addEventListener('click', function () { state.screen = 'preflight'; render(); });
    }

    function renderProgress() {
        var html = pageHeader('Migration in Progress');

        if (state.error) {
            html += '<div class="notice notice-error"><p>' + escHtml(state.error) + '</p></div>';

            // Show retry button if the error was from a batch.
            if (state.batchError) {
                html += '<p>';
                html += '<button class="button button-primary" id="cartshift-retry-batch">Retry Batch</button> ';
                html += '<button class="button" id="cartshift-back-from-error">Back to Start</button>';
                html += '</p>';
            } else {
                html += '<p><button class="button" id="cartshift-back-from-error">Back to Start</button></p>';
            }

            root.innerHTML = html;

            var retryBtn = document.getElementById('cartshift-retry-batch');
            if (retryBtn) retryBtn.addEventListener('click', function () {
                state.error = null;
                state.batchError = null;
                render();
                runNextBatch();
            });

            var backBtn = document.getElementById('cartshift-back-from-error');
            if (backBtn) backBtn.addEventListener('click', function () {
                state.screen = 'preflight';
                state.error = null;
                state.batchError = null;
                state.progress = null;
                state.preflight = null;
                state.migrating = false;
                state.finalized = false;
                state.finalizing = false;
                state.finalizeStats = null;
                render();
            });
            return;
        }

        if (!state.progress) {
            html += '<p><span class="spinner is-active" style="float:none;"></span> Starting...</p>';
            root.innerHTML = html;
            return;
        }

        var p = state.progress;

        html += '<div class="cartshift-status-bar">';
        html += '<strong>Status:</strong> <span class="cartshift-badge cartshift-badge-' + p.status + '">' + capitalize(p.status) + '</span>';
        if (p.dry_run) html += ' <span class="cartshift-badge cartshift-badge-dryrun">DRY RUN</span>';
        if (p.started_at) html += ' | Started: ' + p.started_at;
        html += '</div>';

        if (p.entities) {
            html += '<table class="widefat striped cartshift-progress-table"><thead><tr><th>Entity</th><th>Progress</th><th>Processed</th><th>Skipped</th><th>Errors</th></tr></thead><tbody>';

            for (var entity in p.entities) {
                var e = p.entities[entity];
                var pct = e.total > 0 ? Math.round(((e.processed + e.skipped + e.errors) / e.total) * 100) : 0;
                if (e.status === 'completed') pct = 100;

                html += '<tr>';
                html += '<td><strong>' + capitalize(entity) + '</strong></td>';
                html += '<td><div class="cartshift-progress-bar"><div class="cartshift-progress-fill cartshift-progress-' + e.status + '" style="width:' + pct + '%;"></div><span class="cartshift-progress-text">' + pct + '%</span></div></td>';
                html += '<td>' + e.processed + ' / ' + e.total + '</td>';
                html += '<td>' + e.skipped + '</td>';
                html += '<td>' + (e.errors > 0 ? '<span class="cartshift-fail">' + e.errors + '</span>' : '0') + '</td>';
                html += '</tr>';
            }

            html += '</tbody></table>';
        }

        if (p.status === 'running') {
            html += '<p style="margin-top:15px;"><button class="button button-secondary" id="cartshift-cancel">Cancel Migration</button></p>';
        } else {
            html += '<p style="margin-top:15px;">';

            if (!state.finalized && !p.dry_run) {
                var finBtnDisabled = state.finalizing ? ' disabled' : '';
                html += '<button class="button button-primary" id="cartshift-finalize"' + finBtnDisabled + '>';
                html += state.finalizing ? '<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span> Finalizing...' : 'Finalize Migration';
                html += '</button> ';
            }

            if (state.finalized && state.finalizeStats) {
                html += '<div class="notice notice-success inline" style="margin:10px 0;"><p>';
                html += 'Finalization complete — ' + state.finalizeStats.customers_updated + ' customer stats recalculated, caches cleared.';
                html += '</p></div>';
            }

            html += '<button class="button' + (state.finalized ? ' button-primary' : '') + '" id="cartshift-view-results">View Results & Log</button>';
            html += '</p>';
        }

        root.innerHTML = html;

        var cancelBtn = document.getElementById('cartshift-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelMigration);

        var finalizeBtn = document.getElementById('cartshift-finalize');
        if (finalizeBtn) finalizeBtn.addEventListener('click', runFinalize);

        var resultsBtn = document.getElementById('cartshift-view-results');
        if (resultsBtn) resultsBtn.addEventListener('click', function () { state.screen = 'results'; loadLog(); render(); });
    }

    function renderResults() {
        var html = pageHeader('Migration Results');

        if (state.progress && state.progress.entities) {
            html += '<h2>Summary</h2>';
            html += '<table class="widefat striped"><thead><tr><th>Entity</th><th>Processed</th><th>Skipped</th><th>Errors</th><th>Status</th></tr></thead><tbody>';

            var totalProcessed = 0, totalSkipped = 0, totalErrors = 0;
            for (var entity in state.progress.entities) {
                var e = state.progress.entities[entity];
                totalProcessed += e.processed;
                totalSkipped += e.skipped;
                totalErrors += e.errors;
                html += '<tr>';
                html += '<td><strong>' + capitalize(entity) + '</strong></td>';
                html += '<td>' + e.processed + '</td>';
                html += '<td>' + e.skipped + '</td>';
                html += '<td>' + (e.errors > 0 ? '<span class="cartshift-fail">' + e.errors + '</span>' : '0') + '</td>';
                html += '<td><span class="cartshift-badge cartshift-badge-' + e.status + '">' + capitalize(e.status) + '</span></td>';
                html += '</tr>';
            }

            html += '<tr class="cartshift-total-row"><td><strong>Total</strong></td><td><strong>' + totalProcessed + '</strong></td><td><strong>' + totalSkipped + '</strong></td><td><strong>' + totalErrors + '</strong></td><td></td></tr>';
            html += '</tbody></table>';
        }

        html += '<h2>Migration Log</h2>';

        if (state.log && state.log.length > 0) {
            html += '<div class="cartshift-log-container">';
            html += '<table class="widefat striped"><thead><tr><th>Time</th><th>Entity</th><th>WC ID</th><th>Status</th><th>Message</th></tr></thead><tbody>';

            for (var i = 0; i < state.log.length; i++) {
                var l = state.log[i];
                html += '<tr class="cartshift-log-' + l.status + '">';
                html += '<td>' + escHtml(l.created_at) + '</td>';
                html += '<td>' + escHtml(l.entity_type) + '</td>';
                html += '<td>' + (l.wc_id || '-') + '</td>';
                html += '<td><span class="cartshift-badge cartshift-badge-' + l.status + '">' + capitalize(l.status) + '</span></td>';
                html += '<td>' + escHtml(l.message || '') + '</td>';
                html += '</tr>';
            }

            html += '</tbody></table>';
            html += '</div>';

            // Pagination
            if (state.logPages > 1) {
                html += '<p class="cartshift-log-pagination">';
                if (state.logPage > 1) {
                    html += '<button class="button" id="cartshift-log-prev">Previous</button> ';
                }
                html += 'Page ' + state.logPage + ' of ' + state.logPages;
                if (state.logPage < state.logPages) {
                    html += ' <button class="button" id="cartshift-log-next">Next</button>';
                }
                html += '</p>';
            }
        } else {
            html += '<p>No log entries found.</p>';
        }

        html += '<h2>Actions</h2>';
        html += '<p>';
        html += '<button class="button" id="cartshift-new-migration">New Migration</button> ';
        html += '<button class="button button-link-delete" id="cartshift-rollback">Rollback Migration</button>';
        html += '</p>';

        root.innerHTML = html;

        var prevBtn = document.getElementById('cartshift-log-prev');
        if (prevBtn) prevBtn.addEventListener('click', function () { state.logPage--; loadLog(); });
        var nextBtn = document.getElementById('cartshift-log-next');
        if (nextBtn) nextBtn.addEventListener('click', function () { state.logPage++; loadLog(); });
        var newBtn = document.getElementById('cartshift-new-migration');
        if (newBtn) newBtn.addEventListener('click', function () {
            state.screen = 'preflight';
            state.preflight = null;
            state.counts = null;
            state.progress = null;
            state.log = [];
            state.selectedEntities = [];
            state.migrating = false;
            state.finalized = false;
            state.finalizing = false;
            state.finalizeStats = null;
            render();
        });
        var rollbackBtn = document.getElementById('cartshift-rollback');
        if (rollbackBtn) rollbackBtn.addEventListener('click', rollback);
    }

    // Actions
    function runPreflight() {
        state.loading = true;
        state.error = null;
        render();

        Promise.all([api('GET', 'preflight'), api('GET', 'counts')])
            .then(function (results) {
                state.preflight = results[0];
                state.counts = results[1];
                state.loading = false;
                render();
            })
            .catch(function (err) {
                state.error = err.message;
                state.loading = false;
                render();
            });
    }

    function startMigration() {
        if (state.selectedEntities.length === 0) {
            alert('Please select at least one entity type to migrate.');
            return;
        }

        // FIX F2: disable migrate button immediately.
        state.migrating = true;

        // Capture dry run BEFORE re-rendering (checkbox will be destroyed).
        var dryRunEl = document.getElementById('cartshift-dry-run');
        var dryRun = dryRunEl ? dryRunEl.checked : false;

        // Auto-include dependencies.
        var selected = state.selectedEntities.slice();
        if (selected.indexOf('orders') !== -1) {
            if (selected.indexOf('products') === -1) selected.unshift('products');
            if (selected.indexOf('customers') === -1) selected.splice(1, 0, 'customers');
        }
        if (selected.indexOf('subscriptions') !== -1) {
            if (selected.indexOf('products') === -1) selected.unshift('products');
            if (selected.indexOf('customers') === -1) selected.splice(1, 0, 'customers');
            if (selected.indexOf('orders') === -1) selected.splice(selected.indexOf('subscriptions'), 0, 'orders');
        }
        state.selectedEntities = selected;

        state.screen = 'progress';
        state.progress = null;
        state.error = null;
        state.batchError = null;
        render();

        api('POST', 'migrate', { entity_types: selected, dry_run: dryRun })
            .then(function (result) {
                var data = result.data || result;
                updateProgressFromBatch(data);

                if (data.continue) {
                    runNextBatch();
                } else {
                    migrationFinished();
                }
            })
            .catch(function (err) {
                state.error = err.message;
                state.batchError = true;
                state.migrating = false;
                render();
            });
    }

    /**
     * Self-calling batch loop: POST /migrate/batch until continue === false.
     */
    function runNextBatch() {
        api('POST', 'migrate/batch')
            .then(function (result) {
                var data = result.data || result;
                updateProgressFromBatch(data);

                if (data.continue) {
                    // Use setTimeout to avoid deep call stacks and let the browser breathe.
                    setTimeout(runNextBatch, 50);
                } else {
                    migrationFinished();
                }
            })
            .catch(function (err) {
                state.error = err.message;
                state.batchError = true;
                // Fetch latest progress to display current state.
                api('GET', 'progress').then(function (result) {
                    var data = result.data || result;
                    state.progress = data;
                    render();
                }).catch(function () {
                    render();
                });
            });
    }

    /**
     * Update local progress state from a batch response.
     */
    function updateProgressFromBatch(data) {
        // Fetch full progress from the server to get the complete picture.
        api('GET', 'progress').then(function (result) {
            var progressData = result.data || result;
            state.progress = progressData;
            render();
        }).catch(function () {
            // Fallback: use the batch entities data if progress endpoint fails.
            if (data.entities && state.progress) {
                state.progress.entities = data.entities;
                state.progress.status = data.status || state.progress.status;
            }
            render();
        });
    }

    function migrationFinished() {
        state.migrating = false;
        // Final progress fetch.
        api('GET', 'progress').then(function (result) {
            var data = result.data || result;
            state.progress = data;
            render();
        }).catch(function () {
            render();
        });
    }

    function runFinalize() {
        if (!state.progress || !state.progress.migration_id) {
            alert('No migration ID found. Cannot finalize.');
            return;
        }

        state.finalizing = true;
        render();

        api('POST', 'finalize', { migration_id: state.progress.migration_id })
            .then(function (result) {
                var data = result.data || result;
                state.finalizing = false;
                state.finalized = true;
                state.finalizeStats = data.stats || data;
                render();
            })
            .catch(function (err) {
                state.finalizing = false;
                alert('Finalization failed: ' + err.message);
                render();
            });
    }

    function startPolling() {
        if (pollingInterval) return;
        pollingInterval = setInterval(function () {
            api('GET', 'progress').then(function (result) {
                var data = result.data || result;
                state.progress = data;
                render();
                if (data.status !== 'running') {
                    stopPolling();
                }
            });
        }, 2000);
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
    }

    function cancelMigration() {
        if (!confirm('Are you sure you want to cancel the migration?')) return;
        api('POST', 'cancel').then(function () {
            stopPolling();
            state.migrating = false;
            api('GET', 'progress').then(function (result) {
                var data = result.data || result;
                state.progress = data;
                render();
            });
        });
    }

    function loadLog() {
        api('GET', 'log?page=' + state.logPage).then(function (result) {
            var data = result.data || result;
            state.log = data.entries;
            state.logPages = data.pages;
            render();
        });
    }

    function rollback() {
        if (!confirm('This will DELETE all FluentCart records created by the migration. This cannot be undone. Continue?')) return;

        state.loading = true;
        render();

        api('POST', 'rollback').then(function (result) {
            var data = result.data || result;
            state.loading = false;
            alert('Rollback complete. Deleted records: ' + JSON.stringify(data.deleted));
            state.screen = 'preflight';
            state.preflight = null;
            state.counts = null;
            state.progress = null;
            state.log = [];
            state.migrating = false;
            render();
        }).catch(function (err) {
            state.loading = false;
            alert('Rollback failed: ' + err.message);
            render();
        });
    }

    // Helpers
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
    }

    // Init
    render();

    // Auto-run preflight on load.
    runPreflight();

})();
