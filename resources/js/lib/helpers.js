import { usePage } from '@inertiajs/vue3';

export function can(permission) {
    const user = usePage().props.auth.user;

    // Mirrors the backend's Gate::before: system admins can do everything
    // in every company, regardless of per-company role assignments.
    if (user?.is_system_admin === true) {
        return true;
    }

    return (user?.permissions ?? []).includes(permission);
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
