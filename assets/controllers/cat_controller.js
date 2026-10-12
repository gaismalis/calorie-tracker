import { Controller } from '@hotwired/stimulus';

// Just for fun: now and then a cat walks onto the screen and sits down on top of things. Tap it a random
// number of times (1 to maxTaps) and it runs off. The next visit is due minHours–maxHours after the last
// one, kept in localStorage so page loads don't reset it; once due, the cat shows up a few seconds into
// the next page. A cat that wasn't shooed comes back on the next page. Skipped for "reduce motion".
const NEXT_VISIT_KEY = 'cat.nextVisit';
const ARRIVAL_SECONDS = [3, 12];
const SIZE = 64;
const WALK_PX_PER_SECOND = 120;
const SOUNDS = ['meow', 'mrrp?', 'purrr', 'no.', '*yawn*', 'hmm?', 'mew', '5 more minutes'];

const between = (min, max) => min + Math.random() * (max - min);

export default class extends Controller {
    static values = { minHours: Number, maxHours: Number, maxTaps: Number };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        let due = Number(localStorage.getItem(NEXT_VISIT_KEY));
        if (!due) {
            due = this.scheduleNext();
        }
        const wait = Math.max(due - Date.now(), between(...ARRIVAL_SECONDS) * 1000);
        // setTimeout can't wait longer than ~24.8 days; check again when the page is opened next.
        if (wait < 2 ** 31) this.timer = setTimeout(() => this.arrive(), wait);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.cat?.remove();
        this.cat = null;
    }

    arrive() {
        if (document.hidden) {
            this.timer = setTimeout(() => this.arrive(), 5000);
            return;
        }

        this.tapsLeft = 1 + Math.floor(Math.random() * this.maxTapsValue);
        const cat = document.createElement('button');
        cat.type = 'button';
        cat.className = 'cat walking';
        cat.setAttribute('aria-label', 'A cat sat down here. Tap it until it leaves.');
        cat.innerHTML = '<span class="cat-body" aria-hidden="true">🐈</span>';
        cat.addEventListener('click', () => this.tap());
        this.element.append(cat);
        this.cat = cat;

        // In from the right edge, to a random spot (the emoji faces left).
        const x = between(0.1, 0.8) * (window.innerWidth - SIZE);
        const y = between(0.3, 0.8) * (window.innerHeight - SIZE);
        const walk = cat.animate(
            [{ transform: `translate(${window.innerWidth}px, ${y}px)` }, { transform: `translate(${x}px, ${y}px)` }],
            { duration: (window.innerWidth - x) / WALK_PX_PER_SECOND * 1000, fill: 'forwards' },
        );
        walk.onfinish = () => cat.classList.replace('walking', 'sitting');
        this.position = { x, y };
    }

    tap() {
        if (!this.cat || this.cat.classList.contains('leaving')) return;

        this.tapsLeft -= 1;
        if (this.tapsLeft > 0) {
            this.say(SOUNDS[Math.floor(Math.random() * SOUNDS.length)]);
            this.cat.animate([{ rotate: '0deg' }, { rotate: '-12deg' }, { rotate: '10deg' }, { rotate: '0deg' }], { duration: 300 });
            return;
        }

        // Off to the left, fast.
        const cat = this.cat;
        this.cat = null;
        this.scheduleNext();
        cat.getAnimations().forEach((a) => a.cancel());
        cat.className = 'cat leaving';
        const { x, y } = this.position;
        cat.animate(
            [{ transform: `translate(${x}px, ${y}px)` }, { transform: `translate(${-SIZE * 2}px, ${y}px)` }],
            { duration: (x + SIZE * 2) / (WALK_PX_PER_SECOND * 5) * 1000, fill: 'forwards' },
        ).onfinish = () => cat.remove();
    }

    say(text) {
        const bubble = document.createElement('span');
        bubble.className = 'cat-says';
        bubble.textContent = text;
        bubble.style.left = `${this.position.x + SIZE / 2}px`;
        bubble.style.top = `${this.position.y}px`;
        this.element.append(bubble);
        bubble.animate([{ opacity: 1, translate: '-50% 0' }, { opacity: 0, translate: '-50% -40px' }], { duration: 1200 })
            .onfinish = () => bubble.remove();
    }

    scheduleNext() {
        const due = Date.now() + between(this.minHoursValue, this.maxHoursValue) * 3600 * 1000;
        localStorage.setItem(NEXT_VISIT_KEY, String(due));

        return due;
    }
}
