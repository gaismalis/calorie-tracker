import { Controller } from '@hotwired/stimulus';

// Just for fun: now and then a cat walks onto the screen, waves, and sits on top of things. Tap it a random
// number of times (1 to maxTaps): it pulls a grumpy or smug face each time, and at the last tap it's happy
// and walks off. Rarely (1 in ZOMBIE_CHANCE) it's a zombie cat. The next visit is due minHours–maxHours
// after the last one, kept in localStorage so page loads don't reset it; once due, the cat shows up a few
// seconds into the next page. Right after logging in (soon = true) it comes within SOON_SECONDS whatever the
// schedule. A cat that wasn't shooed comes back on the next page. Skipped for "reduce motion".
//
// Frames: public/cat/cat-sprite.png, one row of 111x128 cells made from the CC0 "Orange Fat Cat"
// (megupets.com) with bin/build-cat-sprite.php.
const SHEET = '/cat/cat-sprite.png';
const CELL_WIDTH = 111;
const CELL_HEIGHT = 128;
const CELLS = 36;
const SCALE = 0.75; // shown 83x96 px; the sheet is twice that for sharp phone screens
const WIDTH = CELL_WIDTH * SCALE;
const HEIGHT = CELL_HEIGHT * SCALE;
const FRAME = { walk: 0, walkFrames: 19, idle: 19, blink: 20, wave: 21, waveFrames: 10, grin: 31, grumpy: 32, tsk: 33, happy: 34, zombie: 35 };

const NEXT_VISIT_KEY = 'cat.nextVisit';
const ARRIVAL_SECONDS = [3, 12];
const SOON_SECONDS = [1, 5];
const WALK_PX_PER_SECOND = 90;
const ZOMBIE_CHANCE = 20;
const SOUNDS = ['meow', 'mrrp?', 'purrr', 'no.', '*yawn*', 'hmm?', 'mew', '5 more minutes', 'hiss'];
const ZOMBIE_SOUNDS = ['braaains', 'mrrrgh', 'brains?', 'uuurgh'];

const between = (min, max) => min + Math.random() * (max - min);
const pick = (list) => list[Math.floor(Math.random() * list.length)];

export default class extends Controller {
    static values = { minHours: Number, maxHours: Number, maxTaps: Number, soon: Boolean };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        let due = Number(localStorage.getItem(NEXT_VISIT_KEY));
        if (!due) {
            due = this.scheduleNext();
        }
        const soon = this.soonValue;
        this.element.removeAttribute('data-cat-soon-value'); // not again when Turbo restores this page from its cache
        const wait = soon
            ? between(...SOON_SECONDS) * 1000
            : Math.max(due - Date.now(), between(...ARRIVAL_SECONDS) * 1000);
        // setTimeout can't wait longer than ~24.8 days; check again when the page is opened next.
        if (wait < 2 ** 31) this.timer = setTimeout(() => this.arrive(), wait);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.stop();
        this.cat?.remove();
        this.cat = null;
    }

    async arrive() {
        if (document.hidden) {
            this.timer = setTimeout(() => this.arrive(), 5000);
            return;
        }
        const sheet = new Image();
        sheet.src = SHEET;
        try {
            await sheet.decode();
        } catch {
            return; // no picture, no cat
        }
        if (!this.element.isConnected) return;

        this.tapsLeft = 1 + Math.floor(Math.random() * this.maxTapsValue);
        this.zombie = Math.random() * ZOMBIE_CHANCE < 1;

        const cat = document.createElement('button');
        cat.type = 'button';
        cat.className = 'cat' + (this.zombie ? ' zombie' : '');
        cat.style.width = `${WIDTH}px`;
        cat.style.height = `${HEIGHT}px`;
        cat.setAttribute('aria-label', 'A cat sat down here. Tap it until it leaves.');
        this.sprite = document.createElement('span');
        this.sprite.className = 'cat-sprite';
        this.sprite.setAttribute('aria-hidden', 'true');
        this.sprite.style.backgroundImage = `url(${SHEET})`;
        this.sprite.style.backgroundSize = `${CELLS * WIDTH}px ${HEIGHT}px`;
        cat.append(this.sprite);
        cat.addEventListener('click', () => this.tap());
        this.element.append(cat);
        this.cat = cat;

        // Walk in from the nearer edge to a random spot.
        const x = between(0.1, 0.8) * (window.innerWidth - WIDTH);
        const y = between(0.3, 0.8) * (window.innerHeight - HEIGHT);
        const fromX = x < (window.innerWidth - WIDTH) / 2 ? -WIDTH : window.innerWidth;
        this.position = { x, y };
        this.state = 'walking';
        this.walk(fromX, x, WALK_PX_PER_SECOND, () => {
            this.face(true);
            this.state = 'waving';
            this.loop(FRAME.wave, FRAME.waveFrames, 10, 2, () => this.sit());
        });
    }

    tap() {
        if (!this.cat || !['sitting', 'reacting'].includes(this.state)) return;

        this.tapsLeft -= 1;
        this.stop();
        if (this.tapsLeft > 0) {
            this.state = 'reacting';
            this.show(pick([FRAME.grumpy, FRAME.tsk]));
            this.say(pick(this.zombie ? ZOMBIE_SOUNDS : SOUNDS));
            this.cat.animate([{ rotate: '0deg' }, { rotate: '-8deg' }, { rotate: '6deg' }, { rotate: '0deg' }], { duration: 300 });
            this.later(() => this.sit(), 900);
            return;
        }

        // Happy to have been noticed, then off to the nearer edge, faster.
        this.state = 'leaving';
        this.scheduleNext();
        this.show(FRAME.happy);
        this.later(() => {
            const { x } = this.position;
            const toX = x + WIDTH / 2 < window.innerWidth / 2 ? -WIDTH * 2 : window.innerWidth + WIDTH;
            const cat = this.cat;
            this.walk(x, toX, WALK_PX_PER_SECOND * 3, () => {
                cat.remove();
                if (this.cat === cat) this.cat = null;
            });
        }, 600);
    }

    sit() {
        this.state = 'sitting';
        this.show(FRAME.idle);
        // Blink now and then.
        this.later(() => {
            this.show(FRAME.blink);
            this.later(() => this.sit(), 160);
        }, between(2000, 5000));
    }

    walk(fromX, toX, pxPerSecond, done) {
        const { y } = this.position;
        this.face(toX >= fromX);
        this.cat.classList.add('walking');
        this.loop(FRAME.walk, FRAME.walkFrames, Math.min(40, pxPerSecond / 4), Infinity);
        this.cat.getAnimations().forEach((a) => a.cancel());
        this.cat.animate(
            [{ transform: `translate(${fromX}px, ${y}px)` }, { transform: `translate(${toX}px, ${y}px)` }],
            { duration: Math.abs(toX - fromX) / pxPerSecond * 1000, fill: 'forwards' },
        ).onfinish = () => {
            this.cat?.classList.remove('walking');
            this.stop();
            done();
        };
    }

    /** Plays frames first…first+count-1 at fps, `times` times over, then calls done. */
    loop(first, count, fps, times, done = () => {}) {
        clearInterval(this.ticker);
        let step = 0;
        this.show(first);
        this.ticker = setInterval(() => {
            step += 1;
            if (step >= count * times) {
                clearInterval(this.ticker);
                done();
                return;
            }
            this.show(first + (step % count));
        }, 1000 / fps);
    }

    show(frame) {
        // The zombie has one picture for everything; CSS makes it shamble.
        const cell = this.zombie ? FRAME.zombie : frame;
        this.sprite.style.backgroundPosition = `${-cell * WIDTH}px 0`;
    }

    /** The picture faces right; flipped when walking left. */
    face(right) {
        this.sprite.classList.toggle('facing-left', !right);
    }

    later(callback, ms) {
        clearTimeout(this.poseTimer);
        this.poseTimer = setTimeout(callback, ms);
    }

    stop() {
        clearInterval(this.ticker);
        clearTimeout(this.poseTimer);
    }

    say(text) {
        const bubble = document.createElement('span');
        bubble.className = 'cat-says';
        bubble.textContent = text;
        bubble.style.left = `${this.position.x + WIDTH / 2}px`;
        bubble.style.top = `${this.position.y - 8}px`;
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
