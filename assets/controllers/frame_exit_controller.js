import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

// Rendered into the add/adjust dialog's frame when the next step is a whole page (e.g. the main
// page after saving). Closes the dialog and visits that page.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.element.closest('dialog')?.close();
        visit(this.urlValue);
    }
}
