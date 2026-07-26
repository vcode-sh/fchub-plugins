(function () {
    'use strict';

    var settings = window.FCHubFakturowniaCheckout || {};
    var toggleLabel = settings.toggleLabel || 'I want a company invoice';

    function initNipToggle() {
        var nipField = document.getElementById('billing_nip');
        if (!nipField) {
            return;
        }

        var wrapper = nipField.closest('.fchub-nip-field-wrapper') || nipField.parentElement;
        if (!wrapper || wrapper.dataset.fchubNipInit) {
            return;
        }
        wrapper.dataset.fchubNipInit = '1';
        wrapper.style.display = 'none';

        var toggleWrapper = document.createElement('div');
        toggleWrapper.className = 'fchub-nip-toggle-wrapper';
        toggleWrapper.style.cssText = 'padding: 4px 0;';

        var label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px;';

        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'billing_wants_company_invoice';

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(toggleLabel));
        toggleWrapper.appendChild(label);
        wrapper.parentNode.insertBefore(toggleWrapper, wrapper);

        checkbox.addEventListener('change', function () {
            wrapper.style.display = this.checked ? '' : 'none';
            if (!this.checked) {
                nipField.value = '';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNipToggle);
    } else {
        initNipToggle();
    }

    var observer = new MutationObserver(function (mutations) {
        for (var index = 0; index < mutations.length; index += 1) {
            if (!mutations[index].addedNodes.length) {
                continue;
            }

            if (document.getElementById('billing_nip')) {
                initNipToggle();
                observer.disconnect();
            }
            break;
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
})();
