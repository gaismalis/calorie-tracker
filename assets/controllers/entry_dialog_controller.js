import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

// One dialog for adding and adjusting meals and exercise. Its Turbo frame loads the form
// (/meals/new, /exercises/new), then — after the AI answers — the "we think it's about X kcal"
// review with the slider (/…/{id}/edit). Responses without the frame (e.g. the main page after
// saving, or with a message) are shown as a normal full-page visit.
export default class extends Controller {
    static targets = ['toggle', 'menu', 'dialog', 'frame'];

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.hidden ? this.openMenu() : this.closeMenu();
    }

    openMenu() {
        this.menuTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.menuTarget.querySelector('a, button')?.focus();
    }

    closeMenu() {
        if (!this.hasMenuTarget) return;
        this.menuTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }

    outside(event) {
        if (!this.menuTarget.hidden && !this.menuTarget.contains(event.target) && !this.toggleTarget.contains(event.target)) {
            this.closeMenu();
        }
    }

    // From the + menu or an "Adjust" link: load its URL into the dialog.
    open(event) {
        event.preventDefault();
        this.closeMenu();
        this.frameTarget.replaceChildren(this.loadingMessage());
        this.frameTarget.src = null; // force a reload even if the same URL was open before
        this.frameTarget.src = event.currentTarget.href;
        if (!this.dialogTarget.open) this.dialogTarget.showModal();
    }

    loaded() {
        if (!this.dialogTarget.open) return;
        this.dialogTarget.querySelector('textarea, input[type=range]')?.focus();
        // Show "Estimating…" on the submit button while the AI works (up to ~5 s).
        this.frameTarget.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
            const button = form.querySelector('button[type=submit][data-busy-label]');
            if (button) { button.disabled = true; button.textContent = button.dataset.busyLabel; }
        }, { once: true }));
    }

    close() {
        this.dialogTarget.close();
    }

    // Where the last press started: closing on "click outside" requires the press to start outside too.
    // Otherwise dragging a slider and releasing past the dialog's edge counts as a click on the backdrop
    // in some browsers (Safari, Firefox), and the dialog would close mid-drag.
    pressed(event) {
        this.pressStartedOutside = event.target === event.currentTarget && this.isOutside(event);
    }

    // A click on the dark area around the dialog lands on the <dialog> element itself.
    backdrop(event) {
        const startedOutside = this.pressStartedOutside;
        this.pressStartedOutside = false;
        if (event.target === event.currentTarget && startedOutside && this.isOutside(event)) this.dialogTarget.close();
    }

    isOutside(event) {
        const box = this.dialogTarget.getBoundingClientRect();
        return event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
    }

    // After closing a freshly logged entry's review, reload so it shows up in the overview and log.
    closed() {
        const leaving = this.frameTarget.querySelector('[data-controller~="frame-exit"]');
        const reload = !leaving && this.frameTarget.querySelector('[data-reload-on-close]');
        this.frameTarget.replaceChildren();
        this.frameTarget.removeAttribute('src');
        if (reload) visit(window.location.href, { action: 'replace' });
    }

    // The response isn't a frame (e.g. redirect to the main page with a message): show it as the whole page.
    leaveFrame(event) {
        event.preventDefault();
        this.dialogTarget.close();
        event.detail.visit(event.detail.response);
    }

    loadingMessage() {
        const p = document.createElement('p');
        p.className = 'muted';
        p.textContent = 'Loading…';
        return p;
    }
}
