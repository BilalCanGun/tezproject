<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Markdown from '@/Components/Markdown.vue';

defineProps({
    config: { type: Object, required: true },
});

const emit = defineEmits(['done']);

/* A short lock keeps participants from clicking through without reading. */
const locked = ref(true);
let timer;

onMounted(() => { timer = setTimeout(() => { locked.value = false; }, 4000); });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="mx-auto flex h-full w-full max-w-2xl flex-col justify-center px-6 py-10">
        <h2 class="mb-4 text-xl font-medium">{{ config.title }}</h2>
        <Markdown :source="config.body" variant="kiosk" />
        <div class="mt-8 flex justify-end">
            <button type="button" class="rounded border border-kiosk-text px-4 py-2 text-sm disabled:opacity-40" :disabled="locked" @click="emit('done')">Devam</button>
        </div>
    </div>
</template>
