import { Controller } from '@hotwired/stimulus';

/* Multi-selection of documents for a grouped ZIP download. */
export default class extends Controller {
    static targets = ['checkbox', 'count', 'bar', 'all'];

    connect() {
        this.update();
    }

    toggleAll() {
        const checked = this.allTarget.checked;
        this.checkboxTargets.forEach((box) => { box.checked = checked; });
        this.update();
    }

    update() {
        const selected = this.checkboxTargets.filter((box) => box.checked).length;
        this.countTarget.textContent = String(selected);
        this.barTarget.hidden = selected === 0;
        if (this.hasAllTarget) {
            this.allTarget.checked = selected > 0 && selected === this.checkboxTargets.length;
        }
    }
}
