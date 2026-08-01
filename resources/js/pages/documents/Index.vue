<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link } from '@lucide/vue';
import axios from 'axios';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUploads } from '@/composables/useUploads';

/* Types -------------------------------------------------------------- */

interface DocumentRow {
    id: number;
    user_id: number;
    owner: string;
    visibility: 'private' | 'public';
    original_filename: string;
    status: 'pending' | 'processed' | 'failed';
    extraction_method: string | null;
    page_count: number | null;
    error: string | null;
    size_kb: number;
    created_at: string;
    progress_page: number | null;
    progress_total: number | null;
    current_holder_id : number | null;
    current_holder_name : string | null;
    tracking_status: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    documents: {
        data: DocumentRow[];
        links: PaginationLink[];
        total: number;
    };
}>();

const page = usePage();
const currentUserId = computed(() => (page.props as any).auth?.user?.id);

/* Upload with visibility choice -------------------------------------- */

const { startUpload } = useUploads();
const fileInput = ref<HTMLInputElement | null>(null);

const uploadDialogOpen = ref(false);
const chosenFile = ref<File | null>(null);
const chosenVisibility = ref<'private' | 'public'>('private');

function onFileChosen(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = ''; // allow re-picking the same file

    if (file) {
        chosenFile.value = file;
        chosenVisibility.value = 'private';
        uploadDialogOpen.value = true;
    }
}

function confirmUpload() {
    if (!chosenFile.value) return;

    startUpload(chosenFile.value, chosenVisibility.value);
    uploadDialogOpen.value = false;
    chosenFile.value = null;
}

/* Delete with password confirmation ----------------------------------- */

const deleteDialogOpen = ref(false);
const deletingDoc = ref<DocumentRow | null>(null);

const deleteForm = useForm({ password: '' });

function confirmDelete(doc: DocumentRow) {
    deletingDoc.value = doc;
    deleteForm.reset();
    deleteForm.clearErrors();
    deleteDialogOpen.value = true;
}

function destroyDocument() {
    if (!deletingDoc.value) return;

    deleteForm.delete(`/documents/${deletingDoc.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
            deletingDoc.value = null;
            deleteForm.reset();
        },
    });
}

/* Auto-refresh while anything is pending ------------------------------ */

let poll: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    poll = setInterval(() => {
        if (props.documents.data.some((d) => d.status === 'pending')) {
            router.reload({ only: ['documents'] });
        }
    }, 5000);
});

onUnmounted(() => {
    if (poll) clearInterval(poll);
});

/* Helpers ------------------------------------------------------------- */

const statusVariant = (status: DocumentRow['status']) =>
    status === 'processed' ? 'default' : status === 'failed' ? 'destructive' : 'secondary';

const methodLabel = (method: string | null) =>
    method === 'text' ? 'Native text' : method === 'ocr' ? 'OCR' : method === 'mixed' ? 'Mixed' : '—';




/* Forward ------------------------------------------------------------- */

const forwardDialogOpen = ref(false);
const forwardingDoc = ref<DocumentRow | null>(null);
const routingScope = ref<'within_division' | 'cross_division'>('within_division');
const toUserId = ref<number | null>(null);
const toOrgUnitId = ref<number | null>(null);
const remarks = ref('');
const forwardError = ref('');

const colleagues = ref<{ id: number; name: string }[]>([]);
const divisions = ref<{ id: number; name: string }[]>([]);

const isFocal = computed(() =>
    ((usePage().props as any).auth?.user?.roles ?? []).includes('document_focal'),
);

onMounted(async () => {
    const { data } = await axios.get('/documents/routing-options');
    colleagues.value = data.colleagues;
    divisions.value = data.divisions;
});

function openForward(doc: DocumentRow) {
    forwardingDoc.value = doc;
    routingScope.value = 'within_division';
    toUserId.value = null;
    toOrgUnitId.value = null;
    remarks.value = '';
    forwardError.value = '';
    forwardDialogOpen.value = true;
}

async function submitForward() {
    if (!forwardingDoc.value) return;
    forwardError.value = '';

    try {
        await axios.post(`/documents/${forwardingDoc.value.id}/forward`, {
            scope: routingScope.value,
            to_user_id: toUserId.value,
            to_org_unit_id: toOrgUnitId.value,
            remarks: remarks.value || null,
        });

        forwardDialogOpen.value = false;
        router.reload({ only: ['documents'] });
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
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Documents</h1>
                <p class="text-sm text-muted-foreground">
                    Upload PDFs — text is extracted automatically, scanned pages go through OCR.
                </p>
            </div>
            <div>
                <input
                    ref="fileInput"
                    type="file"
                    accept="application/pdf"
                    class="hidden"
                    @change="onFileChosen"
                />
                <Button @click="fileInput?.click()">Upload PDF</Button>
            </div>
        </div>

        <!-- Table -->
        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>File</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Method</TableHead>
                        <TableHead>Pages</TableHead>
                        <TableHead>Size</TableHead>
                        <TableHead>Uploaded</TableHead>
                        <TableHead>Holder</TableHead>
                        <TableHead class="w-[90px] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="doc in documents.data" :key="doc.id">
                        <TableCell class="max-w-[280px] font-medium">
                            <div class="flex items-center gap-2">
                                <a
                                    :href="`/documents/${doc.id}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="truncate hover:underline underline-offset-4"
                                >
                                    {{ doc.original_filename }}
                                </a>
                                <Badge
                                    v-if="doc.visibility === 'public'"
                                    variant="outline"
                                    class="shrink-0"
                                >
                                    Public
                                </Badge>
                            </div>
                            <p
                                v-if="doc.user_id !== currentUserId"
                                class="text-xs text-muted-foreground"
                            >
                                by {{ doc.owner }}
                            </p>
                        </TableCell>

                        <TableCell>
                            <template v-if="doc.status === 'pending' && doc.progress_total">
                                <span class="text-sm">
                                    Reading page {{ doc.progress_page }} of {{ doc.progress_total }}
                                </span>
                                <div class="mt-1 h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-primary transition-all duration-500"
                                        :style="{ width: `${Math.round(((doc.progress_page ?? 0) / doc.progress_total) * 100)}%` }"
                                    />
                                </div>
                            </template>
                            <template v-else>
                                <Badge
                                    :variant="statusVariant(doc.status)"
                                    :class="doc.status === 'pending' && 'animate-pulse'"
                                >
                                    {{ doc.status }}
                                </Badge>
                            </template>
                            <p
                                v-if="doc.status === 'failed' && doc.error"
                                class="mt-1 max-w-[220px] text-xs text-destructive"
                            >
                                {{ doc.error }}
                            </p>
                        </TableCell>

                        <TableCell>{{ methodLabel(doc.extraction_method) }}</TableCell>
                        <TableCell>{{ doc.page_count ?? '—' }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ doc.size_kb }} KB</TableCell>
                        <TableCell class="text-muted-foreground">{{ doc.created_at }}</TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ doc.current_holder_name ?? '—' }}
                            <Badge v-if="doc.tracking_status !== 'draft'" variant="outline" class="ml-1">
                                {{ doc.tracking_status }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="doc.user_id === currentUserId"
                                variant="outline"
                                size="sm"
                                class="bg-red-500 text-white hover:bg-red-700 hover:text-white"
                                @click="confirmDelete(doc)"
                            >
                                Delete
                            </Button>
                            <Button
                                v-if="doc.user_id === currentUserId && doc.status === 'failed'"
                                variant="outline"
                                size="sm"
                                class="ml-3"
                                @click="router.post(`/documents/${doc.id}/retry`, {}, { preserveScroll: true })"
                            >
                                Retry
                            </Button>
                            <Button
                                v-if="doc.current_holder_id === currentUserId && doc.visibility === 'private'"
                                variant="outline"
                                size="sm"
                                @click="openForward(doc)"
                            >
                                Forward
                            </Button>
                        </TableCell>
                    </TableRow>

                    <TableRow v-if="documents.data.length === 0">
                        <TableCell colspan="7" class="h-24 text-center text-muted-foreground">
                            No documents yet. Upload your first PDF to get started.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <!-- Pagination -->
        <div v-if="documents.total > 10" class="flex justify-end gap-1">
            <Button
                v-for="(link, i) in documents.links"
                :key="i"
                :variant="link.active ? 'default' : 'outline'"
                size="sm"
                :disabled="!link.url"
                @click="link.url && router.get(link.url, {}, { preserveState: true })"
            >
                <span v-html="link.label" />
            </Button>
        </div>

        <!-- Upload visibility dialog -->
        <Dialog v-model:open="uploadDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Upload document</DialogTitle>
                    <DialogDescription>
                        <strong>{{ chosenFile?.name }}</strong> — choose who can see this document.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label>Visibility</Label>
                    <Select v-model="chosenVisibility">
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="private">Private — only you</SelectItem>
                            <SelectItem value="public">Public — all employees</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ chosenVisibility === 'public'
                            ? 'Everyone in the organization can find, read, and search this document.'
                            : 'Only you can see and search this document.' }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="uploadDialogOpen = false">
                        Cancel
                    </Button>
                    <Button type="button" @click="confirmUpload">Upload</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete confirmation dialog -->
        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete this document?</DialogTitle>
                    <DialogDescription>
                        This will permanently delete
                        <strong>{{ deletingDoc?.original_filename }}</strong> — the file,
                        its extracted text, and its search entry. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <form class="flex flex-col gap-4" @submit.prevent="destroyDocument">
                    <div class="grid gap-2">
                        <Label for="delete-password">Confirm your password</Label>
                        <Input
                            id="delete-password"
                            v-model="deleteForm.password"
                            type="password"
                            autocomplete="current-password"
                            autofocus
                        />
                        <p v-if="deleteForm.errors.password" class="text-sm text-destructive">
                            {{ deleteForm.errors.password }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="deleteDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="deleteForm.processing || !deleteForm.password"
                        >
                            {{ deleteForm.processing ? 'Deleting…' : 'Delete document' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>


        <Dialog v-model:open="forwardDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Forward document</DialogTitle>
                    <DialogDescription>
                        <strong>{{ forwardingDoc?.original_filename }}</strong>
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label>Send to</Label>
                        <Select v-model="routingScope">
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="within_division">Someone in my division</SelectItem>
                                <SelectItem v-if="isFocal" value="cross_division">Another division</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="routingScope === 'within_division'" class="grid gap-2">
                        <Label>Colleague</Label>
                        <Select v-model="toUserId">
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a colleague" />
                            </SelectTrigger>
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
                            <SelectTrigger>
                                <SelectValue placeholder="Choose a division" />
                            </SelectTrigger>
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
                        <Input v-model="remarks" placeholder="e.g. For review and comments" />
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
    </div>
</template>