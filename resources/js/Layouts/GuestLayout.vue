<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
</script>

<template>
    <div class="flex min-h-screen bg-zinc-100">
        <!-- Brand panel -->
        <div class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-brand-950 p-12 lg:flex">
            <div class="pointer-events-none absolute -right-40 -top-40 h-96 w-96 rounded-full bg-white/10 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-40 -left-20 h-96 w-96 rounded-full bg-zinc-700/30 blur-3xl" />

            <div class="relative" />

            <div class="relative">
                <div class="mb-8">
                    <ApplicationLogo size="hero" />
                </div>
                <h1 class="max-w-md text-3xl font-semibold leading-tight tracking-tight text-white">
                    Group accounting for the whole holding structure.
                </h1>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-brand-300">
                    Double-entry ledgers, consolidated statements, intercompany
                    eliminations and per-company access control — in one place.
                </p>
            </div>

            <p class="relative text-xs text-brand-400">
                &copy; {{ new Date().getFullYear() }} Toggle. Authorized personnel only.
            </p>
        </div>

        <!-- Form panel -->
        <div class="flex w-full flex-col items-center justify-center px-6 py-12 lg:w-1/2">
            <div class="mb-8 flex items-center lg:hidden">
                <ApplicationLogo size="lg" />
            </div>

            <div class="mb-5 w-full max-w-md">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Financial Suite</p>
            </div>

            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
                <slot />
            </div>

            <p class="mt-6 text-xs text-slate-400">
                Access is provisioned by your system administrator.
            </p>
        </div>
    </div>
</template>
