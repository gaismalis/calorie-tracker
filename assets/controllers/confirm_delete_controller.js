import { Controller } from '@hotwired/stimulus';

// Two-click delete without a popup: the first click turns ✕ into a red trash icon,
// a second click (within a few seconds) submits. Otherwise it quietly resets.
const TRASH_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/>'
    + '<path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';

export default class extends Controller {
    static targets = ['button'];
    static values = { timeout: { type: Number, default: 3000 } };

    connect() {
        this.armed = false;
        this.originalHtml = this.buttonTarget.innerHTML;
        this.originalTitle = this.buttonTarget.title;
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    click(event) {
        if (this.armed) {
            return; // second click: let the form submit
        }
        event.preventDefault();
        this.armed = true;
        this.buttonTarget.innerHTML = TRASH_ICON;
        this.buttonTarget.title = 'Click again to delete';
        this.buttonTarget.classList.add('armed');
        this.timer = setTimeout(() => this.reset(), this.timeoutValue);
    }

    reset() {
        this.armed = false;
        this.buttonTarget.innerHTML = this.originalHtml;
        this.buttonTarget.title = this.originalTitle;
        this.buttonTarget.classList.remove('armed');
    }
}
