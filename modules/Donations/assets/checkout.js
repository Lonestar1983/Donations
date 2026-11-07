/* Donation checkout: server-created and verified payments inside a native dialog. */
(function () {
    'use strict';
    let sdkPromise;
    function loadSDK(client, currency) {
        if (!sdkPromise) {
            sdkPromise = new Promise(function (resolve, reject) {
                const script = document.createElement('script');
                const query = new URLSearchParams({'client-id': client, currency: currency, intent: 'capture', components: 'buttons'});
                script.src = 'https://www.paypal.com/sdk/js?' + query.toString();
                script.dataset.namespace = 'evoDonationPayPal';
                script.onload = function () { window.evoDonationPayPal ? resolve(window.evoDonationPayPal) : reject(new Error('SDK unavailable')); };
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        return sdkPromise;
    }
    function init() {
        const form = document.querySelector('[data-ed-checkout]');
        const modal = document.querySelector('.ed-payment-modal');
        if (!form || !modal || typeof modal.showModal !== 'function') return;
        const status = modal.querySelector('.ed-payment-status');
        const container = modal.querySelector('.ed-paypal-buttons');
        const close = modal.querySelector('.ed-payment-close');
        const refresh = modal.querySelector('.ed-payment-refresh');
        let busy = false, rendered = false, snapshot, donation, orderID, finished = false;
        function lock(value) { busy = value; close.disabled = value; }
        function failure() {
            lock(false); finished = true; container.hidden = true;
            status.textContent = modal.dataset.error; refresh.hidden = false;
        }
        async function request(action, fields) {
            const body = new URLSearchParams(snapshot);
            body.set('action', action); body.set('ed_modal', '1');
            Object.entries(fields || {}).forEach(function (entry) { body.set(entry[0], entry[1]); });
            const response = await fetch(form.getAttribute('action'), {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: body});
            const result = await response.json();
            if (!response.ok || result.error) throw new Error('Checkout request failed');
            return result;
        }
        close.addEventListener('click', function () { if (!busy) modal.close(); });
        modal.addEventListener('cancel', function (event) { if (busy) event.preventDefault(); });
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || modal.open) return;
            if (finished) { window.location.reload(); return; }
            if (!form.reportValidity()) return;
            snapshot = new URLSearchParams(new FormData(form));
            modal.querySelector('.ed-payment-amount').textContent = snapshot.get('amount') + ' ' + form.dataset.currency;
            modal.showModal(); status.textContent = modal.dataset.loading; lock(true);
            try {
                const paypal = await loadSDK(form.dataset.client, form.dataset.currency);
                if (!rendered) {
                    const buttons = paypal.Buttons({
                        fundingSource: paypal.FUNDING.PAYPAL,
                        createOrder: async function () {
                            lock(true); status.textContent = modal.dataset.loading;
                            try {
                                const data = await request('create');
                                donation = data.donation; orderID = data.orderID;
                                if (!donation || !orderID) throw new Error('Order unavailable');
                                return orderID;
                            } catch (error) { failure(); throw error; }
                        },
                        onApprove: async function (data) {
                            lock(true); status.textContent = modal.dataset.loading;
                            try {
                                if (data.orderID !== orderID) throw new Error('Order mismatch');
                                const result = await request('capture', {donation: donation, orderID: orderID});
                                status.textContent = result.message; container.hidden = true; refresh.hidden = false;
                                finished = true; lock(false);
                            } catch (error) { failure(); }
                        },
                        onCancel: function () { lock(false); status.textContent = modal.dataset.cancel; },
                        onError: failure
                    });
                    if (!buttons.isEligible()) throw new Error('PayPal unavailable');
                    await buttons.render(container); rendered = true;
                }
                status.textContent = ''; lock(false);
            } catch (error) { failure(); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
