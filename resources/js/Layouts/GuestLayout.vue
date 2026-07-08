<script setup>
</script>

<template>
    <div class="flex min-h-screen bg-slate-100">
        <!-- Brand panel -->
        <div class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-brand-950 p-12 lg:flex">
            <div class="pointer-events-none absolute -right-40 -top-40 h-96 w-96 rounded-full bg-brand-700/30 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-40 -left-20 h-96 w-96 rounded-full bg-brand-800/40 blur-3xl" />

            <div class="relative flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-base font-bold text-white shadow-lg">
                    H
                </span>
                <span class="leading-tight">
                    <span class="block text-lg font-semibold tracking-wide text-white">HOLDCO</span>
                    <span class="block text-xs font-medium uppercase tracking-widest text-brand-300">Financial Suite</span>
                </span>
            </div>

            <div class="relative">
                <h1 class="max-w-md text-3xl font-semibold leading-tight tracking-tight text-white">
                    Group accounting for the whole holding structure.
                </h1>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-brand-300">
                    Double-entry ledgers, consolidated statements, intercompany
                    eliminations and per-company access control — in one place.
                </p>
            </div>

            <p class="relative text-xs text-brand-400">
                &copy; {{ new Date().getFullYear() }} HoldCo Books. Authorized personnel only.
            </p>
        </div>

        <!-- Form panel -->
        <div class="flex w-full flex-col items-center justify-center px-6 py-12 lg:w-1/2">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-base font-bold text-white shadow-lg">
                    H
                </span>
                <span class="leading-tight">
                    <span class="block text-lg font-semibold tracking-wide text-slate-900">HOLDCO</span>
                    <span class="block text-xs font-medium uppercase tracking-widest text-slate-400">Financial Suite</span>
                </span>
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
