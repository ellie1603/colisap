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

/*
 * Back button (topbar): shown only on pages opened from inside a module (a member opened from Monitoring,
 * an edit form, the import wizard) — never on the modules themselves. It returns to the page the user came
 * from, on the tab, search and filters it was left on, and its label says where it leads.
 */
(() => {
    const KEY = 'colisap.trail';
    const LIMIT = 30;

    const read = () => {
        try {
            return JSON.parse(sessionStorage.getItem(KEY)) ?? [];
        } catch {
            return [];
        }
    };

    const write = (trail) => {
        try {
            sessionStorage.setItem(KEY, JSON.stringify(trail.slice(-LIMIT)));
        } catch {
            // Storage unavailable (private mode): the button simply stays hidden.
        }
    };

    const pageName = () => document.querySelector('.fi-header-heading')?.textContent.trim()
        || document.querySelector('.fi-sidebar-item-active .fi-sidebar-item-label')?.textContent.trim()
        || document.title.split(' - ')[0].trim();

    const pathOf = (url) => new URL(url, location.origin).pathname.replace(/\/+$/, '') || '/';

    // A module is a page opened straight from the sidebar menu (Dashboard, Members, Monitoring…): no Back there.
    const isModulePage = () => [...document.querySelectorAll('.fi-sidebar-item a[href]')]
        .some((link) => pathOf(link.href) === pathOf(location.href));

    // The module a detail page belongs to — where Back leads when the page was opened directly.
    const parentModule = () => {
        const link = document.querySelector('.fi-sidebar-item.fi-active a[href]');
        const name = link?.querySelector('.fi-sidebar-item-label')?.textContent.trim();

        return link && name ? { path: new URL(link.href).pathname, url: link.href, name } : null;
    };

    const render = (trail) => {
        const target = isModulePage() ? null : trail[trail.length - 2];

        document.querySelectorAll('[data-colisap-back]').forEach((button) => {
            button.hidden = !target;

            if (target) {
                button.title = `Back to ${target.name}`;
                button.querySelector('[data-colisap-back-label]').textContent = `Back to ${target.name}`;
            }
        });
    };

    const record = () => {
        if (/\/login\/?$/.test(location.pathname)) {
            write([]);

            return;
        }

        if (!document.querySelector('[data-colisap-back]')) {
            return;
        }

        let trail = read();
        const here = { path: location.pathname, url: location.href, name: pageName() };
        const top = trail[trail.length - 1];
        const previous = trail[trail.length - 2];

        if (isModulePage()) {
            // Opening a module from the menu starts a fresh trail: Back only appears on the pages opened from it.
            trail = [here];
        } else if (top?.path === here.path) {
            trail[trail.length - 1] = here;
        } else if (previous?.path === here.path) {
            // Went back (this button, the browser's Back, or the menu): drop the page that was left.
            trail.pop();
            trail[trail.length - 1] = here;
        } else if (top && /\/create\/?$/.test(top.path)) {
            // A saved "create" form leads to the new record; Back should skip the empty form.
            trail[trail.length - 1] = here;
        } else {
            trail.push(here);
        }

        if (trail.length === 1 && !isModulePage()) {
            const parent = parentModule();

            if (parent && parent.path !== here.path) {
                trail.unshift(parent);
            }
        }

        write(trail);
        render(read());
    };

    // Tabs, search and filters update the address without a page change: keep where the page ended up.
    const remember = () => {
        const trail = read();
        const top = trail[trail.length - 1];

        if (top?.path === location.pathname) {
            top.url = location.href;
            write(trail);
        }
    };

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-colisap-back]')) {
            return;
        }

        const trail = read();
        const target = trail[trail.length - 2];

        if (!target) {
            return;
        }

        remember();

        if (window.Livewire?.navigate) {
            window.Livewire.navigate(target.url);
        } else {
            window.location.assign(target.url);
        }
    });

    document.addEventListener('livewire:navigate', remember);
    window.addEventListener('pagehide', remember);
    document.addEventListener('DOMContentLoaded', record);
    document.addEventListener('livewire:navigated', record);
})();
