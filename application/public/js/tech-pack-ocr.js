(function () {
    'use strict';

    // Prefer the labelled print-type row; a sheet may mention other techniques
    // in its notes or add-ons. Conflicting/unrecognised text needs a person.
    function detectPrintType(text) {
        const lines = String(text || '').split(/\r?\n/);
        const labelled = lines.findIndex(line => /print\s*(?:type|method|technique)\b/i.test(line));
        const source = labelled >= 0
            ? lines[labelled].replace(/^.*?print\s*(?:type|method|technique)\b[:\s-]*/i, '') + ' ' + (lines[labelled + 1] || '')
            : lines.join(' ');
        const types = [
            ['Full Sublimation', /\b(?:full[\s_-]*)?subli(?:mation|mated)?\b/i],
            ['DTF', /\bdtf\b|direct\s*(?:to|[-])\s*film/i],
            ['Silkscreen', /\bsilk\s*screen\b|\bscreen\s*print(?:ing)?\b/i],
            ['Embroidery', /\bembroider(?:y|ed)\b/i],
            ['DTG', /\bdtg\b|direct\s*to\s*garment/i],
        ].filter(entry => entry[1].test(source));
        return types.length === 1 ? types[0][0] : null;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { detectPrintType };
        return;
    }

    let engine;
    function loadEngine() {
        if (window.Tesseract) return Promise.resolve(window.Tesseract);
        if (!engine) engine = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
            script.onload = () => window.Tesseract ? resolve(window.Tesseract) : reject(new Error('OCR unavailable'));
            script.onerror = reject;
            document.head.appendChild(script);
        }).catch(error => { engine = null; throw error; });
        return engine;
    }

    document.querySelectorAll('[data-tech-pack-ocr]').forEach(panel => {
        const field = panel.querySelector('input[name="print_type"]');
        const read = panel.querySelector('[data-ocr-read]');
        const apply = panel.querySelector('[data-ocr-apply]');
        const status = panel.querySelector('[data-ocr-message]');
        if (!field || !read || !apply || !status) return;
        let suggestion = null;
        let run = 0;
        async function recognize() {
            const token = ++run;
            read.disabled = true;
            apply.hidden = true;
            status.textContent = 'Reading print type from the image…';
            let worker;
            let timer;
            let expired = false;
            const job = (async () => {
                const T = await loadEngine();
                if (expired) throw new Error('Timeout');
                worker = await T.createWorker('eng');
                if (expired) { await worker.terminate(); throw new Error('Timeout'); }
                return worker.recognize(panel.dataset.imageUrl);
            })();
            try {
                const result = await Promise.race([job, new Promise((_, reject) => {
                    timer = setTimeout(() => { expired = true; reject(new Error('Timeout')); }, 60000);
                })]);
                if (token !== run) return;
                suggestion = result.data.confidence >= 60 ? detectPrintType(result.data.text) : null;
                if (suggestion) {
                    status.textContent = 'Detected: ' + suggestion + '. Check it against the image before using it.';
                    apply.textContent = 'Use ' + suggestion;
                    apply.hidden = false;
                } else {
                    status.textContent = 'No clear print type found. Please enter the print type shown in the image.';
                }
            } catch (_) {
                status.textContent = 'Could not read the image. Check your internet connection or enter the print type manually.';
            } finally {
                clearTimeout(timer);
                if (worker) worker.terminate().catch(() => {});
                read.disabled = false;
            }
        }
        read.addEventListener('click', recognize);
        apply.addEventListener('click', () => {
            if (!suggestion) return;
            field.value = suggestion;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            status.textContent = 'Print type selected. Save the Tech Pack to update production routing.';
            apply.hidden = true;
            field.focus();
        });
        if (!field.value.trim() || /^(?:n\/?a|none|—|-)$/i.test(field.value.trim())) recognize();
    });
})();
