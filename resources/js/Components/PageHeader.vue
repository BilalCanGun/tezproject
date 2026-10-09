<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div v-if="$slots.breadcrumb" class="mb-1 text-xs text-stone-500"><slot name="breadcrumb" /></div>
            <h1 class="text-xl font-semibold">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 max-w-3xl text-sm text-stone-600">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
