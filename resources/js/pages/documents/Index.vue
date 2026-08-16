<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sparkles, Send } from '@lucide/vue';
import axios from 'axios';
import { Checkbox } from '@/components/ui/checkbox';
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
    has_been_routed: boolean;
    include_in_llm: boolean;
    llm_status: 'pending' | 'ready' | 'failed' | null;
}

interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
    sources?: string[];
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
    ai_module_enabled: boolean;
}>();

const page = usePage();
const currentUserId = computed(() => (page.props as any).auth?.user?.id);

/* Upload with visibility choice -------------------------------------- */

const { startUpload } = useUploads();
const fileInput = ref<HTMLInputElement | null>(null);

const uploadDialogOpen = ref(false);
const chosenFile = ref<File | null>(null);
const chosenVisibility = ref<'private' | 'public'>('private');
const includeInLlm = ref(false);

function onFileChosen(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = ''; // allow re-picking the same file

    if (file) {
        chosenFile.value = file;
        chosenVisibility.value = 'private';
        includeInLlm.value = false;
        uploadDialogOpen.value = true;
    }
}

function confirmUpload() {
    if (!chosenFile.value) return;

    startUpload(chosenFile.value, chosenVisibility.value, includeInLlm.value);
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
        if (props.documents.data.some((d) => d.status === 'pending' || d.llm_status === 'pending')) {
            router.reload({ only: ['documents'] });
        }
    }, 5000);
});

onUnmounted(() => {
    if (poll) clearInterval(poll);
});

/* AI assistant chat ----------------------------------------------------- */

const chatDialogOpen = ref(false);
const chatMessages = ref<ChatMessage[]>([]);
const chatInput = ref('');
const chatLoading = ref(false);

async function sendChatMessage() {
    const question = chatInput.value.trim();
    if (!question || chatLoading.value) return;

    chatMessages.value.push({ role: 'user', content: question });
    chatInput.value = '';
    chatLoading.value = true;

    try {
        const { data } = await axios.post('/assistant/chat', { question });
        const result = await pollForAnswer(data.id);
        chatMessages.value.push({ role: 'assistant', content: result.answer, sources: result.sources });
    } catch (err: any) {
        chatMessages.value.push({
            role: 'assistant',
            content:
                err.response?.data?.message ??
                err.message ??
                'Something went wrong asking the assistant. Please try again.',
        });
    } finally {
        chatLoading.value = false;
    }
}

// The assistant runs as a background job (a local LLM call can take minutes),
// so we poll for its result instead of waiting on one long HTTP request.
async function pollForAnswer(id: string, attempt = 0): Promise<{ answer: string; sources: string[] }> {
    const { data } = await axios.get(`/assistant/chat/${id}`);

    if (data.status === 'pending') {
        if (attempt > 300) throw new Error('The assistant is taking too long to respond. Please try again.');
        await new Promise((resolve) => setTimeout(resolve, 2000));
        return pollForAnswer(id, attempt + 1);
    }

    return { answer: data.answer, sources: data.sources };
}

/* Helpers ------------------------------------------------------------- */

const llmStatusLabel = (status: DocumentRow['llm_status']) =>
    status === 'ready' ? 'AI ready'
    : status === 'pending' ? 'AI indexing…'
    : status === 'failed' ? 'AI failed'
    : 'AI queued';

const statusVariant = (status: DocumentRow['status']) =>
    status === 'processed' ? 'default' : status === 'failed' ? 'destructive' : 'secondary';

const methodLabel = (method: string | null) =>
    method === 'text' ? 'Native text' : method === 'ocr' ? 'OCR' : method === 'mixed' ? 'Mixed' : '—';


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
                <Button
                    v-if="props.ai_module_enabled"
                    variant="outline"
                    class="mr-2"
                    @click="chatDialogOpen = true"
                >
                    <Sparkles class="mr-1.5 h-4 w-4" />
                    Ask AI
                </Button>
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
                                <Badge
                                    v-if="doc.include_in_llm"
                                    :variant="doc.llm_status === 'failed' ? 'destructive' : 'secondary'"
                                    class="shrink-0"
                                >
                                    {{ llmStatusLabel(doc.llm_status) }}
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
                                v-if="doc.user_id === currentUserId && !doc.has_been_routed"
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

                <label v-if="props.ai_module_enabled" class="flex items-start gap-2 text-sm">
                    <Checkbox
                        :model-value="includeInLlm"
                        class="mt-0.5"
                        @update:model-value="(checked) => (includeInLlm = !!checked)"
                    />
                    <span>
                        Include in AI assistant
                        <span class="block text-xs text-muted-foreground">
                            Lets "Ask AI" answer questions using this document's content. Runs on our
                            self-hosted model — nothing is sent to a third party.
                        </span>
                    </span>
                </label>

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

        <!-- AI assistant chat -->
        <Dialog v-model:open="chatDialogOpen">
            <DialogContent class="flex max-h-[80vh] flex-col sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ask AI</DialogTitle>
                    <DialogDescription>
                        Answers are generated only from documents you've included in the AI assistant.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-1 flex-col gap-3 overflow-y-auto py-2">
                    <p v-if="chatMessages.length === 0" class="text-sm text-muted-foreground">
                        Ask a question about any document you've marked "Include in AI assistant".
                    </p>

                    <div
                        v-for="(message, i) in chatMessages"
                        :key="i"
                        class="flex flex-col gap-1 rounded-lg p-3 text-sm"
                        :class="message.role === 'user' ? 'ml-8 bg-primary text-primary-foreground' : 'mr-8 bg-muted'"
                    >
                        <p class="whitespace-pre-wrap">{{ message.content }}</p>
                    </div>

                    <p v-if="chatLoading" class="mr-8 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
                        Thinking…
                    </p>
                </div>

                <form class="flex items-center gap-2 border-t pt-3" @submit.prevent="sendChatMessage">
                    <Input
                        v-model="chatInput"
                        placeholder="Ask a question…"
                        :disabled="chatLoading"
                        autofocus
                    />
                    <Button type="submit" size="icon" :disabled="chatLoading || !chatInput.trim()">
                        <Send class="h-4 w-4" />
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>