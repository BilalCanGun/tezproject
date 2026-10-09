<script setup>
import { conditionStyles } from '@/lib/format';

defineProps({
    condition: { type: String, default: null },
    label: { type: String, default: '' },
});
</script>

<template>
    <span v-if="condition" class="badge" :class="conditionStyles[condition]">{{ label }}</span>
    <span v-else class="text-xs text-stone-400">Atanmadı</span>
</template>
