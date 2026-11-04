/**
 * Styled tooltips for title attributes.
 *
 * Replaces the browser's native title tooltip with one that matches the app (light and dark
 * theme), wraps long text and stays inside the window. Titles are picked up on hover or
 * keyboard focus, so elements added later work too. Add data-native-title to an element
 * to keep the browser's own tooltip.
 */
(function () {
    'use strict';

    const SHOW_DELAY = 250; // ms of hovering before the tooltip appears
    const GAP = 8;          // px between the element and the tooltip
    const EDGE = 8;         // px the tooltip keeps from the window edges

    let tooltip = null;
    let textEl = null;
    let hovered = null;     // element under the mouse (tooltip may still be waiting to show)
    let shown = null;       // element the tooltip is currently shown for
    let timer = null;

    function getTooltip() {
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'app-tooltip';
            tooltip.className = 'app-tooltip';
            tooltip.setAttribute('role', 'tooltip');
            tooltip.hidden = true;

            textEl = document.createElement('span');
            const arrow = document.createElement('span');
            arrow.className = 'app-tooltip-arrow';

            tooltip.append(textEl, arrow);
            document.body.appendChild(tooltip);
        }
        return tooltip;
    }

    function findTarget(node) {
        if (!(node instanceof Element)) {
            return null;
        }

        const el = node.closest('[title], [data-tooltip]');
        if (!el || el.hasAttribute('data-native-title') || el.tagName === 'IFRAME') {
            return null;
        }
        return el;
    }

    // Move the title into data-tooltip so the browser does not show its own tooltip as well
    function takeTitle(el) {
        const title = el.getAttribute('title');
        if (title !== null) {
            el.removeAttribute('title');
            el.setAttribute('data-tooltip', title);

            // Icon-only buttons and links used the title as their accessible name
            if (!el.hasAttribute('aria-label') && el.textContent.trim() === '') {
                el.setAttribute('aria-label', title);
            }
        }
        return (el.getAttribute('data-tooltip') || '').trim();
    }

    function position(el) {
        const target = el.getBoundingClientRect();
        tooltip.style.left = '0px';
        tooltip.style.top = '0px';
        const box = tooltip.getBoundingClientRect();

        // Above the element, or below it when there is no room above
        let top = target.top - box.height - GAP;
        const below = top < EDGE;
        if (below) {
            top = target.bottom + GAP;
        }

        const center = target.left + target.width / 2;
        const left = Math.max(EDGE, Math.min(center - box.width / 2, window.innerWidth - box.width - EDGE));

        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
        tooltip.classList.toggle('is-below', below);
        tooltip.style.setProperty('--app-tooltip-arrow', Math.max(10, Math.min(center - left, box.width - 10)) + 'px');
    }

    function show(el) {
        const text = takeTitle(el);
        if (!text || !el.isConnected) {
            return;
        }

        getTooltip();
        textEl.textContent = text;
        tooltip.classList.remove('is-visible');
        tooltip.hidden = false;
        position(el);
        shown = el;

        if (el.getAttribute('aria-label') !== text && !el.hasAttribute('aria-describedby')) {
            el.setAttribute('aria-describedby', tooltip.id);
        }

        requestAnimationFrame(function () {
            if (shown === el) {
                tooltip.classList.add('is-visible');
            }
        });
    }

    function hide() {
        clearTimeout(timer);
        timer = null;
        hovered = null;

        if (shown && shown.getAttribute('aria-describedby') === 'app-tooltip') {
            shown.removeAttribute('aria-describedby');
        }
        shown = null;

        if (tooltip) {
            tooltip.classList.remove('is-visible');
            tooltip.hidden = true;
        }
    }

    function isKeyboardFocus(el) {
        try {
            return el.matches(':focus-visible');
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('pointerover', function (event) {
        if (event.pointerType !== 'mouse') {
            return;
        }

        const el = findTarget(event.target);
        if (el === hovered) {
            return;
        }

        hide();
        if (!el) {
            return;
        }

        // Take the title right away, before the browser's own tooltip delay runs out
        takeTitle(el);
        hovered = el;
        timer = setTimeout(function () {
            show(el);
        }, SHOW_DELAY);
    });

    document.addEventListener('pointerout', function (event) {
        if (!event.relatedTarget) {
            hide();
        }
    });

    document.addEventListener('focusin', function (event) {
        const el = findTarget(event.target);
        if (el && isKeyboardFocus(event.target)) {
            hide();
            hovered = el;
            show(el);
        }
    });

    document.addEventListener('focusout', hide);
    document.addEventListener('pointerdown', hide);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hide();
        }
    });
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
