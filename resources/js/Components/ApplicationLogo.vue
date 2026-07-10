<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    size: {
        type: String,
        default: 'md',
    },
});

const logoSources = ['/logo.png', '/logo.svg'];
const sourceIndex = ref(0);
const logoFailed = ref(false);

const handleLogoError = () => {
    if (sourceIndex.value < logoSources.length - 1) {
        sourceIndex.value += 1;
        return;
    }

    logoFailed.value = true;
};

const sizes = {
    md: 'h-14 w-44 text-lg',
    sidebar: 'h-20 w-56 text-lg',
    lg: 'h-28 w-96 text-xl',
    hero: 'h-64 w-[34rem] max-w-full text-xl',
};

const imageClass = computed(() =>
    ['hero', 'sidebar'].includes(props.size) ? 'h-full w-full object-cover' : 'h-full w-full object-contain',
);
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden font-bold text-white"
        :class="sizes[size] ?? sizes.md"
    >
        <img
            v-if="!logoFailed"
            :src="logoSources[sourceIndex]"
            alt="Horizon Equity Group logo"
            :class="imageClass"
            @error="handleLogoError"
        />
        <span v-else class="flex h-14 w-14 items-center justify-center rounded-xl bg-black">H</span>
    </span>
</template>
