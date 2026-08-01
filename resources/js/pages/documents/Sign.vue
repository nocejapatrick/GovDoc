<script setup lang="ts">
import { onMounted, ref } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfjsWorker;

const props = defineProps<{ document: { id: number; original_filename: string } }>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const overlayRef = ref<HTMLDivElement | null>(null);
const pageNum = ref(1);
const numPages = ref(1);
const pdfDoc = ref<any>(null);

// Signature state
const signatureFile = ref<File | null>(null);
const signaturePreviewUrl = ref('');
const sigPos = ref({ x: 100, y: 100, width: 160, height: 60 });
const dragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });

onMounted(async () => {
    const loadingTask = pdfjsLib.getDocument(`/documents/${props.document.id}/raw`);
    pdfDoc.value = await loadingTask.promise;
    numPages.value = pdfDoc.value.numPages;
    renderPage(1);
});

async function renderPage(num: number) {
    const page = await pdfDoc.value.getPage(num);
    const viewport = page.getViewport({ scale: 1.4 });

    const canvas = canvasRef.value!;
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: canvas.getContext('2d')!, viewport }).promise;

    if (overlayRef.value) {
        overlayRef.value.style.width = `${viewport.width}px`;
        overlayRef.value.style.height = `${viewport.height}px`;
    }
}

function onSignatureChosen(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        signatureFile.value = file;
        signaturePreviewUrl.value = URL.createObjectURL(file);
    }
}

function startDrag(e: MouseEvent) {
    dragging.value = true;
    dragOffset.value = { x: e.offsetX, y: e.offsetY };
}

function onDrag(e: MouseEvent) {
    if (!dragging.value || !overlayRef.value) return;
    const rect = overlayRef.value.getBoundingClientRect();
    sigPos.value.x = e.clientX - rect.left - dragOffset.value.x;
    sigPos.value.y = e.clientY - rect.top - dragOffset.value.y;
}

function stopDrag() {
    dragging.value = false;
}

async function applySignature() {
    if (!signatureFile.value) return;

    const form = new FormData();
    form.append('signature', signatureFile.value);
    form.append('page', String(pageNum.value));
    form.append('x', String(Math.round(sigPos.value.x)));
    form.append('y', String(Math.round(sigPos.value.y)));
    form.append('width', String(Math.round(sigPos.value.width)));
    form.append('height', String(Math.round(sigPos.value.height)));
    // The canvas render scale (1.4) must be sent too, so the backend
    // can convert screen pixels back to real PDF points.
    form.append('render_scale', '1.4');

    await axios.post(`/documents/${props.document.id}/sign`, form);

    window.location.href = `/documents/${props.document.id}/routing`;
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-6">
        <h1 class="text-xl font-semibold">Sign: {{ document.original_filename }}</h1>

        <div class="flex items-center gap-3">
            <Input type="file" accept="image/png" @change="onSignatureChosen" class="max-w-xs" />
            <span class="text-sm text-muted-foreground">Upload a signature image (PNG, transparent background works best)</span>
        </div>

        <div
            class="relative w-fit border shadow-sm"
            @mousemove="onDrag"
            @mouseup="stopDrag"
            @mouseleave="stopDrag"
        >
            <canvas ref="canvasRef" />
            <div ref="overlayRef" class="pointer-events-none absolute left-0 top-0">
                <img
                    v-if="signaturePreviewUrl"
                    :src="signaturePreviewUrl"
                    class="pointer-events-auto absolute cursor-move select-none"
                    :style="{
                        left: `${sigPos.x}px`,
                        top: `${sigPos.y}px`,
                        width: `${sigPos.width}px`,
                        height: `${sigPos.height}px`,
                    }"
                    @mousedown="startDrag"
                    draggable="false"
                />
            </div>
        </div>

        <div class="flex items-center gap-2">
            <Button variant="outline" :disabled="pageNum <= 1" @click="pageNum--; renderPage(pageNum)">Prev page</Button>
            <span class="text-sm">Page {{ pageNum }} of {{ numPages }}</span>
            <Button variant="outline" :disabled="pageNum >= numPages" @click="pageNum++; renderPage(pageNum)">Next page</Button>
        </div>

        <Button :disabled="!signatureFile" @click="applySignature">Apply signature & finalize</Button>
    </div>
</template>