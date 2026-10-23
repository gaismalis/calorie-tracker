import { Controller } from '@hotwired/stimulus';

// The recipe form: add and remove foods, and the live total of kcal and macros.
export default class extends Controller {
    static targets = ['list', 'item', 'template', 'kcal', 'protein', 'carbs', 'fat'];

    connect() {
        // New rows get indexes after the highest one already there (rows shown again after an error keep theirs).
        this.next = Math.max(-1, ...this.itemTargets.map((item) => {
            const match = item.querySelector('input')?.name.match(/^items\[(\d+)\]/);
            return match ? Number(match[1]) : -1;
        })) + 1;
        this.update();
    }

    add() {
        const html = this.templateTarget.innerHTML.replaceAll('__i__', String(this.next++));
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.itemTargets.at(-1).querySelector('input')?.focus();
    }

    // The last food is cleared instead, so there's always a row to fill in.
    remove(event) {
        const item = event.target.closest('[data-recipe-form-target="item"]');
        if (this.itemTargets.length > 1) {
            item.remove();
        } else {
            item.querySelectorAll('input').forEach((input) => { input.value = ''; });
        }
        this.update();
    }

    update() {
        for (const field of ['kcal', 'protein', 'carbs', 'fat']) {
            const sum = [...this.element.querySelectorAll(`[data-total="${field}"]`)]
                .reduce((total, input) => total + (parseFloat(input.value.replace(',', '.')) || 0), 0);
            this[`${field}Target`].textContent = field === 'kcal' ? Math.round(sum) : Math.round(sum * 10) / 10;
        }
    }
}
