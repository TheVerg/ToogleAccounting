<script setup>
import { computed, ref, useSlots } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({
    hideCompanySelector: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const slots = useSlots();
const user = computed(() => page.props.auth.user);
const companies = computed(() => page.props.auth.companies ?? []);
const currentCompany = computed(() => page.props.auth.currentCompany);
const flash = computed(() => page.props.flash ?? {});
const sidebarOpen = ref(false);
const userMenuOpen = ref(false);
const dismissed = ref({ success: false, error: false });

const canDo = (permission) =>
    user.value?.is_system_admin || user.value?.permissions?.includes(permission);

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

// Heroicons (outline, 24) path data.
const icons = {
    dashboard: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
    invoices: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    payments: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
    expenses: 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
    recurring: 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
    equity: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    intercompany: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
    employees: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    payroll: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
    journal: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
    statements: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    budgets: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M12 21a9 9 0 100-18 9 9 0 000 18z',
    accounts: 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z',
    contacts: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    taxes: 'M9 14.25l6-6m4.5 6.75a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9h.008v.008H9.75V9zm4.5 5.25h.008v.008h-.008v-.008z',
    companies: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    users: 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
    roles: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    audit: 'M9 12h6m-6 4h6m2.25 5H6.75A2.25 2.25 0 014.5 18.75V5.25A2.25 2.25 0 016.75 3h6.879c.597 0 1.169.237 1.591.659l2.621 2.621c.422.422.659.994.659 1.591v10.879A2.25 2.25 0 0117.25 21zM13.5 3.75V7.5c0 .414.336.75.75.75H18',
    benchmarks: 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75',
    queries: 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
};

const nav = computed(() => [
    {
        label: 'Transactions',
        items: [
            { label: 'Revenue', href: '/income/invoices', icon: 'invoices', show: canDo('invoices.view') },
            { label: 'Payments', href: '/payments', icon: 'payments', show: canDo('payments.view') },
            { label: 'Expenses', href: '/expenses', icon: 'expenses', show: canDo('expenses.view') },
            { label: 'Equity', href: '/equity', icon: 'equity', show: canDo('equity.view') },
            { label: 'Shareholder Loans', href: '/shareholder-loans', icon: 'payments', show: canDo('equity.view') },
            { label: 'Intercompany', href: '/intercompany', icon: 'intercompany', show: canDo('intercompany.manage') },
            { label: 'Journal', href: '/journal', icon: 'journal', show: canDo('journals.view') },
        ],
    },
    {
        label: 'Reporting',
        items: [
            { label: 'Financial Statements', href: '/statements/register', icon: 'statements', show: canDo('reports.view') },
            { label: 'General Ledger', href: '/statements/general-ledger', icon: 'journal', show: canDo('reports.view') },
            { label: 'Cash Book', href: '/statements/cash-book', icon: 'payments', show: canDo('reports.view') },
            { label: 'Tax by Currency', href: '/statements/tax-by-currency', icon: 'taxes', show: canDo('reports.view') },
            { label: 'Tax Compliance', href: '/compliance', icon: 'taxes', show: canDo('compliance.view') },
            { label: 'Analysis', href: '/analysis', icon: 'benchmarks', show: canDo('reports.view') },
            { label: 'Budgets', href: '/budgets', icon: 'budgets', show: canDo('budgets.view') },
        ],
    },
    {
        label: 'Human Resources',
        items: [
            { label: 'Employees', href: '/employees', icon: 'employees', show: canDo('payroll.view') },
            { label: 'Payroll', href: '/payroll', icon: 'payroll', show: canDo('payroll.view') },
            { label: 'Employee Loans', href: '/employee-loans', icon: 'equity', show: canDo('payroll.view') },
        ],
    },
    {
        label: 'Master Data',
        items: [
            { label: 'Chart of Accounts', href: '/accounts', icon: 'accounts', show: canDo('accounts.view') },
            { label: 'Opening Balances', href: '/settings/opening-balances', icon: 'journal', show: user.value?.is_system_admin === true },
            { label: 'Fiscalisation', href: '/settings/fiscalisation', icon: 'taxes', show: user.value?.is_system_admin === true },
            { label: 'Contacts', href: '/contacts', icon: 'contacts', show: canDo('contacts.view') },
            { label: 'Shareholders', href: '/shareholders', icon: 'users', show: canDo('equity.view') },
            { label: 'Tax Rates', href: '/taxes/rates', icon: 'taxes', show: canDo('taxes.manage') },
            { label: 'Exchange Rates', href: '/settings/exchange-rates', icon: 'taxes', show: canDo('accounts.view') },
            { label: 'Payroll Rules', href: '/settings/payroll-statutory', icon: 'taxes', show: canDo('payroll.manage') },
            { label: 'Ratio Configuration', href: '/settings/ratio-benchmarks', icon: 'benchmarks', show: canDo('reports.view') },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Companies', href: '/companies', icon: 'companies', show: canDo('companies.view') },
            { label: 'Audit Log', href: '/admin/audit-logs', icon: 'audit', show: canDo('users.manage') },
            { label: 'Users', href: '/admin/users', icon: 'users', show: canDo('users.manage') },
            { label: 'Roles & Permissions', href: '/admin/roles', icon: 'roles', show: canDo('roles.manage') },
            { label: 'Queries', href: '/support/queries', icon: 'queries', show: true },
        ],
    },
    {
        label: 'Platform',
        items: [
            { label: 'Registered Businesses', href: '/platform/businesses', icon: 'companies', show: user.value?.is_platform_admin === true },
            { label: 'Queries Inbox', href: '/platform/queries', icon: 'queries', show: user.value?.is_platform_admin === true },
        ],
    },
]
    .map((group) => ({ ...group, items: group.items.filter((i) => i.show) }))
    .filter((group) => group.items.length));

const isActive = (href) => {
    const path = href.split('?')[0];
    return window.location.pathname === path;
};
const groupActive = (item) => item.children?.some((c) => isActive(c.href));

// Collapsible state, persisted in localStorage so it survives page
// reloads and Inertia navigations. Groups default to open; submenus
// (e.g. Financial Statements) default to open only when active.
const STORAGE_KEY = 'holdco.sidebar';
const stored = (() => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
    } catch {
        return {};
    }
})();

const collapsedGroups = ref(stored.groups ?? {});
const submenuState = ref(stored.submenus ?? {});
const persist = () => localStorage.setItem(STORAGE_KEY, JSON.stringify({
    groups: collapsedGroups.value,
    submenus: submenuState.value,
}));

const toggleGroup = (label) => {
    collapsedGroups.value[label] = !collapsedGroups.value[label];
    persist();
};
const groupOpen = (label) => !collapsedGroups.value[label];

const toggleSubmenu = (label) => {
    submenuState.value[label] = !(submenuState.value[label] ?? false);
    persist();
};
const submenuOpen = (item) => submenuState.value[item.label] ?? groupActive(item);

// Breadcrumb trail derived from the active page's place in the nav
// tree. Crumbs are { label, href? } — intermediate crumbs with an href
// are rendered as links; the last crumb is always plain text.
const breadcrumbs = computed(() => {
    const crumb = (label, href = null) => ({ label, href });

    for (const group of nav.value) {
        for (const item of group.items) {
            if (item.href && isActive(item.href)) {
                return [crumb(group.label), crumb(item.label)];
            }
            for (const child of item.children ?? []) {
                if (isActive(child.href)) {
                    return [crumb(group.label), crumb(item.label), crumb(child.label)];
                }
            }
        }
    }

    // Pages reachable outside the sidebar tree (tabs, detail screens).
    const statements = [crumb('Reporting'), crumb('Financial Statements', '/statements/register')];
    const extra = {
        '/dashboard': [crumb('Dashboard')],
        '/profile': [crumb('My Profile')],
        '/admin/audit-logs': [crumb('Administration'), crumb('Audit Log')],
        '/expenses/recurring': [crumb('Transactions'), crumb('Expenses', '/expenses'), crumb('Recurring')],
        '/equity/dividends': [crumb('Transactions'), crumb('Equity', '/equity'), crumb('Dividends')],
        '/statements/register': statements,
        '/statements/trial-balance': [...statements, crumb('Trial Balance')],
        '/statements/profit-loss': [...statements, crumb('Profit & Loss')],
        '/statements/balance-sheet': [...statements, crumb('Balance Sheet')],
        '/statements/cash-flow': [...statements, crumb('Cash Flow')],
        '/statements/budget-vs-actual': [...statements, crumb('Budget vs Actual')],
        '/statements/tax-summary': [...statements, crumb('Tax Summary')],
        '/statements/ratios': [...statements, crumb('Financial Ratios')],
    };

    const path = window.location.pathname;
    if (extra[path]) {
        return extra[path];
    }

    // Detail pages: derive the trail from the route shape + page props.
    if (/^\/statements\/register\/\d+/.test(path)) {
        return [...statements, crumb('Statement Register', '/statements/register'),
            crumb(page.props.statement?.name ?? 'Statement')];
    }
    if (/^\/analysis\/.+/.test(path)) {
        return [crumb('Reporting'), crumb('Analysis', '/analysis'),
            crumb(page.props.report?.title ?? 'Report')];
    }
    if (/^\/budgets\/\d+/.test(path)) {
        return [crumb('Reporting'), crumb('Budgets', '/budgets'),
            crumb(page.props.budget?.name ?? 'Budget')];
    }
    if (/^\/employees\/\d+/.test(path)) {
        return [crumb('Human Resources'), crumb('Employees', '/employees'),
            crumb(page.props.employee?.full_name ?? 'Employee')];
    }
    if (/^\/payroll\/\d+/.test(path)) {
        return [crumb('Human Resources'), crumb('Payroll', '/payroll'),
            crumb(page.props.run?.reference ?? 'Payroll Run')];
    }
    if (path === '/employee-loans') {
        return [crumb('Human Resources'), crumb('Employee Loans')];
    }

    return [];
});

const currentPath = computed(() => {
    try {
        return new URL(page.url ?? window.location.pathname, window.location.origin).pathname;
    } catch {
        return window.location.pathname;
    }
});

const currentQuery = computed(() => {
    try {
        return new URL(page.url ?? window.location.href, window.location.origin).searchParams;
    } catch {
        return new URLSearchParams(window.location.search);
    }
});

const pageDescription = computed(() => {
    const path = currentPath.value;

    if (path === '/dashboard') {
        return 'A quick overview of company activity, balances, and recent accounting movement.';
    }
    if (path === '/analysis') {
        return 'Explore operational, workforce, group, and management reporting for better business decisions.';
    }
    if (path === '/compliance') {
        return 'Reconcile books to FDMS, prepare VAT working papers, control approvals, and retain filing evidence.';
    }
    if (path === '/compliance/accountant') {
        return 'Prioritise returns, deadlines, and unresolved compliance work across assigned clients.';
    }
    if (path === '/income/invoices') {
        return 'Track revenue records, customer balances, payments received, and export filtered revenue reports.';
    }
    if (path === '/payments') {
        return 'Record cash movements, transfers, and payment activity across your money accounts.';
    }
    if (path === '/expenses') {
        return currentQuery.value.get('petty') === '1'
            ? 'Monitor petty cash spending, float balances, top-ups, and supporting expense records.'
            : 'Capture expenses, attach supporting documents, and review spending by date, type, or status.';
    }
    if (path === '/expenses/recurring') {
        return 'Maintain repeating expense schedules and generate due expenses into the ledger.';
    }
    if (path === '/equity') {
        return "Record capital injections, drawings, share issues, and other equity movements.";
    }
    if (path === '/equity/dividends') {
        return 'Declare dividends split by shareholding and track each shareholder’s payment.';
    }
    if (path === '/shareholder-loans') {
        return 'Track money lent to the company by shareholders, outstanding balances, and repayments.';
    }
    if (path === '/shareholders') {
        return 'Maintain the shareholder register: who holds shares, their ownership percentage, investment, and loans.';
    }
    if (path === '/statements/general-ledger') {
        return 'Browse every posting to an account with running balances for any period.';
    }
    if (path === '/statements/cash-book') {
        return 'Follow receipts and payments through each cash and bank account with running balances.';
    }
    if (path === '/intercompany') {
        return 'Manage funding, loans, and balances between companies in the group.';
    }
    if (path === '/journal') {
        return 'Review posted journal entries, draft adjustments, reversals, and ledger evidence.';
    }
    if (path === '/statements/register') {
        return 'Generate, import, approve, and open frozen financial statement snapshots by period.';
    }
    if (/^\/statements\/register\/\d+/.test(path)) {
        return 'View the selected frozen statement snapshot exactly as it was generated or approved.';
    }
    if (path.startsWith('/statements/')) {
        return 'Open registered financial statements for the selected statement type and period.';
    }
    if (path === '/budgets') {
        return 'Create and review operating budgets for planning, tracking, and comparison.';
    }
    if (/^\/budgets\/\d+/.test(path)) {
        return 'Edit budget lines and review the structure for this budget period.';
    }
    if (path === '/employees') {
        return 'Manage staff records, employment details, salary structure, and linked system access.';
    }
    if (/^\/employees\/\d+/.test(path)) {
        return 'Review employee profile details, contract records, and recurring payroll components.';
    }
    if (path === '/payroll') {
        return 'Prepare payroll runs, review pay periods, and post approved payroll to accounting.';
    }
    if (/^\/payroll\/\d+/.test(path)) {
        return 'Review payroll figures, employee add-ons, deductions, approval status, and ledger posting.';
    }
    if (path === '/employee-loans') {
        return 'Track employee loans, outstanding balances, repayments, and payroll deductions.';
    }
    if (path === '/accounts') {
        return 'Maintain the chart of accounts used for posting, reporting, and statement grouping.';
    }
    if (path === '/contacts') {
        return 'Manage customers, vendors, and shared contacts used across revenue and expenses.';
    }
    if (path === '/taxes/rates') {
        return 'Configure tax rates used when recording revenue, expenses, and tax summaries.';
    }
    if (path === '/settings/exchange-rates') {
        return 'Enter daily exchange rates for multi-currency trading; the latest rate carries forward.';
    }
    if (path === '/settings/fiscalisation') {
        return 'Register this company\'s ZIMRA fiscal device and monitor fiscal days and receipt submissions.';
    }
    if (path === '/statements/tax-by-currency') {
        return 'Tax collected and paid per currency of trade, as ZIMRA expects it declared.';
    }
    if (path === '/settings/ratio-benchmarks') {
        return 'Set benchmark targets and custom ratios for financial ratio reporting.';
    }
    if (path === '/companies') {
        return 'Manage holding and subsidiary companies, currencies, access, and reporting structure.';
    }
    if (path === '/admin/audit-logs') {
        return 'Review system activity, data changes, approvals, and user actions for audit control.';
    }
    if (path === '/settings/opening-balances') {
        return 'One-time cutover entry: bring an existing business onto the system with its real account balances.';
    }
    if (path === '/admin/users') {
        return 'Create users, assign company access, and manage role-based permissions.';
    }
    if (path === '/admin/roles') {
        return 'Configure roles and permissions that control access across the system.';
    }
    if (path === '/profile') {
        return 'Update your account information, password, and profile settings.';
    }

    return '';
});

const switchCompany = (event) => {
    router.post('/switch-company', { company_id: Number(event.target.value) }, { preserveScroll: true });
};
</script>

<template>
    <div class="min-h-screen bg-zinc-100">
        <!-- Mobile backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-black/50 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-brand-950 transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <!-- Brand -->
            <Link href="/dashboard" class="flex h-32 shrink-0 flex-col items-start justify-center border-b border-white/10 px-4">
                <ApplicationLogo size="sidebar" />
                <span class="mt-1 text-xs font-semibold uppercase tracking-[0.22em] text-brand-300">Financial Suite</span>
            </Link>

            <!-- Navigation -->
            <nav class="scrollbar-slim flex-1 overflow-y-auto px-3 pb-6 pt-2">
                <!-- Dashboard: standalone, outside any group -->
                <Link
                    href="/dashboard"
                    class="group mb-1 mt-2 flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                    :class="isActive('/dashboard')
                        ? 'bg-brand-800 text-white'
                        : 'text-brand-200/90 hover:bg-white/5 hover:text-white'"
                >
                    <svg class="h-[18px] w-[18px] shrink-0" :class="isActive('/dashboard') ? 'text-brand-300' : 'text-brand-400 group-hover:text-brand-300'" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.dashboard" />
                    </svg>
                    Dashboard
                </Link>

                <div v-for="group in nav" :key="group.label">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between rounded-md px-3 pb-1 pt-5 text-left text-[11px] font-semibold uppercase tracking-widest text-brand-400 hover:text-brand-200"
                        @click="toggleGroup(group.label)"
                    >
                        {{ group.label }}
                        <svg
                            class="h-3.5 w-3.5 transition-transform"
                            :class="groupOpen(group.label) ? '' : '-rotate-90'"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        ><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>

                    <div v-show="groupOpen(group.label)">
                        <template v-for="item in group.items" :key="item.label">
                            <Link
                                v-if="!item.children"
                                :href="item.href"
                                class="group mb-0.5 flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors"
                                :class="isActive(item.href)
                                    ? 'bg-brand-800 text-white'
                                    : 'text-brand-200/90 hover:bg-white/5 hover:text-white'"
                            >
                                <svg class="h-[18px] w-[18px] shrink-0" :class="isActive(item.href) ? 'text-brand-300' : 'text-brand-400 group-hover:text-brand-300'" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                                </svg>
                                {{ item.label }}
                            </Link>
                            <div v-else>
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm font-medium transition-colors"
                                    :class="groupActive(item) ? 'text-white' : 'text-brand-200/90 hover:bg-white/5 hover:text-white'"
                                    @click="toggleSubmenu(item.label)"
                                >
                                    <span class="flex items-center gap-3">
                                        <svg class="h-[18px] w-[18px] shrink-0 text-brand-400" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                                        </svg>
                                        {{ item.label }}
                                    </span>
                                    <svg
                                        class="h-3.5 w-3.5 text-brand-400 transition-transform"
                                        :class="submenuOpen(item) ? '' : '-rotate-90'"
                                        fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                                    ><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                                <div v-show="submenuOpen(item)" class="mb-1 ml-[21px] border-l border-white/10 pl-3">
                                    <Link
                                        v-for="child in item.children"
                                        :key="child.label"
                                        :href="child.href"
                                        class="block rounded-md px-3 py-1.5 text-[13px] transition-colors"
                                        :class="isActive(child.href)
                                            ? 'bg-brand-800 font-medium text-white'
                                            : 'text-brand-300 hover:bg-white/5 hover:text-white'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Main column -->
        <div class="flex min-h-screen flex-col lg:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between border-b border-zinc-200 bg-white px-4 shadow-sm sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <button class="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = !sidebarOpen">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>

                    <!-- Breadcrumbs -->
                    <nav class="flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                        <Link href="/dashboard" class="shrink-0 text-slate-400 hover:text-slate-600" title="Dashboard">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons.dashboard" />
                            </svg>
                        </Link>
                        <template v-for="(crumb, i) in breadcrumbs" :key="crumb.label + i">
                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            <Link
                                v-if="crumb.href && i !== breadcrumbs.length - 1"
                                :href="crumb.href"
                                class="truncate text-slate-400 hover:text-slate-600 hover:underline"
                            >{{ crumb.label }}</Link>
                            <span
                                v-else
                                class="truncate"
                                :class="i === breadcrumbs.length - 1 ? 'font-medium text-slate-800' : 'text-slate-400'"
                            >{{ crumb.label }}</span>
                        </template>
                    </nav>
                </div>

                <!-- User menu -->
                <div class="relative shrink-0">
                    <button
                        type="button"
                        class="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-slate-100"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-black text-xs font-semibold text-white shadow-sm">
                            {{ initials }}
                        </span>
                        <span class="hidden text-left leading-tight md:block">
                            <span class="block text-sm font-medium text-slate-800">{{ user?.name }}</span>
                            <span class="block text-xs text-slate-400">{{ user?.email }}</span>
                        </span>
                        <svg class="hidden h-4 w-4 text-slate-400 transition-transform md:block" :class="userMenuOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div v-if="userMenuOpen" class="fixed inset-0 z-30" @click="userMenuOpen = false" />
                    <div
                        v-if="userMenuOpen"
                        class="absolute right-0 z-40 mt-2 w-60 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg"
                    >
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="text-sm font-medium text-slate-800">{{ user?.name }}</p>
                            <p class="truncate text-xs text-slate-400">{{ user?.email }}</p>
                        </div>
                        <Link
                            href="/profile"
                            class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900"
                            @click="userMenuOpen = false"
                        >
                            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            Profile
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-3 border-t border-slate-100 px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5A2.25 2.25 0 003.75 5.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                            </svg>
                            Sign out
                        </Link>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <main class="flex-1">
                <div class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
                    <div v-if="flash.success && !dismissed.success" class="mb-5 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span class="flex-1">{{ flash.success }}</span>
                        <button class="shrink-0 rounded p-0.5 text-green-600 hover:bg-green-100" title="Dismiss" @click="dismissed.success = true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div v-if="flash.error && !dismissed.error" class="mb-5 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        <span class="flex-1">{{ flash.error }}</span>
                        <button class="shrink-0 rounded p-0.5 text-red-600 hover:bg-red-100" title="Dismiss" @click="dismissed.error = true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h1 class="text-2xl font-semibold tracking-tight text-slate-900"><slot name="title" /></h1>
                            <p v-if="slots.description || pageDescription" class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                                <slot name="description">{{ pageDescription }}</slot>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <!-- Company context filter -->
                            <div v-if="!hideCompanySelector && companies.length" class="flex items-center gap-2">
                                <span class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Company</span>
                                <select
                                    class="rounded-md border-slate-300 bg-white py-1.5 pr-8 text-sm font-medium text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500"
                                    :value="currentCompany?.id"
                                    @change="switchCompany"
                                >
                                    <option v-for="c in companies" :key="c.id" :value="c.id">
                                        {{ c.name }}{{ c.type === 'holding' ? ' (Holding)' : '' }}
                                    </option>
                                </select>
                            </div>
                            <slot name="actions" />
                        </div>
                    </div>

                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
