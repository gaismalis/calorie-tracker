import { Controller } from '@hotwired/stimulus';

// Live preview on the "check your meal" page: kcal and macros scale with the grams typed.
// The server does the same calculation on save; this is only for instant feedback.
export default class extends Controller {
    static targets = ['row', 'total'];
    static fields = ['kcal', 'protein', 'carbs', 'fat'];

    recalculate() {
        const totals = Object.fromEntries(this.constructor.fields.map((f) => [f, 0]));

        for (const row of this.rowTargets) {
            const original = parseFloat(row.dataset.grams);
            const grams = parseFloat(row.querySelector('input').value.replace(',', '.'));
            const factor = original > 0 && grams >= 0 ? grams / original : 1;

            for (const field of this.constructor.fields) {
                const value = parseFloat(row.dataset[field]) * factor;
                totals[field] += value;
                row.querySelector(`[data-field="${field}"]`).textContent = this.format(field, value);
            }
        }

        for (const cell of this.totalTargets) {
            cell.textContent = this.format(cell.dataset.field, totals[cell.dataset.field]);
        }
    }

    format(field, value) {
        return field === 'kcal' ? Math.round(value).toString() : (Math.round(value * 10) / 10).toString();
    }
}
