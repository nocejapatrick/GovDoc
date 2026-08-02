<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog, DialogContent, DialogDescription,
    DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';

interface RoutingCaseRow {
    id: number;
    title: string;
    file_count: number;
    tracking_status: string;
    current_holder: string | null;
    is_mine_to_act_on: boolean;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    cases: {
        data: RoutingCaseRow[];
        links: PaginationLink[];
        total: number;
    };
}>();

/* New case dialog ------------------------------------------------------- */

const newCaseDialogOpen = ref(false);
const caseTitle = ref('');
const selectedFiles = ref<File[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const creating = ref(false);
const createError = ref('');

function openNewCase() {
    caseTitle.value = '';
    selectedFiles.value = [];
    createError.value = '';
    newCaseDialogOpen.value = true;
}

function onFilesChosen(e: Event) {
    const files = Array.from((e.target as HTMLInputElement).files ?? []);
    selectedFiles.value = files;
}

function removeFile(index: number) {
    selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index);
}

async function submitNewCase() {
    if (selectedFiles.value.length === 0) {
        createError.value = 'Please select at least one PDF.';
        return;
    }

    creating.value = true;
    createError.value = '';

    const form = new FormData();
    if (caseTitle.value) form.append('title', caseTitle.value);
    selectedFiles.value.forEach((file) => form.append('files[]', file));

    try {
        const { data } = await axios.post('/routing', form, {
            headers: { Accept: 'application/json' },
        });

        newCaseDialogOpen.value = false;
        router.visit(`/routing/${data.id}`);
    } catch (err: any) {
        createError.value =
            err.response?.data?.message ??
            err.response?.data?.errors?.['files.0']?.[0] ??
            'Could not create routing case.';
        creating.value = false;
    }
}

/* Helpers ---------------------------------------------------------------- */

const statusVariant = (status: string) =>
    status === 'signed' || status === 'approved' ? 'default'
    : status === 'returned' ? 'destructive'
    : 'secondary';
</script>

<template>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Document Routing</h1>
                <p class="text-sm text-muted-foreground">
                    Route forms and memos for approval — not indexed for search.
                </p>
            </div>
            <Button @click="openNewCase">Start new routing</Button>
        </div>

        <!-- Table -->
        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Case</TableHead>
                        <TableHead>Files</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Holder</TableHead>
                        <TableHead>Started</TableHead>
                        <TableHead class="w-[80px]"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="c in cases.data" :key="c.id">
                        <TableCell class="font-medium">{{ c.title }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ c.file_count }}</TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(c.tracking_status)">
                                {{ c.tracking_status }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ c.current_holder ?? '—' }}
                            <span v-if="c.is_mine_to_act_on" class="ml-1 text-xs text-primary">(you)</span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ c.created_at }}</TableCell>
                        <TableCell>
                            <Link :href="`/routing/${c.id}`" class="text-xs underline underline-offset-4">
                                Open
                            </Link>
                        </TableCell>
                    </TableRow>

                    <TableRow v-if="cases.data.length === 0">
                        <TableCell colspan="6" class="h-24 text-center text-muted-foreground">
                            No routing cases yet. Start one to route a form for approval.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <!-- Pagination -->
        <div v-if="cases.total > 15" class="flex justify-end gap-1">
            <Button
                v-for="(link, i) in cases.links"
                :key="i"
                :variant="link.active ? 'default' : 'outline'"
                size="sm"
                :disabled="!link.url"
                @click="link.url && router.get(link.url, {}, { preserveState: true })"
            >
                <span v-html="link.label" />
            </Button>
        </div>

        <!-- New case dialog -->
        <Dialog v-model:open="newCaseDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Start new routing</DialogTitle>
                    <DialogDescription>
                        Add one or more PDFs — e.g. a form plus its supporting documents.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label>Title (optional)</Label>
                        <Input v-model="caseTitle" placeholder="e.g. Reimbursement — August 2026" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Files</Label>
                        <input
                            ref="fileInput"
                            type="file"
                            accept="application/pdf"
                            multiple
                            class="hidden"
                            @change="onFilesChosen"
                        />
                        <Button type="button" variant="outline" size="sm" @click="fileInput?.click()">
                            Choose PDFs
                        </Button>

                        <div v-if="selectedFiles.length" class="flex flex-col gap-1 rounded border p-2">
                            <div
                                v-for="(file, i) in selectedFiles"
                                :key="i"
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="truncate">{{ file.name }}</span>
                                <button
                                    type="button"
                                    class="text-xs text-muted-foreground hover:text-destructive"
                                    @click="removeFile(i)"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="createError" class="text-sm text-destructive">{{ createError }}</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="newCaseDialogOpen = false">
                        Cancel
                    </Button>
                    <Button type="button" :disabled="creating" @click="submitNewCase">
                        {{ creating ? 'Creating…' : 'Start routing' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>