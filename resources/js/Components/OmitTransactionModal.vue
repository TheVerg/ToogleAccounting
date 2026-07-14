<script setup>
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    show: Boolean,
    url: String,
    label: String,
});

const emit = defineEmits(['close']);

const form = useForm({ reason: '' });

watch(() => props.show, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
    }
});

const submit = () => {
    form.delete(props.url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="p-6" @submit.prevent="submit">
            <h2 class="text-lg font-semibold text-red-700">Omit transaction permanently?</h2>
            <p class="mt-3 text-sm text-slate-600">
                {{ label }} will be deleted from its source area and from the journal. This changes the ledger and every statement generated from it. The action is restricted to system administrators and is audit logged.
            </p>
            <div class="mt-4">
                <label class="mb-1 block text-sm font-medium text-slate-700">Reason for omission</label>
                <textarea v-model="form.reason" rows="3" minlength="10" maxlength="500" required class="w-full rounded-md border-gray-300 text-sm" placeholder="Explain the date, value, or other error"></textarea>
                <p v-if="form.errors.reason" class="mt-1 text-xs text-red-600">{{ form.errors.reason }}</p>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm" @click="emit('close')">Cancel</button>
                <button type="submit" :disabled="form.processing" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">Permanently omit</button>
            </div>
        </form>
    </Modal>
</template>
