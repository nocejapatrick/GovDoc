<script setup lang="ts">
import { useUploads } from '@/composables/useUploads';

const { uploads, remove } = useUploads();
</script>

<template>
    <div
        v-if="uploads.length"
        class="fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-2"
    >
        <div
            v-for="upload in uploads"
            :key="upload.id"
            class="rounded-lg border bg-background p-3 shadow-lg"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="truncate text-sm font-medium">{{ upload.filename }}</span>
                <button
                    v-if="upload.status !== 'uploading'"
                    class="text-xs text-muted-foreground hover:text-foreground"
                    @click="remove(upload.id)"
                >
                    ✕
                </button>
            </div>

            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full transition-all duration-200"
                    :class="upload.status === 'error' ? 'bg-destructive' : 'bg-primary'"
                    :style="{ width: `${upload.progress}%` }"
                />
            </div>

            <p class="mt-1 text-xs text-muted-foreground">
                <template v-if="upload.status === 'uploading'">Uploading… {{ upload.progress }}%</template>
                <template v-else-if="upload.status === 'done'">Uploaded — processing will begin shortly</template>
                <template v-else class="text-destructive">{{ upload.error }}</template>
            </p>
        </div>
    </div>
</template>