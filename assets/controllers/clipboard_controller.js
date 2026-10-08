import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'label'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        this.labelTarget.textContent = this.labelTarget.dataset.done;
    }
}
