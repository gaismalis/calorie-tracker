import { Controller } from '@hotwired/stimulus';

// "📷 Photo" in the add-meal form: shows a preview, makes the text optional (it becomes "details the photo
// can't show"), and shrinks the photo in the browser before upload (long side MAX_SIDE px, JPEG), so it
// uploads quickly on mobile data. If shrinking isn't possible the original is sent; the server checks it.
const MAX_SIDE = 1600;
const QUALITY = 0.85;

export default class extends Controller {
    static targets = ['input', 'preview', 'image', 'text', 'submit'];
    static values = { placeholder: String, photoPlaceholder: String };

    async choose() {
        const file = this.inputTarget.files[0];
        if (!file) return this.clear();

        this.submitTarget.disabled = true;
        const smaller = await this.shrink(file);
        if (smaller !== file) {
            const transfer = new DataTransfer();
            transfer.items.add(smaller);
            this.inputTarget.files = transfer.files;
        }
        this.submitTarget.disabled = false;

        if (this.imageTarget.src) URL.revokeObjectURL(this.imageTarget.src);
        this.imageTarget.src = URL.createObjectURL(smaller);
        this.previewTarget.hidden = false;
        this.textTarget.required = false;
        this.textTarget.placeholder = this.photoPlaceholderValue;
    }

    clear() {
        this.inputTarget.value = '';
        if (this.imageTarget.src) URL.revokeObjectURL(this.imageTarget.src);
        this.imageTarget.removeAttribute('src');
        this.previewTarget.hidden = true;
        this.textTarget.required = true;
        this.textTarget.placeholder = this.placeholderValue;
    }

    async shrink(file) {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
            return blob && blob.size < file.size ? new File([blob], 'meal.jpg', { type: 'image/jpeg' }) : file;
        } catch {
            return file; // e.g. a format this browser can't decode: send it as it is
        }
    }
}
