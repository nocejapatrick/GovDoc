<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    settings: {
        ai_module_enabled: boolean;
    };
}>();

const form = useForm({
    ai_module_enabled: props.settings.ai_module_enabled,
});

function save() {
    form.put('/admin/settings', { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">System Settings</h1>
            <p class="text-sm text-muted-foreground">
                Enable or disable optional modules across the whole system.
            </p>
        </div>

        <div class="rounded-lg border p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <Label for="ai_module_enabled" class="text-base font-medium">AI Assistant</Label>
                    <p class="text-sm text-muted-foreground">
                        Lets users ask questions about documents via the "Ask AI" feature, and embeds
                        newly-processed documents for it. When disabled, "Ask AI" is hidden and no
                        embedding work happens in the background.
                    </p>
                </div>
                <Checkbox
                    id="ai_module_enabled"
                    :model-value="form.ai_module_enabled"
                    @update:model-value="(checked) => (form.ai_module_enabled = !!checked)"
                />
            </div>
        </div>

        <div>
            <Button :disabled="form.processing" @click="save">Save changes</Button>
        </div>
    </div>
</template>
