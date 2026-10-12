import { Controller } from '@hotwired/stimulus';

// "We think it's about 500 kcal" + slider: scales the amount of every item of a meal (grams) or
// exercise (kcal) together. The slider runs from -100 to 100 with 100 % in the middle; it is
// logarithmic, so half and double are equally far from the centre: factor = RANGE ^ (value / 100),
// i.e. 40 %–250 %. The per-item inputs in Details stay editable; editing an amount moves the slider
// to match. The server saves whatever the inputs contain.
//
// Each row (a <tr> or an item's <tbody>):
// - data-base: the amount input's starting value; the amount input is marked data-amount;
// - nutrition per unit of amount comes from data-per100="kcal|protein|carbs|fat" inputs (meals: values
//   per 100 g, editable from the label) or else from data-kcal/-protein/-carbs/-fat at the base amount.
// Correcting a value per 100 g changes the calories, not the amount, so the slider stays where it is.
const RANGE = 2.5;
const FIELDS = ['kcal', 'protein', 'carbs', 'fat'];

export default class extends Controller {
    static targets = ['slider', 'percent', 'total', 'protein', 'carbs', 'fat', 'row'];

    slide() {
        const factor = RANGE ** (this.sliderTarget.value / 100);
        for (const row of this.rowTargets) {
            this.amountInput(row).value = this.round(parseFloat(row.dataset.base) * factor, 1);
        }
        this.update(false);
    }

    edit() {
        this.update(true);
    }

    update(moveSlider) {
        const sums = { kcal: 0, protein: 0, carbs: 0, fat: 0 };
        let kcalAtBase = 0; // the same foods (current values per 100 g) at their starting amounts
        for (const row of this.rowTargets) {
            const base = parseFloat(row.dataset.base);
            const amount = this.number(this.amountInput(row).value);
            for (const field of FIELDS) {
                const perUnit = this.perUnit(row, field, base);
                if (perUnit === null) continue;
                // Rows that started at 0 without per-100 g values keep their values (nothing to scale by).
                const value = perUnit === undefined ? parseFloat(row.dataset[field]) : amount * perUnit;
                sums[field] += value;
                if (field === 'kcal') kcalAtBase += perUnit === undefined ? value : base * perUnit;
                const cell = row.querySelector(`[data-field="${field}"]`);
                if (cell) cell.textContent = field === 'kcal' ? Math.round(value) : this.round(value, 1);
            }
        }

        this.totalTarget.textContent = Math.round(sums.kcal);
        for (const field of ['protein', 'carbs', 'fat']) {
            const target = this[`has${field[0].toUpperCase()}${field.slice(1)}Target`] ? this[`${field}Target`] : null;
            if (target) target.textContent = Math.round(sums[field]);
        }
        const percent = kcalAtBase > 0 ? Math.round((sums.kcal / kcalAtBase) * 100) : 100;
        this.percentTarget.textContent = percent;
        this.sliderTarget.setAttribute('aria-valuetext', `${percent}% of the estimate, ${Math.round(sums.kcal)} kcal`);
        if (moveSlider && percent > 0) {
            this.sliderTarget.value = Math.max(-100, Math.min(100, Math.round((Math.log(percent / 100) / Math.log(RANGE)) * 100)));
        }
    }

    amountInput(row) {
        return row.querySelector('[data-amount]');
    }

    // Nutrition per unit of amount; null = not shown for this row, undefined = can't scale (base 0).
    perUnit(row, field, base) {
        const input = row.querySelector(`[data-per100="${field}"]`);
        if (input) return this.number(input.value) / 100;
        if (row.dataset[field] === undefined) return null;
        return base > 0 ? parseFloat(row.dataset[field]) / base : undefined;
    }

    number(text) {
        const value = parseFloat(String(text).replace(',', '.'));
        return Number.isFinite(value) && value >= 0 ? value : 0;
    }

    round(value, decimals) {
        const f = 10 ** decimals;
        return Math.round(value * f) / f;
    }
}
