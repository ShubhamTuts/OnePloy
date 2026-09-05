let razorpayScriptPromise;

function loadRazorpay() {
    if (window.Razorpay) {
        return Promise.resolve(window.Razorpay);
    }

    if (!razorpayScriptPromise) {
        razorpayScriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://checkout.razorpay.com/v1/checkout.js';
            script.async = true;
            script.onload = () => resolve(window.Razorpay);
            script.onerror = () => reject(new Error('Razorpay Checkout could not be loaded.'));
            document.head.appendChild(script);
        });
    }

    return razorpayScriptPromise;
}

export function registerOnePloyMarketingCheckout(Livewire) {
    Livewire.on('oneploy:razorpay-ready', async ({ checkout }) => {
        try {
            const Razorpay = await loadRazorpay();
            if (typeof Razorpay !== 'function') {
                throw new Error('Razorpay Checkout is unavailable.');
            }

            const payment = new Razorpay({
                key: checkout.key_id,
                order_id: checkout.order_id,
                amount: checkout.amount_minor,
                currency: checkout.currency,
                name: 'OnePloy',
                description: checkout.description,
                prefill: {
                    name: checkout.name,
                    email: checkout.email,
                },
                handler: () => window.location.assign(checkout.return_url),
            });
            payment.open();
        } catch (error) {
            window.toast?.('Payment unavailable', {
                type: 'danger',
                description: error instanceof Error ? error.message : 'Razorpay Checkout could not be opened.',
            });
        }
    });
}
