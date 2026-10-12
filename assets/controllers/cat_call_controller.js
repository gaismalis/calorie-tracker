import { Controller } from '@hotwired/stimulus';

// The 🐾 button on the main page: calls the cat. The cat controller (cat_controller.js, in the layout)
// decides whether it comes and answers next to this button.
export default class extends Controller {
    call() {
        this.dispatch('call', { prefix: 'cat', target: window, detail: { button: this.element } });
    }
}
