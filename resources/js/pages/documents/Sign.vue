<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { usePdfViewer } from '@/composables/usePdfViewer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = defineProps<{ document: { id: number; original_filename: string } }>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const overlayRef = ref<HTMLDivElement | null>(null);
const { numPages, pageNum, load, renderPage } = usePdfViewer();

const signatureFile = ref<File | null>(null);
const signaturePreviewUrl = ref('');
const sigPos = ref({ x: 100, y: 100, width: 160, height: 60 });
const dragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const renderScale = 1.4;
const submitting = ref(false);

onMounted(async () => {
    await load(`/documents/${props.document.id}/raw`);
    render();
});

async function render() {
    if (canvasRef.value) {
        const viewport = await renderPage(canvasRef.value, pageNum.value, renderScale);
        if (overlayRef.value) {
            overlayRef.value.style.width = `${viewport.width}px`;
            overlayRef.value.style.height = `${viewport.height}px`;
        }
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
    if (!signatureFile.value || submitting.value) return;
    submitting.value = true;

    const form = new FormData();
    form.append('signature', signatureFile.value);
    form.append('page', String(pageNum.value));
    form.append('x', String(Math.round(sigPos.value.x)));
    form.append('y', String(Math.round(sigPos.value.y)));
    form.append('width', String(Math.round(sigPos.value.width)));
    form.append('height', String(Math.round(sigPos.value.height)));
    form.append('render_scale', String(renderScale));

    try {
        const { data } = await axios.post(`/documents/${props.document.id}/sign`, form);
        window.location.href = data.redirect;
    } catch (err) {
        submitting.value = false;
        alert('Could not apply signature. Please try again.');
    }
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-6">
        <h1 class="text-xl font-semibold">Sign: {{ document.original_filename }}</h1>

        <div class="flex items-center gap-3">
            <Input type="file" accept="image/png" @change="onSignatureChosen" class="max-w-xs" />
            <span class="text-sm text-muted-foreground">Upload your signature (PNG, transparent background preferred)</span>
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

        <div v-if="numPages > 1" class="flex items-center gap-2">
            <Button variant="outline" size="sm" :disabled="pageNum <= 1" @click="pageNum--; render()">Prev</Button>
            <span class="text-sm">Page {{ pageNum }} of {{ numPages }}</span>
            <Button variant="outline" size="sm" :disabled="pageNum >= numPages" @click="pageNum++; render()">Next</Button>
        </div>

        <Button :disabled="!signatureFile || submitting" @click="applySignature">
            {{ submitting ? 'Applying…' : 'Apply signature' }}
        </Button>
    </div>
</template>