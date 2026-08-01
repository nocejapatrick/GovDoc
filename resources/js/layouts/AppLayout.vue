<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import UploadOverlay from '@/components/UploadOverlay.vue';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
    <UploadOverlay />
</template>
