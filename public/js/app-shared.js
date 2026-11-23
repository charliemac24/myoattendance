document.addEventListener('DOMContentLoaded', function () {
    const moreBtn = document.getElementById('more-tab-btn');
    const overlay = document.getElementById('more-sheet-overlay');
    const logoutBtn = document.getElementById('more-logout-btn');
    if (!moreBtn || !overlay) return;

    function openSheet() {
        overlay.hidden = false;
        moreBtn.setAttribute('aria-expanded', 'true');
    }

    function closeSheet() {
        overlay.hidden = true;
        moreBtn.setAttribute('aria-expanded', 'false');
    }

    moreBtn.addEventListener('click', openSheet);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) closeSheet();
    });

    if (logoutBtn) {
        logoutBtn.addEventListener('click', async function () {
            try {
                await fetch('/api/auth/logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': window.MyoAuth ? window.MyoAuth.csrfToken() : '',
                    },
                });
            } finally {
                window.location.href = '/login';
            }
        });
    }
});
