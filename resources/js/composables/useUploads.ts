import { reactive } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

export interface UploadItem {
    id: number;
    filename: string;
    progress: number;                       // 0–100
    status: 'uploading' | 'done' | 'error';
    error: string | null;
}

// Module scope: created ONCE for the whole SPA session,
// shared by every component that imports it. Survives page
// navigation because Inertia never reloads the browser page.
const uploads = reactive<UploadItem[]>([]);

let nextId = 1;

async function startUpload(
    file: File,
    visibility: 'private' | 'public' = 'private',
    includeInLlm = false,
): Promise<void> {
    const item: UploadItem = reactive({
        id: nextId++,
        filename: file.name,
        progress: 0,
        status: 'uploading',
        error: null,
    });

    uploads.push(item);

    const form = new FormData();
    form.append('file', file);
    form.append('visibility', visibility);
    form.append('include_in_llm', includeInLlm ? '1' : '0');

    try {
        await axios.post('/documents', form, {
            headers: { Accept: 'application/json' },
            onUploadProgress: (e) => {
                if (e.total) {
                    item.progress = Math.round((e.loaded / e.total) * 100);
                }
            },
        });

        item.status = 'done';
        item.progress = 100;

        // If the user is currently looking at the documents page,
        // refresh its list so the new row appears.
        router.reload({ only: ['documents'] });

        // Tidy up the finished entry after a few seconds.
        setTimeout(() => remove(item.id), 4000);
    } catch (err: any) {
        item.status = 'error';
        item.error =
            err.response?.data?.errors?.file?.[0] ??
            err.response?.data?.message ??
            'Upload failed.';
    }
}

function remove(id: number): void {
    const index = uploads.findIndex((u) => u.id === id);
    if (index !== -1) uploads.splice(index, 1);
}

export function useUploads() {
    return { uploads, startUpload, remove };
}