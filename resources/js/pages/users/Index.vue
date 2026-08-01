<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
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
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import DeleteUser from '@/components/DeleteUser.vue';

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    position: string,
    division: string,
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    users: Paginated<UserRow>;
    roles: string[];
    filters: { search: string; role: string };
}>();

const page = usePage();
const currentUserId = computed(() => (page.props as any).auth?.user?.id);

/* ------------------------------------------------------------------ */
/* Filters                                                             */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.search ?? '');
const roleFilter = ref(props.filters.role || 'all');

let searchTimeout: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
});

watch(roleFilter, applyFilters);

function applyFilters() {
    router.get(
        '/users',
        {
            search: search.value || undefined,
            role: roleFilter.value === 'all' ? undefined : roleFilter.value,
        },
        { preserveState: true, replace: true },
    );
}

/* ------------------------------------------------------------------ */
/* Create / edit dialog                                                */
/* ------------------------------------------------------------------ */

const dialogOpen = ref(false);
const editingUser = ref<UserRow | null>(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    roles: [] as string[],
});

function openCreate() {
    editingUser.value = null;
    form.reset();
    form.roles = ['user'];  
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(user: UserRow) {
    editingUser.value = user;
    form.reset();
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.roles = [...user.roles];
    dialogOpen.value = true;
}
function toggleRole(role: string, checked: boolean) {
    if (checked) {
        form.roles = [...form.roles, role];
    } else {
        form.roles = form.roles.filter((r) => r !== role);
    }
}

function submit() {
    if (editingUser.value) {
        form.put(`/users/${editingUser.value.id}`, {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
    } else {
        form.post('/users', {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
    }
}

/* ------------------------------------------------------------------ */
/* Delete confirmation                                                 */
/* ------------------------------------------------------------------ */

const deletingUser = ref<UserRow | null>(null);
const deleteDialogOpen = ref(false);

function confirmDelete(user: UserRow) {
    deletingUser.value = user;
    deleteDialogOpen.value = true;
}


function destroy() {

    if (!deletingUser.value) return;
    


    router.delete(`/users/${deletingUser.value.id}`, {
        preserveScroll: true,
        onFinish: () => (deletingUser.value = null),
    });

    deleteDialogOpen.value = false;
}
</script>

<template>
    <div class="mx-auto flex flex-col gap-6 p-6 w-full">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
                <p class="text-sm text-muted-foreground">
                    Manage accounts and their roles.
                </p>
            </div>
            <Button @click="openCreate">Add user</Button>
        </div>

        <!-- Filters -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Input
                v-model="search"
                placeholder="Search by name or email..."
                class="sm:max-w-xs"
            />
            <Select v-model="roleFilter">
                <SelectTrigger class="sm:w-44">
                    <SelectValue placeholder="All roles" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All roles</SelectItem>
                    <SelectItem v-for="role in roles" :key="role" :value="role">
                        {{ role }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <!-- Table -->
        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Division</TableHead>
                        <TableHead>Position</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Created</TableHead>
                        <TableHead class="w-[140px] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell class="font-medium">
                            {{ user.name }}
                            <span
                                v-if="user.id === currentUserId"
                                class="ml-1 text-xs text-muted-foreground"
                                >(you)</span
                            >
                        </TableCell>
                        <TableCell>{{ user.email }}</TableCell>
                        <TableCell>{{ user.division }}</TableCell>
                        <TableCell>{{ user.position }}</TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="roleName in user.roles"
                                    :key="roleName"
                                    :variant="roleName === 'admin' ? 'default' : 'secondary'"
                                >
                                    {{ roleName }}
                                </Badge>
                                <span v-if="user.roles.length === 0" class="text-xs text-muted-foreground">—</span>
                            </div>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ user.created_at }}
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="openEdit(user)"
                                >
                                    Edit
                                </Button>
                                <Button
                                    v-if="user.id !== currentUserId"
                                    variant="destructive"
                                    size="sm"
                                    @click="confirmDelete(user)"
                                >
                                    Delete
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>

                    <TableRow v-if="users.data.length === 0">
                        <TableCell
                            colspan="5"
                            class="h-24 text-center text-muted-foreground"
                        >
                            No users found. Adjust your search or add a new user.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <!-- Pagination -->
        <div
            v-if="users.total > 0"
            class="flex items-center justify-between text-sm text-muted-foreground"
        >
            <span>Showing {{ users.from }}–{{ users.to }} of {{ users.total }}</span>
            <div class="flex gap-1">
                <Button
                    v-for="(link, i) in users.links"
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
    </div>

    <!-- Create / edit dialog -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{ editingUser ? 'Edit user' : 'Add user' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        editingUser
                            ? 'Update the account details. Leave password blank to keep it unchanged.'
                            : 'Create a new account and assign a role.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" autocomplete="off" />
                    <p v-if="form.errors.name" class="text-sm text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                    />
                    <p v-if="form.errors.email" class="text-sm text-destructive">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="password">
                        Password
                        <span
                            v-if="editingUser"
                            class="font-normal text-muted-foreground"
                        >
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <p v-if="form.errors.password" class="text-sm text-destructive">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Roles</Label>
                    <div class="flex flex-col gap-2">
                        <label
                            v-for="roleName in roles"
                            :key="roleName"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="form.roles.includes(roleName)"
                                @update:model-value="(checked) => toggleRole(roleName, !!checked)"
                            />
                            {{ roleName }}
                        </label>
                    </div>
                    <p v-if="form.errors.roles" class="text-sm text-destructive">
                        {{ form.errors.roles }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ editingUser ? 'Save changes' : 'Create user' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Delete confirmation -->
    <AlertDialog
        v-model:open="deleteDialogOpen"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete this user?</AlertDialogTitle>
                <AlertDialogDescription>
                    This will permanently delete
                    <strong>{{ deletingUser?.name }}</strong> ({{
                        deletingUser?.email
                    }}). This action cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction variant="destructive" @click="destroy">
                    Delete user
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>