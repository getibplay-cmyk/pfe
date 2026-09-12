export function createAppShell(root = document, host = window) {
    let previousOverflow = null;
    let desktop = null;
    let onViewportChange = null;
    let onPageHide = null;

    return {
        mobileMenu: false,
        menuTrigger: null,
        init() {
            desktop = host.matchMedia('(min-width: 1024px)');
            onViewportChange = (event) => { if (event.matches) this.closeMenu(false); };
            onPageHide = () => this.closeMenu(false);
            desktop.addEventListener('change', onViewportChange);
            host.addEventListener('pagehide', onPageHide);
        },
        openMenu(trigger) {
            if (desktop?.matches || this.mobileMenu) return;
            this.menuTrigger = trigger;
            previousOverflow = root.documentElement.style.overflow;
            root.documentElement.style.overflow = 'hidden';
            this.mobileMenu = true;
            this.$nextTick(() => this.$refs.mobilePanel?.querySelector('a, button')?.focus());
        },
        closeMenu(returnFocus = true) {
            if (! this.mobileMenu) return;
            this.mobileMenu = false;
            if (previousOverflow !== null) root.documentElement.style.overflow = previousOverflow;
            previousOverflow = null;
            if (returnFocus) this.$nextTick(() => this.menuTrigger?.focus());
        },
        trapMenu(event) {
            if (! this.mobileMenu || event.key !== 'Tab') return;
            const focusable = [...this.$refs.mobilePanel.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )].filter((element) => element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable.at(-1);
            if (! first) { event.preventDefault(); return; }
            if (event.shiftKey && root.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && root.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        destroy() {
            this.closeMenu(false);
            desktop?.removeEventListener('change', onViewportChange);
            host.removeEventListener('pagehide', onPageHide);
        },
    };
}
