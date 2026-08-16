<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { usePdfViewer } from '@/composables/usePdfViewer';
import { Button } from '@/components/ui/button';

const props = defineProps<{ documentId: number; versionId?: number | null }>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const { numPages, pageNum, loading, load, renderPage } = usePdfViewer();

onMounted(async () => {
    if (!props.documentId) {
        console.error('PdfPreview: documentId is missing!');
        return;
    }
    const url = props.versionId
        ? `/documents/${props.documentId}/raw?version=${props.versionId}`
        : `/documents/${props.documentId}/raw`;
    await load(url);
    render();
});

async function render() {
    if (canvasRef.value) {
        await renderPage(canvasRef.value, pageNum.value, 1.2);
    }
}

watch(pageNum, render);
</script>

<template>
    <div class="flex flex-col items-center gap-2 p-4">
        <p v-if="loading" class="text-sm text-muted-foreground">Loading preview…</p>
        <canvas ref="canvasRef" class="max-w-full rounded border shadow-sm" />
        <div v-if="numPages > 1" class="flex items-center gap-2">
            <Button size="sm" variant="outline" :disabled="pageNum <= 1" @click="pageNum--">
                Prev
            </Button>
            <span class="text-sm">Page {{ pageNum }} of {{ numPages }}</span>
            <Button size="sm" variant="outline" :disabled="pageNum >= numPages" @click="pageNum++">
                Next
            </Button>
        </div>
    </div>
</template>