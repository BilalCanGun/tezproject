<script setup>
defineProps({
    warnings: { type: Array, default: () => [] },
});
</script>

<template>
    <section v-if="warnings.length" class="card mb-6 border-amber-300 p-4">
        <h2 class="card-title mb-1">Görev tasarımı uyarıları</h2>
        <p class="mb-2 text-xs text-stone-500">Bu uyarılar protokolün dondurulmasını engellemez; veri toplamadan önce gözden geçirin.</p>
        <ul class="list-disc space-y-1 pl-5 text-sm text-amber-900">
            <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
        </ul>
    </section>
</template>
