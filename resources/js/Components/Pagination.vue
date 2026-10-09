<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: { type: Object, required: true },
});
</script>

<template>
    <div v-if="paginator.last_page > 1" class="flex items-center justify-between gap-3 px-3 py-2 text-xs text-stone-600">
        <span>{{ paginator.from }}–{{ paginator.to }} / {{ paginator.total }}</span>
        <div class="flex gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded border px-2 py-1"
                    :class="link.active ? 'border-accent bg-accent text-white' : 'border-stone-200 bg-white hover:bg-stone-50'"
                    v-html="link.label"
                />
                <span v-else class="rounded border border-stone-100 px-2 py-1 text-stone-300" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
