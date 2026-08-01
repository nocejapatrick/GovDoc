<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface ActivityRow {
    id: number;
    description: string;
    user: string;
    document: string;
    changes: Record<string, any>;
    when: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    activities: {
        data: ActivityRow[];
        links: PaginationLink[];
        total: number;
    };
}>();

const actionVariant = (description: string) =>
    description === 'deleted' ? 'destructive'
    : description === 'created' ? 'default'
    : 'secondary';

function formatChanges(changes: Record<string, any>): string[] {
    const attrs = changes?.attributes ?? {};
    const old = changes?.old ?? {};

    return Object.keys(attrs).map((key) =>
        key in old && old[key] !== attrs[key]
            ? `${key}: ${old[key]} → ${attrs[key]}`
            : `${key}: ${attrs[key]}`,
    );
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Activity log</h1>
            <p class="text-sm text-muted-foreground">
                Every upload, status change, view, and deletion across the system.
            </p>
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>User</TableHead>
                        <TableHead>Action</TableHead>
                        <TableHead>Document</TableHead>
                        <TableHead>Changes</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="activity in activities.data" :key="activity.id">
                        <TableCell class="whitespace-nowrap text-muted-foreground">
                            {{ activity.when }}
                        </TableCell>
                        <TableCell class="font-medium">{{ activity.user }}</TableCell>
                        <TableCell>
                            <Badge :variant="actionVariant(activity.description)">
                                {{ activity.description }}
                            </Badge>
                        </TableCell>
                        <TableCell class="max-w-[220px] truncate">
                            {{ activity.document }}
                        </TableCell>
                        <TableCell class="max-w-[320px] whitespace-normal text-xs text-muted-foreground">
                            <template v-if="formatChanges(activity.changes).length">
                                <p v-for="(line, i) in formatChanges(activity.changes)" :key="i" class="break-words">
                                    {{ line }}
                                </p>
                            </template>
                            <template v-else>—</template>
                        </TableCell>
                    </TableRow>

                    <TableRow v-if="activities.data.length === 0">
                        <TableCell colspan="5" class="h-24 text-center text-muted-foreground">
                            No activity yet.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div v-if="activities.total > 25" class="flex justify-end gap-1">
            <Button
                v-for="(link, i) in activities.links"
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