import { shallowRef, ref } from 'vue';

export function usePdfViewer() {
    const pdfDoc = shallowRef<any>(null);   // ← was ref
    const numPages = ref(1);
    const pageNum = ref(1);
    const loading = ref(false);

    async function load(url: string) {
        loading.value = true;

        const pdfjsLib = await import('pdfjs-dist');
        const workerUrl = (await import('pdfjs-dist/build/pdf.worker.min.mjs?url')).default;
        pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

        const loadingTask = pdfjsLib.getDocument({ url });
        pdfDoc.value = await loadingTask.promise;
        numPages.value = pdfDoc.value.numPages;
        loading.value = false;
    }

    async function renderPage(canvas: HTMLCanvasElement, num: number, scale = 1.2) {
        const pdfjsLib = await import('pdfjs-dist');
        const page = await pdfDoc.value.getPage(num);
        const viewport = page.getViewport({ scale });

        canvas.width = viewport.width;
        canvas.height = viewport.height;

        await page.render({ canvasContext: canvas.getContext('2d')!, viewport }).promise;
        return viewport;
    }

    return { pdfDoc, numPages, pageNum, loading, load, renderPage };
}