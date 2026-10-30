import { Controller } from '@hotwired/stimulus';

// 🎤 in the meal dialog: record what you ate, send it to /meals/voice, show "We got this:" with the foods heard.
// "Log it" puts them in the meal's text box and logs the meal as if typed; "Fix it" puts them there to edit first.
//
// Recorded as 16 kHz mono WAV, made here from the microphone's samples: every browser can do that (Safari's own
// recordings are MP4, which Gemini doesn't take), and a minute is under 2 MB.
const SAMPLE_RATE = 16000;
const MAX_SECONDS = 60;

export default class extends Controller {
    static targets = ['start', 'recording', 'timer', 'working', 'result', 'foods', 'error'];
    static values = { url: String, token: String };

    connect() {
        // Needs a microphone API (and https, or localhost).
        if (!navigator.mediaDevices?.getUserMedia || !(window.AudioContext || window.webkitAudioContext)) {
            this.element.hidden = true;
        }
    }

    disconnect() {
        this.release();
    }

    async start() {
        this.show('recording');
        this.chunks = [];
        // this.audio, not this.context: Stimulus controllers use `context` themselves.
        // Made right away, in the tap itself: browsers (Safari above all) keep audio made later, after the
        // microphone question, paused, and nothing would be recorded.
        this.audio = new (window.AudioContext || window.webkitAudioContext)();
        this.audio.resume();
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
        } catch {
            this.fail('Allow the microphone for this site to log by voice, or type the meal.');
            return;
        }
        if (!this.audio) return; // cancelled while asking for the microphone
        // Kept on the controller: Chrome garbage-collects a source nobody refers to, and the recording goes silent.
        this.source = this.audio.createMediaStreamSource(this.stream);
        // ScriptProcessor is old but works in every browser without a separate worklet file.
        this.processor = this.audio.createScriptProcessor(4096, 1, 1);
        this.processor.onaudioprocess = (event) => this.chunks.push(new Float32Array(event.inputBuffer.getChannelData(0)));
        this.source.connect(this.processor);
        this.processor.connect(this.audio.destination);

        this.startedAt = Date.now();
        this.timerTarget.textContent = '0:00';
        this.ticker = setInterval(() => {
            const seconds = Math.floor((Date.now() - this.startedAt) / 1000);
            this.timerTarget.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
            if (seconds >= MAX_SECONDS) this.stop();
        }, 250);
    }

    async stop() {
        if (!this.audio) return;
        const rate = this.audio.sampleRate;
        const chunks = this.chunks;
        this.release();
        if (chunks.length === 0) return this.fail("Didn't catch anything. Check the microphone and try again.");

        this.show('working');
        const body = new FormData();
        body.append('voice', new Blob([wav(chunks, rate)], { type: 'audio/wav' }), 'voice.wav');
        body.append('_token', this.tokenValue);
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) return this.fail(data.error || 'Something went wrong. Please try again.');
            this.heard = data.text;
            this.foodsTarget.replaceChildren(...data.foods.map((food) => {
                const li = document.createElement('li');
                li.textContent = food;
                return li;
            }));
            this.show('result');
        } catch {
            this.fail('Something went wrong. Please try again or type the meal.');
        }
    }

    cancel() {
        this.release();
        this.show('start');
    }

    again() {
        this.start();
    }

    // "Log it": the foods become the meal's text and the meal is logged as usual (AI estimate, then the review).
    use() {
        const form = this.fillText();
        form.requestSubmit(form.querySelector('button[type=submit]'));
    }

    // "Fix it": the foods go into the text box to edit before logging.
    edit() {
        const text = this.fillText().querySelector('textarea[name=description]');
        this.show('start');
        text.focus();
        text.setSelectionRange(text.value.length, text.value.length);
    }

    fillText() {
        const form = this.element.closest('form');
        const text = form.querySelector('textarea[name=description]');
        text.value = this.heard;
        text.dispatchEvent(new Event('input', { bubbles: true })); // the recipe list filters on it
        return form;
    }

    fail(message) {
        this.release();
        this.errorTarget.textContent = message;
        this.show('start');
        this.errorTarget.hidden = false;
    }

    show(state) {
        this.startTarget.hidden = state !== 'start';
        this.recordingTarget.hidden = state !== 'recording';
        this.workingTarget.hidden = state !== 'working';
        this.resultTarget.hidden = state !== 'result';
        this.errorTarget.hidden = true;
    }

    release() {
        clearInterval(this.ticker);
        this.source?.disconnect();
        this.processor?.disconnect();
        this.stream?.getTracks().forEach((track) => track.stop());
        this.audio?.close();
        this.source = this.processor = this.stream = this.audio = null;
    }
}

// The recorded chunks as a 16-bit mono WAV file at SAMPLE_RATE (averaging down from the microphone's rate).
function wav(chunks, rate) {
    const length = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
    const input = new Float32Array(length);
    let offset = 0;
    for (const chunk of chunks) { input.set(chunk, offset); offset += chunk.length; }

    const ratio = rate / SAMPLE_RATE;
    const samples = Math.floor(input.length / ratio);
    const buffer = new ArrayBuffer(44 + samples * 2);
    const view = new DataView(buffer);
    const text = (at, value) => [...value].forEach((char, i) => view.setUint8(at + i, char.charCodeAt(0)));
    text(0, 'RIFF'); view.setUint32(4, 36 + samples * 2, true); text(8, 'WAVE');
    text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, SAMPLE_RATE, true); view.setUint32(28, SAMPLE_RATE * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    text(36, 'data'); view.setUint32(40, samples * 2, true);
    for (let i = 0; i < samples; i++) {
        const from = Math.floor(i * ratio);
        const to = Math.min(input.length, Math.floor((i + 1) * ratio));
        let sum = 0;
        for (let j = from; j < to; j++) sum += input[j];
        const sample = Math.max(-1, Math.min(1, sum / Math.max(1, to - from)));
        view.setInt16(44 + i * 2, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true);
    }
    return buffer;
}
