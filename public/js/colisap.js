(() => {
    const root = document.documentElement;
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cores = navigator.hardwareConcurrency || 8;
    const memoryGb = navigator.deviceMemory || 8;
    const isLite = prefersReducedMotion || cores <= 4 || memoryGb <= 4;

    if (isLite) {
        root.classList.add('colisap-lite');
    }

    window.colisapIsLite = isLite;

    // Menu links start loading their page once the pointer rests on them (Livewire's wire:navigate.hover), so
    // by the click the page is usually already here. Renamed before Livewire reads the links: on first load
    // (this file runs before Livewire's own DOMContentLoaded handler) and right after each page swap.
    // Table rows are left alone, so moving across a list does not fetch every row.
    const PREFETCHED_LINKS = '.fi-sidebar a[wire\\:navigate], .fi-topbar a[wire\\:navigate], .fi-breadcrumbs a[wire\\:navigate]';
    const prefetchOnHover = () => document.querySelectorAll(PREFETCHED_LINKS).forEach((link) => {
        link.removeAttribute('wire:navigate');
        link.setAttribute('wire:navigate.hover', '');
    });

    document.addEventListener('DOMContentLoaded', prefetchOnHover);
    document.addEventListener('livewire:navigating', (event) => event.detail?.onSwap?.(prefetchOnHover));

    const formatter = new Intl.NumberFormat('en-US');

    window.colisapWhenSplashDone = (callback) => {
        const splash = document.getElementById('colisap-splash');

        if (!splash || splash.classList.contains('is-leaving')) {
            callback();

            return;
        }

        window.addEventListener('colisap:splash-done', callback, { once: true });
    };

    window.colisapCountUp = (element, target) => {
        if (element.dataset.counted === String(target)) {
            return;
        }

        element.dataset.counted = String(target);

        if (isLite || target === 0) {
            element.textContent = formatter.format(target);

            return;
        }

        element.textContent = '0';

        window.colisapWhenSplashDone(() => {
            const duration = 700;
            const startedAt = performance.now();

            const step = (now) => {
                const progress = Math.min((now - startedAt) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 4);

                element.textContent = formatter.format(Math.round(target * eased));

                if (progress < 1) {
                    requestAnimationFrame(step);
                }
            };

            requestAnimationFrame(step);
        });
    };
})();

/*
 * Tabs: a pill that glides to the selected tab, and table content that slides in
 * from the side the user moved towards. Tabs switch through Livewire, so the page never reloads.
 */
(() => {
    const TABS = '.fi-main .fi-tabs';
    const motionEnabled = () => !window.colisapIsLite;
    let pendingSlide = null;

    const placePill = (nav, item, instant = false) => {
        const navBox = nav.getBoundingClientRect();
        const box = item.getBoundingClientRect();

        nav.classList.toggle('colisap-tabs--instant', instant);
        nav.style.setProperty('--tab-x', `${box.left - navBox.left + nav.scrollLeft}px`);
        nav.style.setProperty('--tab-y', `${box.top - navBox.top}px`);
        nav.style.setProperty('--tab-w', `${box.width}px`);
        nav.style.setProperty('--tab-h', `${box.height}px`);
        nav.classList.add('colisap-tabs--ready');

        if (instant) {
            requestAnimationFrame(() => requestAnimationFrame(() => nav.classList.remove('colisap-tabs--instant')));
        }
    };

    const syncAll = (instant = false) => {
        document.querySelectorAll(TABS).forEach((nav) => {
            const active = nav.querySelector('.fi-tabs-item.fi-active');

            if (active) {
                placePill(nav, active, instant || !nav.classList.contains('colisap-tabs--ready'));
            }
        });
    };

    const slideTarget = (component) => component?.querySelector('.fi-ta-content') ?? component?.querySelector('.fi-ta-ctn');

    document.addEventListener('click', (event) => {
        const item = event.target.closest(`${TABS} .fi-tabs-item`);

        if (!item || item.classList.contains('fi-active')) {
            return;
        }

        const nav = item.closest('.fi-tabs');
        const items = [...nav.querySelectorAll('.fi-tabs-item')];
        const from = items.findIndex((tab) => tab.classList.contains('fi-active'));
        const to = items.indexOf(item);

        nav.classList.add('colisap-tabs--pending');
        items.forEach((tab) => tab.classList.toggle('colisap-tab-target', tab === item));
        placePill(nav, item);

        if (!motionEnabled()) {
            return;
        }

        const component = item.closest('[wire\:id]');
        const target = slideTarget(component);
        const direction = to > from ? 'right' : 'left';

        pendingSlide = { component, direction };
        target?.classList.add(`colisap-slide-out-${direction}`);
    }, true);

    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('morph.updating', ({ el, toEl }) => {
            if (!(el instanceof HTMLElement)) {
                return;
            }

            if (el.classList.contains('fi-tabs') && el.classList.contains('colisap-tabs--ready')) {
                toEl.setAttribute('style', el.getAttribute('style') ?? '');
                toEl.classList.add('colisap-tabs--ready');
            }

            if (pendingSlide && el === slideTarget(pendingSlide.component)) {
                toEl.classList.add(`colisap-slide-in-${pendingSlide.direction}`);
            }
        });

        window.Livewire.hook('commit', ({ component, succeed, fail }) => {
            fail(() => {
                slideTarget(component.el)?.classList.remove('colisap-slide-out-left', 'colisap-slide-out-right');
                pendingSlide = null;
                document.querySelectorAll('.colisap-tabs--pending').forEach((nav) => nav.classList.remove('colisap-tabs--pending'));
                document.querySelectorAll('.colisap-tab-target').forEach((tab) => tab.classList.remove('colisap-tab-target'));
                syncAll();
            });

            succeed(() => requestAnimationFrame(() => {
                syncAll();

                if (!pendingSlide || pendingSlide.component !== component.el) {
                    return;
                }

                const target = slideTarget(component.el);

                if (target) {
                    target.classList.remove('colisap-slide-out-left', 'colisap-slide-out-right');

                    if (!target.classList.contains(`colisap-slide-in-${pendingSlide.direction}`)) {
                        target.classList.add(`colisap-slide-in-${pendingSlide.direction}`);
                    }

                    target.addEventListener('animationend', () => target.classList.remove('colisap-slide-in-left', 'colisap-slide-in-right'), { once: true });
                }

                pendingSlide = null;
            }));
        });
    });

    document.addEventListener('DOMContentLoaded', () => syncAll(true));
    document.addEventListener('livewire:navigated', () => syncAll(true));
    window.addEventListener('resize', () => syncAll(true), { passive: true });
})();
