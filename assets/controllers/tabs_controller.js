import { Controller } from '@hotwired/stimulus';

// Accessible tabs: buttons with role="tab" switch between role="tabpanel" sections (← → to move).
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.tabTargets.forEach((tab) => tab.addEventListener('keydown', (event) => this.key(event)));
    }

    select(event) {
        this.activate(this.tabTargets.indexOf(event.currentTarget));
    }

    key(event) {
        const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        if (!step) return;
        const index = (this.tabTargets.indexOf(event.currentTarget) + step + this.tabTargets.length) % this.tabTargets.length;
        this.activate(index);
        this.tabTargets[index].focus();
    }

    activate(index) {
        this.tabTargets.forEach((tab, i) => {
            tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
            tab.tabIndex = i === index ? 0 : -1;
        });
        this.panelTargets.forEach((panel, i) => { panel.hidden = i !== index; });
    }
}
