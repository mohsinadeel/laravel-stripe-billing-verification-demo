(() => {
    const pending = document.getElementById('checkout-pending');
    if (!pending) return;

    const deadline = Date.now() + 90000;
    async function check() {
        if (Date.now() >= deadline) {
            pending.textContent = 'Payment confirmation is taking longer than expected. Check webhook delivery, then refresh this page.';
            return;
        }
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        try {
            const response = await fetch(pending.dataset.statusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (response.status === 401 || response.status === 403) {
                pending.textContent = 'Your session has expired. Sign in again to check payment confirmation.';
                return;
            }
            if (response.ok && (await response.json()).confirmed === true) {
                window.location.reload();
                return;
            }
        } catch {
            // Keep the pending state during transient network failures.
        } finally {
            clearTimeout(timeout);
        }
        setTimeout(check, 2000);
    }
    setTimeout(check, 2000);
})();
