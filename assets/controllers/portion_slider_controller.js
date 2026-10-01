import { Controller } from '@hotwired/stimulus';

// "We think it's about 500 kcal" + slider: scales every item of a meal (grams) or exercise (kcal)
// together. The slider runs from -100 to 100 with 100 % in the middle; it is logarithmic, so half
// and double are equally far from the centre: factor = RANGE ^ (value / 100), i.e. 40 %–250 %.
// The per-item inputs in Details stay editable; editing one moves the slider to match.
// The server saves whatever the inputs contain.
//
// Each row: data-base = the input's starting value; data-kcal/-protein/-carbs/-fat = nutrition at that value.
const RANGE = 2.5;

export default class extends Controller {
    static targets = ['slider', 'percent', 'total', 'protein', 'carbs', 'fat', 'row'];

    slide() {
        const factor = RANGE ** (this.sliderTarget.value / 100);
        for (const row of this.rowTargets) {
            row.querySelector('input').value = this.round(parseFloat(row.dataset.base) * factor, 1);
        }
        this.update(false);
    }

    edit() {
        this.update(true);
    }

    update(moveSlider) {
        const sums = { kcal: 0, protein: 0, carbs: 0, fat: 0 };
        let base = 0;
        for (const row of this.rowTargets) {
            const start = parseFloat(row.dataset.base);
            const value = parseFloat(row.querySelector('input').value.replace(',', '.'));
            const factor = start > 0 && value >= 0 ? value / start : 1;
            base += parseFloat(row.dataset.kcal);
            for (const field of Object.keys(sums)) {
                if (row.dataset[field] === undefined) continue;
                const amount = parseFloat(row.dataset[field]) * factor;
                sums[field] += amount;
                const cell = row.querySelector(`[data-field="${field}"]`);
                if (cell) cell.textContent = field === 'kcal' ? Math.round(amount) : this.round(amount, 1);
            }
        }

        this.totalTarget.textContent = Math.round(sums.kcal);
        for (const field of ['protein', 'carbs', 'fat']) {
            const target = this[`has${field[0].toUpperCase()}${field.slice(1)}Target`] ? this[`${field}Target`] : null;
            if (target) target.textContent = Math.round(sums[field]);
        }
        const percent = base > 0 ? Math.round((sums.kcal / base) * 100) : 100;
        this.percentTarget.textContent = percent;
        this.sliderTarget.setAttribute('aria-valuetext', `${percent}% of the estimate, ${Math.round(sums.kcal)} kcal`);
        if (moveSlider && percent > 0) {
            this.sliderTarget.value = Math.max(-100, Math.min(100, Math.round((Math.log(percent / 100) / Math.log(RANGE)) * 100)));
        }
    }

    round(value, decimals) {
        const f = 10 ** decimals;
        return Math.round(value * f) / f;
    }
}
