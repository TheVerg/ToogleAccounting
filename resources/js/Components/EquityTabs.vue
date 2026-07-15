<script setup>
import TabBar from '@/Components/TabBar.vue';

defineProps({ active: String });

const tabs = [
    { key: 'transactions', label: 'Equity Transactions', href: '/equity' },
    { key: 'dividends', label: 'Dividends', href: '/equity/dividends' },
];
</script>

<template>
    <TabBar :tabs="tabs" :model-value="active" />
</template>
