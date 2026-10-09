<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    protocol: Object,
    protocols: Array,
    routeName: { type: String, required: true },
});

function change(event) {
    router.get(route(props.routeName), { protocol: event.target.value }, { preserveState: false });
}
</script>

<template>
    <select :value="protocol?.id" class="input w-auto" @change="change">
        <option v-for="p in protocols" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
    </select>
</template>
