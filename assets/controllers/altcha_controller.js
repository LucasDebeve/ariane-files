import { Controller } from '@hotwired/stimulus';

/*
 * Loads the ALTCHA widget (CSP-friendly "external" build) and registers the
 * PBKDF2 worker served from our own origin.
 */
export default class extends Controller {
    static values = { worker: String };

    async connect() {
        await import('altcha');
        await import('altcha/i18n/fr-fr');
        const workerUrl = this.workerValue;
        for (const algorithm of ['PBKDF2/SHA-256', 'PBKDF2/SHA-384', 'PBKDF2/SHA-512']) {
            globalThis.$altcha.algorithms.set(algorithm, () => new Worker(workerUrl));
        }
    }
}
