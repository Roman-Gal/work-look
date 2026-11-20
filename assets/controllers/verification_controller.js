import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['code'];
    connect() {
        const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
        if (token && /^[a-f0-9]{64}$/.test(token)) {
            this.codeTarget.value = token;
            // Remove it from the address bar and the current history entry before further navigation.
            window.history.replaceState(null, '', window.location.pathname);
        }
    }
}
