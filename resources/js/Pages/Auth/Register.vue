<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    inviteRequired: Boolean,
    trialDays: Number,
});

const form = useForm({
    organization: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    currency: 'USD',
    terms: false,
    invite_code: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Create your organization" />

        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Create your organization</h2>
        <p class="mb-6 mt-1 text-sm text-slate-500">
            Start your {{ trialDays }}-day free trial — books, invoicing, multi-currency, and ZIMRA fiscalisation in one system.
        </p>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="organization" value="Business name" />
                <TextInput
                    id="organization"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.organization"
                    placeholder="e.g. Acme Trading (Pvt) Ltd"
                    required
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.organization" />
            </div>

            <div class="mt-4">
                <InputLabel for="name" value="Your full name" />
                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <InputLabel for="password" value="Password" />
                    <PasswordInput id="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>
                <div>
                    <InputLabel for="password_confirmation" value="Confirm password" />
                    <PasswordInput id="password_confirmation" class="mt-1 block w-full" v-model="form.password_confirmation" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <div class="mt-4">
                <InputLabel for="currency" value="Base currency" />
                <select id="currency" v-model="form.currency" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="USD">USD — United States Dollar</option>
                    <option value="ZWG">ZWG — Zimbabwe Gold</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">
                    The currency your financial statements are prepared in — usually USD. You can still trade and invoice in both.
                </p>
                <InputError class="mt-2" :message="form.errors.currency" />
            </div>

            <div v-if="inviteRequired" class="mt-4">
                <InputLabel for="invite_code" value="Invite code" />
                <TextInput id="invite_code" type="text" class="mt-1 block w-full" v-model="form.invite_code" required />
                <InputError class="mt-2" :message="form.errors.invite_code" />
            </div>

            <div class="mt-4">
                <label class="flex items-start gap-2">
                    <Checkbox name="terms" v-model:checked="form.terms" class="mt-0.5" />
                    <span class="text-sm text-gray-600">I agree to the terms of service and privacy policy.</span>
                </label>
                <InputError class="mt-2" :message="form.errors.terms" />
            </div>

            <div class="mt-6 flex items-center justify-between">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                >
                    Already have an account?
                </Link>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Create organization
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
