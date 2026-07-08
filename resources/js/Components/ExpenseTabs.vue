<script setup>
import TabBar from '@/Components/TabBar.vue';

defineProps({ active: String });

const tabs = [
    { key: 'all', label: 'All Expenses', href: '/expenses' },
    { key: 'petty', label: 'Petty Cash', href: '/expenses?petty=1' },
    { key: 'recurring', label: 'Recurring', href: '/expenses/recurring' },
];
</script>

<template>
    <TabBar :tabs="tabs" :model-value="active" />
</template>
