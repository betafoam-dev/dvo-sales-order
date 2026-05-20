(function () {
    // Inject the loader HTML
    const el = document.createElement('div');
    el.id = 'page-loader';
    el.innerHTML = `
        <div class="loader-ring no-print">
            <img class="loader-logo" src="images/logo.png" alt="">
        </div>
    `;
    document.body.appendChild(el);

    const Loader = {
        show() { el.classList.add('active'); },
        hide() { el.classList.remove('active'); }
    };

    window.Loader = Loader;

    // Auto-show on any link click that causes navigation
    document.addEventListener('click', e => {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        const onclick = link.getAttribute('onclick');

        // Skip anchors, javascript:, target="_blank"
        if (!href || href.startsWith('#') || href.startsWith('javascript') || link.target === '_blank') return;

        // Check if this link has a confirm dialog in onclick
        // If so, don't show loader yet - let onclick handle it
        if (onclick && onclick.includes('confirm')) {
            // The onclick will handle showing/hiding loader
            // Don't show it here, let the onclick decide
            return;
        }

        Loader.show();
    }, true); // Use capture phase to run before inline onclick

    // Auto-show on any form submit
    document.addEventListener('submit', () => Loader.show());

    // Hide on browser back/forward (bfcache restore)
    window.addEventListener('pageshow', e => {
        if (e.persisted) Loader.hide();
    });
})();