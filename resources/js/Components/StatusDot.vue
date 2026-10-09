<script setup>
defineProps({
    state: { type: String, default: 'idle' },
});

const colors = {
    ok: 'bg-emerald-500',
    pass: 'bg-emerald-500',
    warn: 'bg-amber-500',
    fail: 'bg-red-600',
    no_data: 'bg-stone-300',
    idle: 'bg-stone-300',
};
</script>

<template>
    <span class="inline-block size-2 shrink-0 rounded-full" :class="colors[state] || colors.idle" />
</template>
