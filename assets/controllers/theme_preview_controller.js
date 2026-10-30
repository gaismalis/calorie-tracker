import { Controller } from '@hotwired/stimulus';

// Profile: picking a colour theme shows it right away (saving the profile keeps it).
export default class extends Controller {
    preview(event) {
        document.documentElement.dataset.theme = event.target.value;
    }
}
