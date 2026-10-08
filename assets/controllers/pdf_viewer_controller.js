import { Controller } from '@hotwired/stimulus';

/*
 * Renders a PDF with pdf.js into canvases (pages are rendered lazily when they
 * come into view). Scripts embedded in PDFs are never executed.
 */
export default class extends Controller {
    static values = { url: String, worker: String };
    static targets = ['pages', 'status'];

    async connect() {
        try {
            const pdfjs = await import('pdfjs-dist');
            pdfjs.GlobalWorkerOptions.workerSrc = this.workerValue;
            this.pdf = await pdfjs.getDocument({
                url: this.urlValue,
                isEvalSupported: false,
                enableXfa: false,
                withCredentials: false,
            }).promise;
            this.statusTarget.hidden = true;
            this.observer = new IntersectionObserver((entries) => this.onVisible(entries), { rootMargin: '400px' });
            for (let n = 1; n <= this.pdf.numPages; n++) {
                const canvas = document.createElement('canvas');
                canvas.className = 'pdf-page';
                canvas.dataset.page = String(n);
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', `Page ${n} / ${this.pdf.numPages}`);
                canvas.width = 800;
                canvas.height = 1100;
                this.pagesTarget.appendChild(canvas);
                this.observer.observe(canvas);
            }
        } catch (error) {
            this.statusTarget.hidden = false;
            this.statusTarget.textContent = this.element.dataset.errorMessage || 'Erreur';
            console.error(error);
        }
    }

    disconnect() {
        this.observer?.disconnect();
        this.pdf?.destroy();
    }

    async onVisible(entries) {
        for (const entry of entries) {
            if (!entry.isIntersecting || entry.target.dataset.rendered) {
                continue;
            }
            const canvas = entry.target;
            canvas.dataset.rendered = '1';
            this.observer.unobserve(canvas);
            const page = await this.pdf.getPage(Number(canvas.dataset.page));
            const width = this.pagesTarget.clientWidth || 800;
            const base = page.getViewport({ scale: 1 });
            const ratio = window.devicePixelRatio || 1;
            const viewport = page.getViewport({ scale: (width / base.width) * ratio });
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        }
    }
}
