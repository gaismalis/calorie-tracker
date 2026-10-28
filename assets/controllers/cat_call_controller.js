import { Controller } from '@hotwired/stimulus';

// The 🐾 button on the main page: calls the cat. The cat controller (cat_controller.js, in the layout)
// decides whether it comes and answers next to this button.
// With the cat turned off, the 🐾 submits the form that turns it back on, and the cat comes as soon as the page
// is back (WAKE_KEY in sessionStorage, read by cat_controller.js).
export const WAKE_KEY = 'cat.wake';

export default class extends Controller {
    call() {
        this.dispatch('call', { prefix: 'cat', target: window, detail: { button: this.element } });
    }

    wake() {
        sessionStorage.setItem(WAKE_KEY, '1');
    }
}
