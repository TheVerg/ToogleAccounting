<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ roles: Array, allPermissions: Array });

const showModal = ref(false);
const editing = ref(null);

const form = useForm({ name: '', permissions: [] });

// Group permissions by module prefix for a tidy matrix.
const grouped = computed(() => {
    const groups = {};
    props.allPermissions.forEach((p) => {
        const [module] = p.split('.');
        (groups[module] ??= []).push(p);
    });
    return groups;
});

const openCreate = () => { editing.value = null; form.reset(); showModal.value = true; };
const openEdit = (r) => {
    editing.value = r;
    form.name = r.name;
    form.permissions = [...r.permissions];
    showModal.value = true;
};

const toggleModule = (perms) => {
    const allOn = perms.every((p) => form.permissions.includes(p));
    form.permissions = allOn
        ? form.permissions.filter((p) => !perms.includes(p))
        : [...new Set([...form.permissions, ...perms])];
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(`/admin/roles/${editing.value.id}`, options);
    else form.post('/admin/roles', options);
};

const destroy = (r) => {
    if (confirm(`Delete role ${r.name}?`)) router.delete(`/admin/roles/${r.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Permissions" />
    <AppLayout>
        <template #title>Administration · Permissions</template>
        <template #actions>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Role
            </button>
        </template>

        <p class="mb-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            Roles bundle permissions; users receive roles in the Users screen.
            Every module route is gated by these permissions. The <strong>system-admin</strong> role is protected and always has everything.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <div v-for="r in roles" :key="r.id" class="rounded-lg bg-white p-5 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-800">
                        {{ r.name }}
                        <span v-if="r.is_protected" class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase text-gray-500">protected</span>
                    </h2>
                    <div v-if="!r.is_protected" class="flex gap-3">
                        <button class="text-sm text-brand-600 hover:underline" @click="openEdit(r)">Edit</button>
                        <button class="text-sm text-red-600 hover:underline" @click="destroy(r)">Delete</button>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1">
                    <span v-for="p in r.permissions" :key="p" class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ p }}</span>
                    <span v-if="!r.permissions.length" class="text-sm text-gray-400">No permissions.</span>
                </div>
            </div>
        </div>

        <Modal :show="showModal" max-width="2xl" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? `Edit role: ${editing.name}` : 'New role' }}</h2>
                <div class="mb-4">
                    <label class="mb-1 block text-sm text-gray-600">Role name</label>
                    <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div class="max-h-96 space-y-3 overflow-y-auto rounded-md border border-gray-200 p-4">
                    <div v-for="(perms, module) in grouped" :key="module">
                        <button type="button" class="mb-1 text-xs font-bold uppercase tracking-wide text-brand-600 hover:underline" @click="toggleModule(perms)">
                            {{ module }} (toggle all)
                        </button>
                        <div class="grid grid-cols-2 gap-1 sm:grid-cols-3">
                            <label v-for="p in perms" :key="p" class="flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="form.permissions" type="checkbox" :value="p" class="rounded border-gray-300 text-brand-600" />
                                {{ p.split('.')[1] }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Save role</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
