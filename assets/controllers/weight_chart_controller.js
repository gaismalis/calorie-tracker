import { Controller } from '@hotwired/stimulus';

// Hover layer for the weight chart: a crosshair snaps to the nearest day with data and one
// tooltip shows the weigh-in and trend for that day. Arrow keys do the same for keyboard users.
// Everything shown here is also in the History table.
export default class extends Controller {
    static targets = ['svg', 'crosshair', 'tooltip'];
    static values = { days: Array };

    move(event) {
        const point = this.svgTarget.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        const x = point.matrixTransform(this.svgTarget.getScreenCTM().inverse()).x;
        this.show(this.nearest(x));
    }

    focus() {
        this.show(this.index ?? this.daysValue.length - 1);
    }

    key(event) {
        const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        if (!step) return;
        event.preventDefault();
        this.show(Math.min(this.daysValue.length - 1, Math.max(0, (this.index ?? this.daysValue.length - 1) + step)));
    }

    hide() {
        this.crosshairTarget.setAttribute('visibility', 'hidden');
        this.tooltipTarget.hidden = true;
    }

    nearest(x) {
        let best = 0;
        this.daysValue.forEach((day, i) => {
            if (Math.abs(day.x - x) < Math.abs(this.daysValue[best].x - x)) best = i;
        });
        return best;
    }

    show(index) {
        this.index = index;
        const day = this.daysValue[index];
        this.crosshairTarget.setAttribute('x1', day.x);
        this.crosshairTarget.setAttribute('x2', day.x);
        this.crosshairTarget.setAttribute('visibility', 'visible');

        const tip = this.tooltipTarget;
        tip.replaceChildren(this.row('date', day.label));
        if (day.kg !== null) tip.append(this.row('weigh-in', `${day.kg.toFixed(1)} kg`, 'Weigh-in'));
        if (day.trend !== null) tip.append(this.row('trend', `${day.trend.toFixed(1)} kg`, 'Trend'));
        tip.hidden = false;

        // Position next to the crosshair, flipping to the left near the right edge.
        const scale = this.svgTarget.getBoundingClientRect().width / this.svgTarget.viewBox.baseVal.width;
        const left = day.x * scale;
        const flip = left > this.element.clientWidth * 0.6;
        tip.style.left = flip ? '' : `${left + 12}px`;
        tip.style.right = flip ? `${this.element.clientWidth - left + 12}px` : '';
    }

    row(kind, value, name = null) {
        const row = document.createElement('div');
        row.className = `tip-${kind}`;
        const strong = document.createElement('strong');
        strong.textContent = value;
        if (name) {
            const key = document.createElement('span');
            key.className = 'key';
            row.append(key, strong, document.createTextNode(' ' + name));
        } else {
            row.append(strong);
        }
        return row;
    }
}
