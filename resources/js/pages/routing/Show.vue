<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
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
import { Skeleton } from '@/components/ui/skeleton';
import { ArrowLeft } from '@lucide/vue';

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

interface DocumentVersion {
    id: number;
    version_number: number;
    label: string | null;
    created_by: string | null;
    created_at: string;
}

interface CaseDocument {
    id: number;
    original_filename: string;
    uploader: string | null;
    can_delete: boolean;
    versions: DocumentVersion[];
}

const props = defineProps<{
    routingCase: {
        id: number;
        title: string;
        tracking_status: string;
        current_holder: string | null;
        current_holder_id: number | null;
        current_org_unit: string | null;
    };
    documents: CaseDocument[];
    can_receive: boolean;
    can_forward: boolean;
    is_current_holder: boolean;
    is_focal: boolean;
    colleagues: { id: number; name: string; position: string | null }[];
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

/* Mark received -------------------------------------------------------- */

async function markReceived() {
    await axios.post(`/routing/${props.routingCase.id}/receive`);
    router.reload({ only: ['case', 'can_receive', 'can_forward', 'trail'] });
}

/* Forward --------------------------------------------------------------- */

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
        await axios.post(`/routing/${props.routingCase.id}/forward`, {
            scope: routingScope.value,
            to_user_id: toUserId.value,
            to_org_unit_id: toOrgUnitId.value,
            remarks: remarks.value || null,
        });

        forwardDialogOpen.value = false;
        router.reload({ only: ['case', 'can_receive', 'can_forward', 'trail'] });
    } catch (err: any) {
        forwardError.value =
            err.response?.data?.message ??
            err.response?.data?.errors?.to_user_id?.[0] ??
            err.response?.data?.errors?.to_org_unit_id?.[0] ??
            'Could not forward this routingCase.';
    }
}

/* Add supporting file ---------------------------------------------------- */

const addFileInput = ref<HTMLInputElement | null>(null);
const addingFile = ref(false);

function onAddFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;

    addingFile.value = true;

    const form = new FormData();
    form.append('file', file);

    router.post(`/routing/${props.routingCase.id}/files`, form, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            addingFile.value = false;
            if (addFileInput.value) addFileInput.value.value = '';
        },
    });
}

/* Replace file ------------------------------------------------------------- */

const replaceFileInput = ref<HTMLInputElement | null>(null);
const replacingDocId = ref<number | null>(null);
const replacingFile = ref(false);

function triggerReplace(docId: number) {
    replacingDocId.value = docId;
    replaceFileInput.value?.click();
}

function onReplaceFileChosen(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    const docId = replacingDocId.value;
    if (!file || !docId) return;

    replacingFile.value = true;

    const form = new FormData();
    form.append('file', file);

    router.post(`/documents/${docId}/replace`, form, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            replacingFile.value = false;
            replacingDocId.value = null;
            if (replaceFileInput.value) replaceFileInput.value.value = '';
        },
    });
}

/* Delete file -------------------------------------------------------------- */

const deletingDoc = ref<CaseDocument | null>(null);
const deleteFileDialogOpen = ref(false);
const deleteFileForm = useForm({ password: '' });

function confirmDeleteFile(doc: CaseDocument) {
    deletingDoc.value = doc;
    deleteFileForm.reset();
    deleteFileForm.clearErrors();
    deleteFileDialogOpen.value = true;
}

function deleteFile() {
    if (!deletingDoc.value) return;

    deleteFileForm.delete(`/routing/${props.routingCase.id}/files/${deletingDoc.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteFileDialogOpen.value = false;
            deletingDoc.value = null;
            deleteFileForm.reset();
        },
    });
}

/* PDF preview modal ------------------------------------------------------ */

const pdfDialogOpen = ref(false);
const viewingDocumentId = ref<number | null>(null);
const viewingVersionId = ref<number | null>(null);

function openPdf(documentId: number, versionId: number | null = null) {
    viewingDocumentId.value = documentId;
    viewingVersionId.value = versionId;
    pdfDialogOpen.value = true;
}

/* Version history ---------------------------------------------------------- */

const versionsDialogOpen = ref(false);
const viewingVersionsDoc = ref<CaseDocument | null>(null);

function openVersions(doc: CaseDocument) {
    viewingVersionsDoc.value = doc;
    versionsDialogOpen.value = true;
}

function viewVersion(versionId: number) {
    if (!viewingVersionsDoc.value) return;
    versionsDialogOpen.value = false;
    openPdf(viewingVersionsDoc.value.id, versionId);
}

function backToVersions() {
    pdfDialogOpen.value = false;
    versionsDialogOpen.value = true;
}

/* Timeline (newest first) ------------------------------------------------ */

const reversedTrail = computed(() => [...props.trail].reverse());
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ routingCase.title }}</h1>
                <p class="text-sm text-muted-foreground">
                    Currently with
                    <strong>{{ routingCase.current_holder ?? '—' }}</strong>
                    <span v-if="routingCase.current_org_unit"> · {{ routingCase.current_org_unit }}</span>
                </p>
                <Badge class="mt-2" variant="secondary">{{ routingCase.tracking_status }}</Badge>
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

        <!-- Files in this case -->
        <div class="rounded-lg border p-4">
            <p class="mb-3 text-sm font-medium">Files in this case</p>

            <div v-if="can_receive" class="flex flex-col gap-1">
                <div
                    v-for="doc in documents"
                    :key="doc.id"
                    class="flex items-center justify-between rounded px-2 py-1.5"
                >
                    <Skeleton class="h-4 w-40" />
                    <Skeleton class="h-4 w-14" />
                </div>

                <p class="mt-2 text-sm text-muted-foreground italic text-red-600">
                    Mark this case as received to view its files.
                </p>    
            </div>

            <div v-else class="flex flex-col gap-1">
                <div
                    v-for="doc in documents"
                    :key="doc.id"
                    class="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-muted/50"
                >
                    <div class="flex flex-col truncate">
                        <span class="truncate">{{ doc.original_filename }}</span>
                        <span class="text-xs text-muted-foreground">Uploaded by {{ doc.uploader ?? 'Unknown' }}</span>
                    </div>
                    <div class="flex shrink-0 gap-3 text-xs">
                        <button class="underline underline-offset-4" @click="openPdf(doc.id)">
                            View
                        </button>
                        <button
                            v-if="doc.versions.length > 1"
                            class="underline underline-offset-4"
                            @click="openVersions(doc)"
                        >
                            Versions ({{ doc.versions.length }})
                        </button>
                        <Link
                            v-if="is_current_holder"
                            :href="`/documents/${doc.id}/sign`"
                            class="underline underline-offset-4"
                        >
                            Sign
                        </Link>
                        <button
                            v-if="is_current_holder"
                            class="underline underline-offset-4 disabled:opacity-50"
                            :disabled="replacingFile && replacingDocId === doc.id"
                            @click="triggerReplace(doc.id)"
                        >
                            {{ replacingFile && replacingDocId === doc.id ? 'Replacing…' : 'Replace' }}
                        </button>
                        <button
                            v-if="doc.can_delete"
                            class="text-destructive underline underline-offset-4"
                            @click="confirmDeleteFile(doc)"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <p v-if="documents.length === 0" class="text-sm text-muted-foreground">
                    No files attached yet.
                </p>
            </div>

            <div v-if="is_current_holder && !can_receive" class="mt-3 border-t pt-3">
                <input
                    ref="addFileInput"
                    type="file"
                    accept="application/pdf"
                    class="hidden"
                    @change="onAddFile"
                />
                <input
                    ref="replaceFileInput"
                    type="file"
                    accept="application/pdf"
                    class="hidden"
                    @change="onReplaceFileChosen"
                />
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="addingFile"
                    @click="addFileInput?.click()"
                >
                    {{ addingFile ? 'Adding…' : '+ Add supporting file' }}
                </Button>
            </div>
        </div>

        <!-- Vertical timeline -->
        <div class="relative flex flex-col gap-6 pl-6">
            <div class="absolute bottom-2 left-[7px] top-2 w-px bg-border" />

            <div v-for="entry in reversedTrail" :key="entry.id" class="relative">
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
                    <p v-if="entry.remarks && !can_receive" class="text-sm italic text-muted-foreground">
                        "{{ entry.remarks }}"
                    </p>
                    <p v-else class="mt-2 text-sm text-muted-foreground italic text-red-600">
                        Mark this case as received to view remarks.
                    </p>
                    <p v-if="entry.received_at" class="text-xs text-emerald-600">
                        Received {{ entry.received_at }}
                    </p>
                    <p v-else class="text-xs text-amber-600">
                        Awaiting receipt
                    </p>
                </div>
            </div>

            <div class="relative">
                <div class="absolute -left-6 top-1 h-3 w-3 rounded-full border-2 border-primary bg-background" />
                <p class="text-sm font-medium">Started</p>
            </div>

            <div v-if="trail.length === 0" class="text-sm text-muted-foreground">
                This case hasn't been routed anywhere yet.
            </div>
        </div>

        <Link href="/routing" class="text-sm text-muted-foreground underline underline-offset-4">
            ← Back to routing
        </Link>

        <!-- Forward dialog -->
        <Dialog v-model:open="forwardDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Forward case</DialogTitle>
                    <DialogDescription>{{ routingCase.title }}</DialogDescription>
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
                                    {{ c.name }}<span v-if="c.position" class="text-muted-foreground"> — {{ c.position }}</span>
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
                    <Button type="button" variant="outline" @click="forwardDialogOpen = false">
                        Cancel
                    </Button>
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

        <!-- PDF preview modal -->
        <Dialog v-model:open="pdfDialogOpen">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <button
                            v-if="viewingVersionId"
                            type="button"
                            class="rounded p-1 hover:bg-muted"
                            aria-label="Back to versions"
                            @click="backToVersions"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </button>
                        {{ documents.find(d => d.id === viewingDocumentId)?.original_filename }}
                    </DialogTitle>
                </DialogHeader>
                <PdfPreview
                    v-if="pdfDialogOpen && viewingDocumentId"
                    :document-id="viewingDocumentId"
                    :version-id="viewingVersionId"
                />
            </DialogContent>
        </Dialog>

        <!-- Version history dialog -->
        <Dialog v-model:open="versionsDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Version history</DialogTitle>
                    <DialogDescription>{{ viewingVersionsDoc?.original_filename }}</DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-1">
                    <div
                        v-for="version in viewingVersionsDoc?.versions ?? []"
                        :key="version.id"
                        class="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-muted/50"
                    >
                        <div class="flex flex-col">
                            <span>v{{ version.version_number }} — {{ version.label ?? 'Untitled' }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ version.created_by ?? 'Unknown' }} · {{ version.created_at }}
                            </span>
                        </div>
                        <button class="shrink-0 text-xs underline underline-offset-4" @click="viewVersion(version.id)">
                            View
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Delete file confirmation -->
        <Dialog v-model:open="deleteFileDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete this file?</DialogTitle>
                    <DialogDescription>
                        This will permanently delete
                        <strong>{{ deletingDoc?.original_filename }}</strong>, including all of its versions.
                        This cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="deleteFile">
                    <div class="grid gap-2">
                        <Label for="delete-file-password">Confirm your password</Label>
                        <Input
                            id="delete-file-password"
                            v-model="deleteFileForm.password"
                            type="password"
                            autocomplete="current-password"
                            autofocus
                        />
                        <p v-if="deleteFileForm.errors.password" class="text-sm text-destructive">
                            {{ deleteFileForm.errors.password }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="deleteFileDialogOpen = false">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="deleteFileForm.processing || !deleteFileForm.password"
                        >
                            {{ deleteFileForm.processing ? 'Deleting…' : 'Delete file' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>