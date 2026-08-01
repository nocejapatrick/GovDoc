<script setup lang="ts">
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

interface SearchHit {
    id: number;
    filename: string;
    method: string | null;
    created_at: string;
    score: number;
    snippets: string[];
    visibility: 'private' | 'public';
    owner: string | null;
}

const props = defineProps<{
    query: string;
    results: { total: number; hits: SearchHit[] };
}>();

const search = ref(props.query ?? '');

let timeout: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(
            '/documents/search',
            { query: search.value || undefined },
            { preserveState: true, replace: true },
        );
    }, 400);
});

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

const methodLabel = (method: string | null) =>
    method === 'text' ? 'Native text' : method === 'ocr' ? 'OCR' : method === 'mixed' ? 'Mixed' : '—';
</script>

<template>
    <div class="mx-auto flex w-full max-w-52xl flex-col gap-6 p-6">
        <!-- Header -->
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Search documents</h1>
            <p class="text-sm text-muted-foreground">
                Searches inside the full text of your PDFs — including scanned pages read by OCR.
            </p>
        </div>

        <!-- Search box -->
        <Input
            v-model="search"
            placeholder="Search for words inside your documents..."
            autofocus
        />

        <!-- Result count / empty state -->
        <p v-if="query && results.total === 0" class="text-sm text-muted-foreground">
            No documents match "{{ query }}".
        </p>
        <p v-else-if="query" class="text-sm text-muted-foreground">
            {{ results.total }} {{ results.total === 1 ? 'document' : 'documents' }} found
        </p>

        <!-- Results -->
        <div class="flex flex-col gap-4">
            <a
                v-for="hit in results.hits"
                :key="hit.id"
                :href="`/documents/${hit.id}`"
                target="_blank"
                rel="noopener"
                class="block rounded-lg border p-4 transition-colors hover:border-foreground/30 hover:bg-muted/40"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate font-medium">{{ hit.filename }}</span>
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge v-if="hit.visibility === 'public'" variant="outline">Public</Badge>
                        <Badge variant="secondary">{{ methodLabel(hit.method) }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ formatDate(hit.created_at) }}</span>
                    </div>
                </div>

                <div class="mt-2 flex flex-col gap-1">
                    <p
                        v-for="(snippet, i) in hit.snippets"
                        :key="i"
                        class="text-sm text-muted-foreground [&_em]:rounded-sm [&_em]:bg-primary/15 [&_em]:px-0.5 [&_em]:not-italic [&_em]:font-medium [&_em]:text-foreground"
                        v-html="'…' + snippet + '…'"
                    />
                </div>
            </a>
        </div>

        <!-- Back link -->
        <Link
            href="/documents"
            class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
        >
            ← Back to documents
        </Link>
    </div>
</template>