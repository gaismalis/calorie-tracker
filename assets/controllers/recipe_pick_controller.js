import { Controller } from '@hotwired/stimulus';

// Recipes in the add-meal form. While the text box is empty, all recipes are listed (scrollable); while
// typing, only those matching the typed words (in the recipe's name or its foods), best match first, so
// "greek yog" suggests "Greek yogurt bowl". Picking one makes it the base of the meal (logged with its
// own numbers, no AI) and the text box becomes "anything extra this time?", which the AI estimates.
export default class extends Controller {
    static targets = ['input', 'base', 'baseName', 'baseKcal', 'baseItems', 'picks', 'title', 'option', 'text'];
    static values = { placeholder: String, extrasPlaceholder: String };

    filter() {
        if (this.inputTarget.value) return; // a recipe is picked: the text is what was added
        const words = this.words(this.textTarget.value);
        let shown = 0;
        this.optionTargets.forEach((option, index) => {
            const terms = this.words(option.dataset.search);
            const score = words.length === 0 ? 1 : words.filter((word) => terms.some((term) => term.startsWith(word))).length;
            option.hidden = score === 0;
            option.style.order = words.length === 0 ? '' : String(index - score * 1000);
            if (score > 0) shown++;
        });
        this.picksTarget.hidden = shown === 0;
        this.titleTarget.textContent = words.length === 0 ? 'Your recipes (no AI):' : 'Matching recipes (no AI):';
    }

    pick(event) {
        const option = event.currentTarget.closest('[data-recipe-pick-target="option"]');
        this.inputTarget.value = option.dataset.id;
        this.baseNameTarget.textContent = option.dataset.name;
        this.baseKcalTarget.textContent = option.dataset.kcal;
        this.baseItemsTarget.textContent = option.dataset.items;
        this.baseTarget.hidden = false;
        this.picksTarget.hidden = true;
        // What was typed was the search; now the box is for extras.
        this.textTarget.value = '';
        this.textTarget.required = false;
        this.textTarget.placeholder = this.extrasPlaceholderValue;
        this.textTarget.focus();
    }

    clear() {
        this.inputTarget.value = '';
        this.baseTarget.hidden = true;
        const photo = this.element.querySelector('input[type=file]')?.files.length > 0;
        this.textTarget.required = !photo;
        if (!photo) this.textTarget.placeholder = this.placeholderValue;
        this.filter();
        this.textTarget.focus();
    }

    // Lower-case words of at least 2 letters, without accents ("Caffè" → "caffe").
    words(text) {
        return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().split(/[^\p{L}\p{N}]+/u).filter((word) => word.length >= 2);
    }
}
