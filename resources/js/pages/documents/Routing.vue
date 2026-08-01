<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import PdfPreview from '@/components/PdfPreview.vue';  

interface TrailEntry {
    id: number;
    from: string;
    from_division: string | null;
    to: string;
    to_division: string | null;
    action: string;
    remarks: string | null;
    sent_at: string;
    received_at: string | null;
}

const props = defineProps<{
    document: {
        id: number;
        original_filename: string;
        tracking_status: string;
        current_holder: string | null;
        current_org_unit: string | null;
    };
    trail: TrailEntry[];
}>();

const actionLabel = (action: string) =>
    action === 'forwarded_to_focal' ? 'Sent to division focal'
    : action === 'forwarded' ? 'Forwarded'
    : action === 'returned' ? 'Returned'
    : action;
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ document.original_filename }}</h1>
            <p class="text-sm text-muted-foreground">
                Currently with
                <strong>{{ document.current_holder ?? '—' }}</strong>
                <span v-if="document.current_org_unit"> · {{ document.current_org_unit }}</span>
            </p>
            <Badge class="mt-2" variant="secondary">{{ document.tracking_status }}</Badge>
        </div>

        <!-- PDF preview -->
        <div class="rounded-lg border">
            <PdfPreview :document-id="document.id" />
        </div>

        <!-- Vertical timeline -->
        <div class="relative flex flex-col gap-6 pl-6">
            <div class="absolute bottom-2 left-[7px] top-2 w-px bg-border" />

            <!-- Origin marker -->
            <div class="relative">
                <div class="absolute -left-6 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                <p class="text-sm font-medium">Uploaded</p>
            </div>

            <div v-for="entry in trail" :key="entry.id" class="relative">
                <div class="absolute -left-6 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                <div class="flex flex-col gap-1 rounded-lg border p-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium">{{ actionLabel(entry.action) }}</p>
                        <span class="text-xs text-muted-foreground">{{ entry.sent_at }}</span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ entry.from }}<span v-if="entry.from_division"> ({{ entry.from_division }})</span>
                        →
                        {{ entry.to }}<span v-if="entry.to_division"> ({{ entry.to_division }})</span>
                    </p>
                    <p v-if="entry.remarks" class="text-sm italic text-muted-foreground">
                        "{{ entry.remarks }}"
                    </p>
                    <p v-if="entry.received_at" class="text-xs text-emerald-600">
                        Received {{ entry.received_at }}
                    </p>
                    <p v-else class="text-xs text-amber-600">
                        Awaiting receipt
                    </p>
                </div>
            </div>

            <div v-if="trail.length === 0" class="text-sm text-muted-foreground">
                This document hasn't been routed anywhere yet.
            </div>
        </div>

        <Link href="/documents" class="text-sm text-muted-foreground underline underline-offset-4">
            ← Back to documents
        </Link>
    </div>
</template>