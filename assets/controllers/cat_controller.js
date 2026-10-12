import { Controller } from '@hotwired/stimulus';

// Just for fun: when you open the app, a cat walks onto the screen within a few seconds, waves, and sits on
// top of things. Tap it a random number of times (1 to maxTaps): it pulls a grumpy or smug face each time,
// and at the last tap it's happy and walks off. Ignore it and it gets bored: now and then it pulls a face,
// meows and walks to another spot. Rarely (1 in ZOMBIE_CHANCE) it's a zombie cat.
//
// It comes at most once per gapMinutes: after it has been shooed away, the next visit is due gapMinutes
// later, kept in localStorage so page loads don't reset it. A cat that wasn't shooed comes back on the next
// page. Skipped for "reduce motion".
//
// The 🐾 button (cat_call_controller.js) calls it: if the cat left at least callCooldownMinutes ago it
// answers ("Lasagna?") and comes; called too soon, it answers with an excuse and stays away.
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
const ARRIVAL_SECONDS = [1, 5];
/** Sitting this long without a tap and the cat gets bored. */
const BORED_SECONDS = [6, 14];
const WALK_PX_PER_SECOND = 90;
const ZOMBIE_CHANCE = 20;
const SOUNDS = ['meow', 'mrrp?', 'purrr', 'no.', '*yawn*', 'hmm?', 'mew', '5 more minutes', 'hiss'];
const BORED_SOUNDS = ['meow!', 'MEOW', 'hello?', 'pet me', 'mrrraow', 'feed me', 'hey!', 'I see you'];
const ZOMBIE_SOUNDS = ['braaains', 'mrrrgh', 'brains?', 'uuurgh'];
const LAST_LEFT_KEY = 'cat.lastLeft';
const COMING = ['alright', 'Lasagna?', 'coming!', 'fine.', 'you rang?', 'is it dinner?', 'on my way', 'mrrp!', 'did someone say tuna?'];
const BUSY = ["I'm busy", "I'm cleaning myself", 'what?', 'not now', 'in a meeting', '*ignores you*', "I'm napping",
    'busy knocking things off the table', "can't, I'm a loaf", 'ask me later', 'staring at a wall, brb'];
const HERE = ["I'm right here!", 'look down', 'hello??', "I'm literally here"];

const between = (min, max) => min + Math.random() * (max - min);
const pick = (list) => list[Math.floor(Math.random() * list.length)];

export default class extends Controller {
    static values = { gapMinutes: Number, maxTaps: Number, callCooldownMinutes: Number };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const due = Number(localStorage.getItem(NEXT_VISIT_KEY)) || 0;
        const wait = Math.max(due - Date.now(), between(...ARRIVAL_SECONDS) * 1000);
        // setTimeout can't wait longer than ~24.8 days; check again when the page is opened next.
        if (wait < 2 ** 31) this.timer = setTimeout(() => this.arrive(), wait);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.stop();
        this.cat?.remove();
        this.cat = null;
    }

    /** The 🐾 button: come if the cooldown is over, otherwise say why not. */
    call(event) {
        const button = event.detail?.button;
        if (this.cat || this.arriving) return this.answer(pick(HERE), button);

        const lastLeft = Number(localStorage.getItem(LAST_LEFT_KEY)) || 0;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion || Date.now() - lastLeft < this.callCooldownMinutesValue * 60 * 1000) {
            return this.answer(pick(BUSY), button);
        }
        this.answer(pick(COMING), button);
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.arrive(), 800);
    }

    async arrive() {
        if (document.hidden) {
            this.timer = setTimeout(() => this.arrive(), 5000);
            return;
        }
        if (this.cat || this.arriving) return;
        this.arriving = true;
        const sheet = new Image();
        sheet.src = SHEET;
        try {
            await sheet.decode();
        } catch {
            return; // no picture, no cat
        } finally {
            this.arriving = false;
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
        this.state = 'arriving';

        // Walk in from the nearer edge to a random spot.
        const spot = this.randomSpot();
        const from = { x: spot.x < (window.innerWidth - WIDTH) / 2 ? -WIDTH : window.innerWidth, y: spot.y };
        this.walk(from, spot, WALK_PX_PER_SECOND, () => {
            this.face(true);
            this.state = 'waving';
            this.loop(FRAME.wave, FRAME.waveFrames, 10, 2, () => this.sit());
        });
    }

    tap() {
        if (!this.cat || !['sitting', 'reacting', 'wandering'].includes(this.state)) return;

        this.stop();
        if ('wandering' === this.state) this.halt();
        this.tapsLeft -= 1;
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
        localStorage.setItem(LAST_LEFT_KEY, String(Date.now()));
        this.show(FRAME.happy);
        this.later(() => {
            const { x, y } = this.position;
            const to = { x: x + WIDTH / 2 < window.innerWidth / 2 ? -WIDTH * 2 : window.innerWidth + WIDTH, y };
            const cat = this.cat;
            this.walk(this.position, to, WALK_PX_PER_SECOND * 3, () => {
                cat.remove();
                if (this.cat === cat) this.cat = null;
            });
        }, 600);
    }

    sit() {
        this.state = 'sitting';
        this.blink();
        clearTimeout(this.boredTimer);
        this.boredTimer = setTimeout(() => this.bored(), between(...BORED_SECONDS) * 1000);
    }

    blink() {
        this.show(FRAME.idle);
        this.later(() => {
            this.show(FRAME.blink);
            this.later(() => this.blink(), 160);
        }, between(2000, 5000));
    }

    /** Ignored: pull a face and meow, then (usually) walk somewhere else and sit down again. */
    bored() {
        this.stop();
        this.state = 'reacting';
        this.show(pick([FRAME.grin, FRAME.grumpy, FRAME.tsk]));
        this.say(pick(this.zombie ? ZOMBIE_SOUNDS : BORED_SOUNDS));
        this.later(() => {
            if (Math.random() < 0.25) return this.sit();
            this.state = 'wandering';
            this.walk(this.position, this.randomSpot(), WALK_PX_PER_SECOND, () => this.sit());
        }, 1200);
    }

    walk(from, to, pxPerSecond, done) {
        this.position = to;
        this.face(to.x >= from.x);
        this.cat.classList.add('walking');
        this.loop(FRAME.walk, FRAME.walkFrames, Math.min(40, pxPerSecond / 4), Infinity);
        this.cat.getAnimations().forEach((a) => a.cancel());
        this.walking = this.cat.animate(
            [{ transform: `translate(${from.x}px, ${from.y}px)` }, { transform: `translate(${to.x}px, ${to.y}px)` }],
            { duration: Math.hypot(to.x - from.x, to.y - from.y) / pxPerSecond * 1000, fill: 'forwards' },
        );
        this.walking.onfinish = () => {
            this.walking = null;
            this.cat?.classList.remove('walking');
            this.stop();
            done();
        };
    }

    /** Stops a walk where the cat is right now (tapped on the way). */
    halt() {
        if (!this.walking) return;
        const box = this.cat.getBoundingClientRect();
        const layer = this.element.getBoundingClientRect();
        this.position = { x: box.left - layer.left, y: box.top - layer.top };
        this.walking.onfinish = null;
        this.walking = null;
        this.cat.getAnimations().forEach((a) => a.cancel());
        this.cat.style.transform = `translate(${this.position.x}px, ${this.position.y}px)`;
        this.cat.classList.remove('walking');
    }

    randomSpot() {
        return { x: between(0.1, 0.8) * (window.innerWidth - WIDTH), y: between(0.3, 0.8) * (window.innerHeight - HEIGHT) };
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
        clearTimeout(this.boredTimer);
    }

    say(text) {
        this.bubble(text, this.position.x + WIDTH / 2, this.position.y - 8, '-50%', 1200);
    }

    /** The cat's answer to the 🐾 button, next to the button (right-aligned with it). */
    answer(text, button) {
        const box = button?.getBoundingClientRect();
        const left = box ? box.right : window.innerWidth - 16;
        const top = box ? box.top - 34 : window.innerHeight - 140;
        this.bubble(text, left, top, '-100%', 2200);
    }

    bubble(text, left, top, shiftX, duration) {
        const bubble = document.createElement('span');
        bubble.className = 'cat-says';
        bubble.textContent = text;
        bubble.style.left = `${left}px`;
        bubble.style.top = `${top}px`;
        this.element.append(bubble);
        bubble.animate(
            [{ opacity: 1, translate: `${shiftX} 0` }, { opacity: 1, translate: `${shiftX} -10px`, offset: 0.6 }, { opacity: 0, translate: `${shiftX} -40px` }],
            { duration },
        ).onfinish = () => bubble.remove();
    }

    scheduleNext() {
        localStorage.setItem(NEXT_VISIT_KEY, String(Date.now() + this.gapMinutesValue * 60 * 1000));
    }
}
