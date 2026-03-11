(function () {
    // Inject the loader HTML
    const el = document.createElement('div');
    el.id = 'page-loader';
    el.innerHTML = `
        <div class="loader-ring">
            <img class="loader-logo" src="images/logo.png" alt="Loading...">
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
        // Skip anchors, javascript:, target="_blank", and cancel buttons
        if (!href || href.startsWith('#') || href.startsWith('javascript') || link.target === '_blank') return;
        Loader.show();
    });

    // Auto-show on any form submit
    document.addEventListener('submit', () => Loader.show());

    // Hide on browser back/forward (bfcache restore)
    window.addEventListener('pageshow', e => {
        if (e.persisted) Loader.hide();
    });
})();