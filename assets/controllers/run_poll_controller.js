import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static values = { url: String };
    connect() {
        this.onFrameLoad = () => {
            if (!this.element.querySelector('[data-run-active="1"]')) window.clearInterval(this.timer);
        };
        this.element.addEventListener('turbo:frame-load', this.onFrameLoad);
        this.timer = window.setInterval(() => {
            if (document.hidden || this.element.hasAttribute('busy')) return;
            if (this.element.src === this.urlValue) this.element.reload();
            else this.element.src = this.urlValue;
        }, 3500);
    }
    disconnect() {
        window.clearInterval(this.timer);
        this.element.removeEventListener('turbo:frame-load', this.onFrameLoad);
    }
}
