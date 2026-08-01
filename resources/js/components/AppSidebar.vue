<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { BookOpen, FileText, FolderGit2, LayoutGrid, MailSearch, ScrollText } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';
import { Inbox } from '@lucide/vue';

const page = usePage();
const isAdmin = computed(() =>
    ((page.props as any).auth?.user?.roles ?? []).some((r: any) => (r.name ?? r) === 'admin'),
);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Documents Upload',
        href: "/documents",
        icon: FileText,
    },
    {
        title: 'Search',
        href: "/documents/search",
        icon: MailSearch,
    },
    {
        title: isAdmin.value ? 'User Activities' : 'My Activity',
        href: "/activity",
        icon: ScrollText,
    },
    {
        title: 'Inbox',
        href: '/inbox',
        icon: Inbox,   // from @lucide/vue
    }
];

const footerNavItems: NavItem[] = [
    // {
    //     title: 'Documentation',
    //     href: 'https://laravel.com/docs/starter-kits#vue',
    //     icon: BookOpen,
    // },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
