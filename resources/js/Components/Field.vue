<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    for: { type: String, default: undefined },
});
</script>

<template>
    <div>
        <label v-if="label" :for="$props.for" class="label">{{ label }}</label>
        <slot />
        <p v-if="error" class="mt-1 text-xs text-red-700">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-stone-500">{{ hint }}</p>
    </div>
</template>
