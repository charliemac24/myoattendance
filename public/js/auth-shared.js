window.MyoAuth = (function () {
    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function wirePasswordToggles(root) {
        (root || document).querySelectorAll('[data-toggle-password]').forEach((button) => {
            const input = document.getElementById(button.getAttribute('data-toggle-password'));
            if (!input || button.dataset.toggleWired) return;
            button.dataset.toggleWired = 'true';
            button.addEventListener('click', () => {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
    }

    async function submitJson(url, data, method) {
        const response = await fetch(url, {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(data),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(payload.message || 'Unable to complete the request.');
            error.status = response.status;
            error.errors = payload.errors || {};
            throw error;
        }

        return payload;
    }

    function firstErrorMessage(error) {
        const fieldMessages = Object.values(error.errors || {}).flat();
        return fieldMessages[0] || error.message || 'Unable to complete the request.';
    }

    return { csrfToken, wirePasswordToggles, submitJson, firstErrorMessage };
})();

document.addEventListener('DOMContentLoaded', () => window.MyoAuth.wirePasswordToggles());
