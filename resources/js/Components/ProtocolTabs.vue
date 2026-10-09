<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    protocol: { type: Object, required: true },
});

const tabs = [
    { label: 'Genel ve YZ ayarları', route: 'admin.protocols.edit', match: 'admin.protocols.edit' },
    { label: 'Görev bataryası', route: 'admin.protocols.tasks.index', match: 'admin.protocols.tasks.*' },
    { label: 'Standart kaynak', route: 'admin.protocols.resources.index', match: 'admin.protocols.resources.*' },
    { label: 'Açıklama rubriği', route: 'admin.protocols.rubric.index', match: 'admin.protocols.rubric.*' },
    { label: 'Atama listesi', route: 'admin.protocols.allocation.show', match: 'admin.protocols.allocation.*' },
];
</script>

<template>
    <nav class="mb-5 flex flex-wrap gap-1 border-b border-stone-200">
        <Link
            v-for="tab in tabs"
            :key="tab.route"
            :href="route(tab.route, props.protocol.id)"
            class="-mb-px border-b-2 px-3 py-2 text-sm"
            :class="route().current(tab.match) ? 'border-accent font-medium text-accent-strong' : 'border-transparent text-stone-600 hover:text-ink'"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
