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
    };
})();

/*
 * Skeleton loaders: a grey outline of the next page appears the moment it is asked for, instead of the old page
 * sitting there with only the thin progress bar. Shown while moving between pages (only if the page is not already
 * here after a short moment, so prefetched pages never flash) and between "Sign in" and the first page.
 */
(() => {
    const SHOW_AFTER_MS = 120;
    let pendingSkeleton = null;

    const bar = (width, height = '.75rem', radius = null) => `<span class="colisap-skel" style="--w: ${width}; --h: ${height}${radius ? `; --r: ${radius}` : ''}"></span>`;

    const accent = '<span class="colisap-skel colisap-skel--accent" style="--w: 2.5rem; --h: 4px; --r: 9999px"></span>';

    const heading = (withButton) => `
        <div class="colisap-skel-head">
            <div>${accent}${bar('14rem', '1.7rem')}</div>
            ${withButton ? bar('9rem', '2.3rem', '.65rem') : ''}
        </div>`;

    const listPage = () => {
        const widths = [['72%', '58%', '64%', '40%'], ['60%', '70%', '48%', '52%'], ['80%', '46%', '58%', '36%'], ['66%', '62%', '70%', '44%']];
        const rows = Array.from({ length: 8 }, (_, index) => {
            const [name, detail, branch, date] = widths[index % widths.length];

            return `<div class="colisap-skel-row">${bar(name)}${bar(detail)}${bar(branch)}${bar('4.75rem', '1.4rem', '9999px')}${bar(date)}</div>`;
        }).join('');

        return `${heading(true)}
            <div class="colisap-skel-card">
                <div class="colisap-skel-toolbar">${bar('17rem', '2.3rem', '.6rem')}<div>${bar('2.3rem', '2.3rem', '.6rem')}${bar('2.3rem', '2.3rem', '.6rem')}</div></div>
                ${rows}
            </div>`;
    };

    const formPage = () => {
        const fields = ['32%', '24%', '40%', '28%', '36%', '22%'].map((label) => `<div>${bar(label, '.7rem')}${bar('100%', '2.5rem', '.6rem')}</div>`).join('');

        return `${heading(false)}
            <div class="colisap-skel-card">${bar('11rem', '1rem')}<div class="colisap-skel-form">${fields}</div></div>
            <div class="colisap-skel-actions">${bar('7.5rem', '2.3rem', '.65rem')}${bar('6rem', '2.3rem', '.65rem')}</div>`;
    };

    const chartCard = (extraClass = '') => `
        <div class="colisap-skel-card ${extraClass}">${bar('9rem', '.9rem')}${bar('13rem', '.65rem')}${bar('100%', '15rem', '.8rem')}</div>`;

    const dashboardPage = () => `
        <div class="colisap-skel-hero">
            ${bar('22rem', '.7rem')}${bar('26rem', '2.1rem')}${bar('34rem', '.8rem')}
            <div>${bar('9rem', '2.4rem', '.7rem')}${bar('8rem', '2.4rem', '.7rem')}${bar('8.5rem', '2.4rem', '.7rem')}</div>
        </div>
        <div class="colisap-skel-grid colisap-skel-grid--kpi">
            ${Array.from({ length: 4 }, () => `<div class="colisap-skel-card">${bar('2.5rem', '2.5rem', '.8rem')}${bar('55%', '.65rem')}${bar('45%', '2rem')}${bar('100%', '6px', '9999px')}</div>`).join('')}
        </div>
        <div class="colisap-skel-grid">${chartCard('colisap-skel-span-2')}${chartCard()}</div>`;

    const skeletonFor = (url) => {
        const path = (url?.pathname ?? window.location.pathname).replace(/\/+$/, '');

        if (/\/admin$/.test(path)) {
            return dashboardPage();
        }

        if (/\/(create|edit|import|profile|policy-settings)$/.test(path) || /\/\d+$/.test(path)) {
            return formPage();
        }

        return listPage();
    };

    const removePageSkeleton = () => {
        clearTimeout(pendingSkeleton);
        document.querySelectorAll('.colisap-page-skeleton').forEach((skeleton) => skeleton.remove());
        document.body.classList.remove('colisap-navigating');
    };

    document.addEventListener('livewire:navigate', (event) => {
        removePageSkeleton();

        if (event.detail?.cached) {
            return;
        }

        pendingSkeleton = setTimeout(() => {
            const main = document.querySelector('.fi-main');

            // Another script (e.g. the unsaved-changes prompt) may have cancelled this navigation.
            if (event.defaultPrevented || !main) {
                return;
            }

            document.body.classList.add('colisap-navigating');
            main.insertAdjacentHTML('afterbegin', `<div class="colisap-page-skeleton" role="status" aria-label="Loading page"><div class="colisap-page-skeleton__inner">${skeletonFor(event.detail?.url)}</div></div>`);
        }, SHOW_AFTER_MS);
    });

    // Cleared just before the new page is swapped in, so the skeleton never ends up in the back-button snapshot.
    document.addEventListener('livewire:navigating', removePageSkeleton);
    document.addEventListener('livewire:navigated', removePageSkeleton);

    const showAppSkeleton = (url) => {
        if (document.querySelector('.colisap-app-skeleton')) {
            return;
        }

        const navItems = ['62%', '48%', '70%', '54%', '44%', '66%', '58%'].map((width) => `<div>${bar('1.25rem', '1.25rem', '.4rem')}${bar(width, '.7rem')}</div>`).join('');

        document.body.insertAdjacentHTML('beforeend', `
            <div class="colisap-app-skeleton" role="status" aria-label="Loading COLISAP">
                <aside class="colisap-app-skeleton__sidebar"><div>${bar('2.75rem', '2.75rem', '9999px')}${bar('9rem', '.9rem')}</div>${navItems}</aside>
                <div class="colisap-app-skeleton__body">
                    <div class="colisap-app-skeleton__topbar">${bar('9rem', '2rem', '9999px')}${bar('2rem', '2rem', '9999px')}${bar('2.25rem', '2.25rem', '9999px')}</div>
                    <div class="colisap-page-skeleton__inner">${skeletonFor(url)}</div>
                </div>
            </div>`);
    };

    // Signing in ends with a full-page redirect; cover the wait for the first page with the app's outline.
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('commit', ({ component, succeed }) => {
            succeed(({ effects }) => {
                if (effects?.redirect && component.el.closest('.colisap-login')) {
                    showAppSkeleton(new URL(effects.redirect, window.location.href));
                }
            });
        });
    });

    // Coming back to the sign-in page with the browser's Back button restores it from memory, skeleton included.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('.colisap-app-skeleton').forEach((skeleton) => skeleton.remove());
            removePageSkeleton();
        }
    });
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
