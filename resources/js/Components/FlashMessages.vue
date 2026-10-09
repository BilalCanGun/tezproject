<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const dismissed = ref(false);
const flash = computed(() => page.props.flash || {});

watch(() => [flash.value.success, flash.value.error], () => {
    dismissed.value = false;
});
</script>

<template>
    <div v-if="!dismissed && (flash.success || flash.error)" class="mb-4 flex items-start gap-3 rounded border px-3 py-2 text-sm" :class="flash.error ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900'">
        <p class="flex-1">{{ flash.error || flash.success }}</p>
        <button type="button" class="text-xs opacity-70 hover:opacity-100" @click="dismissed = true">Kapat</button>
    </div>
</template>
