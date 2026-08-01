<script setup lang="ts">
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';

interface InboxRow {
    id: number;
    original_filename: string;
    tracking_status: string;
    from: string;
    received_at: string | null;
}

interface PaginationLink { url: string | null; label: string; active: boolean; }

defineProps<{
    documents: { data: InboxRow[]; links: PaginationLink[]; total: number };
}>();

async function receive(doc: InboxRow) {
    await axios.post(`/documents/${doc.id}/receive`);
    router.reload({ only: ['documents'] });
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Inbox</h1>
            <p class="text-sm text-muted-foreground">Documents currently routed to you.</p>
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>File</TableHead>
                        <TableHead>From</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-[120px] text-right">Action</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="doc in documents.data" :key="doc.id">
                        <TableCell class="font-medium">
                            <a :href="`/documents/${doc.id}`" target="_blank" class="hover:underline">
                                {{ doc.original_filename }}
                            </a>
                            <a :href="`/documents/${doc.id}/routing`" class="hover:underline grey">
                                Track
                            </a>
                        </TableCell>
                        <TableCell>{{ doc.from }}</TableCell>
                        <TableCell>
                            <Badge variant="secondary">{{ doc.tracking_status }}</Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="!doc.received_at"
                                size="sm"
                                @click="receive(doc)"
                            >
                                Mark received
                            </Button>
                        </TableCell>
                    </TableRow>

                    <TableRow v-if="documents.data.length === 0">
                        <TableCell colspan="4" class="h-24 text-center text-muted-foreground">
                            Nothing in your inbox right now.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div v-if="documents.total > 15" class="flex justify-end gap-1">
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
    </div>
</template>