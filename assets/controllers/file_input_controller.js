import { Controller } from '@hotwired/stimulus';

/* Shows the chosen file name and size next to a styled file input. */
export default class extends Controller {
    static targets = ['input', 'name'];

    change() {
        const file = this.inputTarget.files[0];
        if (!file) {
            this.nameTarget.textContent = this.nameTarget.dataset.empty;
            return;
        }
        const size = file.size >= 1048576 ? `${(file.size / 1048576).toFixed(1)} Mo` : `${Math.ceil(file.size / 1024)} Ko`;
        this.nameTarget.textContent = `${file.name} · ${size}`;
    }
}
