import { usePage } from '@inertiajs/vue3';

export function can(permission) {
    const permissions = usePage().props.auth.user?.permissions ?? [];
    return permissions.includes(permission);
}

export function money(value, currency) {
    const cur = currency ?? usePage().props.auth.currentCompany?.currency ?? 'USD';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: cur,
        minimumFractionDigits: 2,
    }).format(Number(value ?? 0));
}

export function fmtDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
