/* The server also validates confirmation; this only improves the form feedback. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.ed-admin-uninstall form').forEach(function (form) {
            const checkbox = form.querySelector('[name="confirm_uninstall"]');
            const button = form.querySelector('button[type="submit"]');
            if (!checkbox || !button) return;
            function update() { button.disabled = !checkbox.checked; }
            checkbox.addEventListener('change', update);
            update();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
