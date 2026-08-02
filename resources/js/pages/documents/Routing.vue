<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog, DialogContent, DialogDescription,
    DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
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
        current_holder_id: number | null;
        current_org_unit: string | null;
    };
    can_receive: boolean;
    can_forward: boolean;
    is_focal: boolean;
    colleagues: { id: number; name: string }[];
    divisions: { id: number; name: string }[];
    trail: TrailEntry[];
}>();

const actionLabel = (action: string) =>
    action === 'forwarded_to_focal' ? 'Sent to division focal'
    : action === 'forwarded' ? 'Forwarded'
    : action === 'returned' ? 'Returned'
    : action === 'approved' ? 'Approved'
    : action === 'signed' ? 'Signed'
    : action;

async function markReceived(documentId: number) {
    await axios.post(`/documents/${documentId}/receive`);
    router.reload({ only: ['document', 'can_receive', 'trail'] });
}


/* Forward ------------------------------------------------------------ */

const forwardDialogOpen = ref(false);
const routingScope = ref<'within_division' | 'cross_division'>('within_division');
const toUserId = ref<number | null>(null);
const toOrgUnitId = ref<number | null>(null);
const remarks = ref('');
const forwardError = ref('');

function openForward() {
    routingScope.value = 'within_division';
    toUserId.value = null;
    toOrgUnitId.value = null;
    remarks.value = '';
    forwardError.value = '';
    forwardDialogOpen.value = true;
}

async function submitForward() {
    forwardError.value = '';

    try {
        await axios.post(`/documents/${props.document.id}/forward`, {
            scope: routingScope.value,
            to_user_id: toUserId.value,
            to_org_unit_id: toOrgUnitId.value,
            remarks: remarks.value || null,
        });

        forwardDialogOpen.value = false;
        router.reload({ only: ['document', 'can_receive', 'can_forward', 'trail'] });
    } catch (err: any) {
        forwardError.value =
            err.response?.data?.message ??
            err.response?.data?.errors?.to_user_id?.[0] ??
            err.response?.data?.errors?.to_org_unit_id?.[0] ??
            'Could not forward this document.';
    }
}


</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ document.original_filename }}</h1>
                <p class="text-sm text-muted-foreground">
                    Currently with
                    <strong>{{ document.current_holder ?? '—' }}</strong>
                    <span v-if="document.current_org_unit"> · {{ document.current_org_unit }}</span>
                </p>
                <Badge class="mt-2" variant="secondary">{{ document.tracking_status }}</Badge>
            </div>

            <div class="flex shrink-0 gap-2">
                <Button v-if="can_receive" size="sm" @click="markReceived">
                    Mark received
                </Button>
                <Button v-if="can_forward" variant="outline" size="sm" @click="openForward">
                    Forward
                </Button>
            </div>
        </div>
        <Link
            :href="`/documents/${document.id}/sign`"
            class="text-sm underline underline-offset-4"
        >
            Sign this document
        </Link>
        <!-- PDF preview -->
        <div class="rounded-lg border">
            <PdfPreview :document-id="document.id" />
        </div>

        <!-- Vertical timeline -->
        <div class="relative flex flex-col gap-6 pl-6">
            <div class="absolute bottom-2 left-[7px] top-2 w-px bg-border" />
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
                    <div class="flex items-center justify-between">
                        <div>
                            <p v-if="entry.received_at" class="text-xs text-emerald-600">
                                Received {{ entry.received_at }}
                            </p>
                            <p v-else class="text-xs text-amber-600">
                                Awaiting receipt
                            </p>
                        </div>
                        <div v-if="entry.to === document.current_holder && entry.received_at === null && can_receive" class="mt-2">
                            <Button size="sm" @click="markReceived(document.id)">
                                Mark received
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Origin marker -->
            <div class="relative">
                <div class="absolute -left-6 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                <p class="text-sm font-medium">Uploaded</p>
            </div>

            <div v-if="trail.length === 0" class="text-sm text-muted-foreground">
                This document hasn't been routed anywhere yet.
            </div>
        </div>

        <Link href="/documents" class="text-sm text-muted-foreground underline underline-offset-4">
            ← Back to documents
        </Link>

        <!-- Forward dialog -->
        <Dialog v-model:open="forwardDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Forward document</DialogTitle>
                    <DialogDescription>{{ document.original_filename }}</DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label>Send to</Label>
                        <Select v-model="routingScope">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="within_division">Someone in my division</SelectItem>
                                <SelectItem v-if="is_focal" value="cross_division">Another division</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="routingScope === 'within_division'" class="grid gap-2">
                        <Label>Colleague</Label>
                        <Select v-model="toUserId">
                            <SelectTrigger><SelectValue placeholder="Choose a colleague" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="c in colleagues" :key="c.id" :value="c.id">
                                    {{ c.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-else class="grid gap-2">
                        <Label>Division</Label>
                        <Select v-model="toOrgUnitId">
                            <SelectTrigger><SelectValue placeholder="Choose a division" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="d in divisions" :key="d.id" :value="d.id">
                                    {{ d.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Delivered to that division's Document Focal for intake.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Remarks (optional)</Label>
                        <Input v-model="remarks" placeholder="e.g. For approval" />
                    </div>

                    <p v-if="forwardError" class="text-sm text-destructive">{{ forwardError }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="forwardDialogOpen = false">Cancel</Button>
                    <Button
                        type="button"
                        :disabled="routingScope === 'within_division' ? !toUserId : !toOrgUnitId"
                        @click="submitForward"
                    >
                        Forward
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>