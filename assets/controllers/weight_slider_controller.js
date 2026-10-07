import { Controller } from '@hotwired/stimulus';

// Quick weigh-in: a slider for speed, the number field and ± 0.1 buttons for precision; all stay in sync.
export default class extends Controller {
    static targets = ['slider', 'number'];

    slid() {
        this.numberTarget.value = parseFloat(this.sliderTarget.value).toFixed(1);
    }

    typed() {
        const value = parseFloat(this.numberTarget.value.replace(',', '.'));
        if (!Number.isNaN(value)) this.sliderTarget.value = value; // the browser clamps it to the slider's range
    }

    step({ params: { delta } }) {
        const current = parseFloat(this.numberTarget.value.replace(',', '.')) || parseFloat(this.sliderTarget.value);
        const next = Math.min(400, Math.max(20, Math.round((current + delta) * 10) / 10));
        this.numberTarget.value = next.toFixed(1);
        this.sliderTarget.value = next;
    }
}
