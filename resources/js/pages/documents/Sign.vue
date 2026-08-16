<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { usePdfViewer } from '@/composables/usePdfViewer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    document: { id: number; original_filename: string; routing_case_id: number | null };
}>();

const backHref = computed(() =>
    props.document.routing_case_id ? `/routing/${props.document.routing_case_id}` : `/documents/${props.document.id}`,
);

const canvasRef = ref<HTMLCanvasElement | null>(null);
const overlayRef = ref<HTMLDivElement | null>(null);
const { numPages, pageNum, load, renderPage } = usePdfViewer();

const signatureFile = ref<File | null>(null);
const signaturePreviewUrl = ref('');
const sigPos = ref({ x: 100, y: 100, width: 160, height: 60 });
const sigAspectRatio = ref(160 / 60);
const dragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const resizing = ref(false);
const resizeStart = ref({ mouseX: 0, width: 0 });
const renderScale = 1.4;
const submitting = ref(false);
const MIN_SIGNATURE_WIDTH = 40;

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
    if (!file) return;

    signatureFile.value = file;
    signaturePreviewUrl.value = URL.createObjectURL(file);

    const img = new Image();
    img.onload = () => {
        sigAspectRatio.value = img.naturalWidth / img.naturalHeight;
        sigPos.value.height = Math.round(sigPos.value.width / sigAspectRatio.value);
    };
    img.src = signaturePreviewUrl.value;
}

function startDrag(e: MouseEvent) {
    dragging.value = true;
    dragOffset.value = { x: e.offsetX, y: e.offsetY };
}

function startResize(e: MouseEvent) {
    resizing.value = true;
    resizeStart.value = { mouseX: e.clientX, width: sigPos.value.width };
}

function onDrag(e: MouseEvent) {
    if (resizing.value) {
        const newWidth = Math.max(MIN_SIGNATURE_WIDTH, resizeStart.value.width + (e.clientX - resizeStart.value.mouseX));
        sigPos.value.width = newWidth;
        sigPos.value.height = Math.round(newWidth / sigAspectRatio.value);
        return;
    }

    if (!dragging.value || !overlayRef.value) return;
    const rect = overlayRef.value.getBoundingClientRect();
    sigPos.value.x = e.clientX - rect.left - dragOffset.value.x;
    sigPos.value.y = e.clientY - rect.top - dragOffset.value.y;
}

function stopDrag() {
    dragging.value = false;
    resizing.value = false;
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
        <Link :href="backHref" class="flex w-fit items-center gap-1 text-sm text-muted-foreground underline underline-offset-4">
            <ArrowLeft class="h-3.5 w-3.5" />
            Back
        </Link>

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
                <div
                    v-if="signaturePreviewUrl"
                    class="pointer-events-auto absolute cursor-move select-none"
                    :style="{
                        left: `${sigPos.x}px`,
                        top: `${sigPos.y}px`,
                        width: `${sigPos.width}px`,
                        height: `${sigPos.height}px`,
                    }"
                    @mousedown="startDrag"
                >
                    <img
                        :src="signaturePreviewUrl"
                        class="h-full w-full select-none"
                        draggable="false"
                    />
                    <div
                        class="absolute -bottom-1.5 -right-1.5 h-3.5 w-3.5 cursor-nwse-resize rounded-sm border border-background bg-primary"
                        @mousedown.stop="startResize"
                    />
                </div>
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