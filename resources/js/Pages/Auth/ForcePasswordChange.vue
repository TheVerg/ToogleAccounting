<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const requirements = computed(() => [
    { label: 'At least 8 characters', met: form.password.length >= 8 },
    { label: 'One uppercase letter', met: /[A-Z]/.test(form.password) },
    { label: 'One lowercase letter', met: /[a-z]/.test(form.password) },
    { label: 'One number', met: /[0-9]/.test(form.password) },
    { label: 'One special character', met: /[^A-Za-z0-9]/.test(form.password) },
    { label: 'Different from temporary password', met: !!form.password && form.password !== form.current_password },
]);

const passwordsMatch = computed(() =>
    !!form.password_confirmation && form.password === form.password_confirmation,
);
const canSubmit = computed(() =>
    !!form.current_password
    && requirements.value.every((requirement) => requirement.met)
    && passwordsMatch.value
    && !form.processing,
);

const submit = () => {
    if (!canSubmit.value) return;

    form.put(route('password.force.update'), {
        onError: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Change your temporary password" />

        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-900">Choose your permanent password</h1>
            <p class="mt-2 text-sm text-gray-600">
                Your temporary password can only be used to reach this page. Replace it before continuing.
            </p>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="current_password" value="Temporary password" />
                <PasswordInput
                    id="current_password"
                    v-model="form.current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    autofocus
                    required
                />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div>
                <InputLabel for="password" value="New password" />
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    required
                />
                <InputError class="mt-2" :message="form.errors.password" />
                <ul class="mt-3 grid gap-1 text-sm sm:grid-cols-2">
                    <li
                        v-for="requirement in requirements"
                        :key="requirement.label"
                        :class="requirement.met ? 'text-green-700' : 'text-gray-500'"
                    >
                        <span aria-hidden="true">{{ requirement.met ? '✓' : '○' }}</span>
                        {{ requirement.label }}
                    </li>
                </ul>
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm new password" />
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    required
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
                <p
                    v-if="form.password_confirmation"
                    class="mt-2 text-sm"
                    :class="passwordsMatch ? 'text-green-700' : 'text-red-600'"
                >
                    {{ passwordsMatch ? '✓ Passwords match' : 'Passwords do not match' }}
                </p>
            </div>

            <PrimaryButton :disabled="!canSubmit">Change password and continue</PrimaryButton>
        </form>
    </GuestLayout>
</template>
