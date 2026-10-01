import { Controller } from '@hotwired/stimulus';

// Scrolls this element into view once the page has rendered, e.g. the day's log after deleting
// an entry from it. Turbo scrolls to the top after a visit, so this waits for turbo:load.
export default class extends Controller {
    connect() {
        this.scroll = () => this.element.scrollIntoView({ block: 'start' });
        document.addEventListener('turbo:load', this.scroll, { once: true });
        requestAnimationFrame(this.scroll); // first load without Turbo
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.scroll);
    }
}
