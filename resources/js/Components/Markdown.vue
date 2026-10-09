<script setup>
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

const props = defineProps({
    source: { type: String, default: '' },
    variant: { type: String, default: 'lab' },
});

const html = computed(() => renderMarkdown(props.source));
</script>

<template>
    <div :class="variant === 'kiosk' ? 'prose-lab prose-kiosk' : 'prose-lab'" v-html="html" />
</template>
