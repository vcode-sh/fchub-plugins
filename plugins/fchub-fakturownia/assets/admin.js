(function () {
    'use strict';

    var settings = window.FCHubFakturowniaAdmin || {};
    var settingsLabel = settings.settingsLabel || 'Settings';
    var observer = new MutationObserver(function () {
        var cards = document.querySelectorAll('.fct-integration-card');

        cards.forEach(function (card) {
            if (card.dataset.fchubLinked) {
                return;
            }

            var title = card.querySelector('.title');
            if (!title || title.textContent.indexOf('Fakturownia') === -1) {
                return;
            }

            card.dataset.fchubLinked = '1';
            card.style.cursor = 'pointer';

            var description = card.querySelector('.desc');
            if (description) {
                var buttonWrapper = document.createElement('div');
                buttonWrapper.className = 'addon-setting-btn';
                buttonWrapper.style.cssText = 'margin-top: 8px;';

                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = settingsLabel;
                button.style.cssText = [
                    'display: inline-flex; align-items: center; gap: 4px;',
                    'padding: 5px 12px; font-size: 13px; font-weight: 500;',
                    'border-radius: 4px; border: 1px solid #d0d5dd;',
                    'background: #fff; color: #344054; cursor: pointer; line-height: 1.5;'
                ].join('');
                button.addEventListener('mouseenter', function () {
                    button.style.background = '#f9fafb';
                });
                button.addEventListener('mouseleave', function () {
                    button.style.background = '#fff';
                });
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    window.location.hash = '#/integrations/fakturownia';
                });

                buttonWrapper.appendChild(button);
                description.parentNode.insertBefore(buttonWrapper, description.nextSibling);
            }

            card.addEventListener('click', function (event) {
                if (event.target.tagName === 'A' || event.target.tagName === 'BUTTON') {
                    return;
                }
                window.location.hash = '#/integrations/fakturownia';
            });

            observer.disconnect();
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });
})();
