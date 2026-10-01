import { Controller } from '@hotwired/stimulus';

// Floating "+" button: opens a small menu (Meal / Exercise), each opening a dialog with its form.
export default class extends Controller {
    static targets = ['toggle', 'menu', 'dialog'];

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.hidden ? this.openMenu() : this.closeMenu();
    }

    openMenu() {
        this.menuTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.menuTarget.querySelector('button')?.focus();
    }

    closeMenu() {
        this.menuTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }

    outside(event) {
        if (!this.menuTarget.hidden && !this.element.contains(event.target)) this.closeMenu();
    }

    open({ params: { kind } }) {
        this.closeMenu();
        const dialog = this.dialogTargets.find((d) => d.dataset.kind === kind);
        dialog.showModal();
        dialog.querySelector('textarea')?.focus();
    }

    close(event) {
        event.target.closest('dialog').close();
        this.toggleTarget.focus();
    }

    // A click on the dark area around the dialog lands on the <dialog> element itself.
    backdrop(event) {
        if (event.target === event.currentTarget) event.currentTarget.close();
    }
}
