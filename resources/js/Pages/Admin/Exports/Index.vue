<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    protocols: Array,
    datasets: Object,
});
</script>

<template>
    <Head title="Veri dışa aktarımı" />

    <AppLayout>
        <PageHeader title="Veri dışa aktarımı" subtitle="CSV dosyaları yalnızca katılımcı koduyla anahtarlanır; iletişim bilgileri hiçbir dosyaya girmez. Serbest metinler (YZ konuşmaları, olay içerikleri) otomatik anonimleştirilir; paylaşım öncesi ayrıca elle kontrol edilmelidir. Her indirme işlem kaydına yazılır." />

        <div class="space-y-4">
            <section v-for="protocol in protocols" :key="protocol.id" class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title"><span class="code">{{ protocol.code }}</span> · {{ protocol.name }}</h2>
                        <p class="text-xs text-stone-500">{{ protocol.phase_label }} · {{ protocol.participants_count }} katılımcı<template v-if="!protocol.is_frozen"> · dondurulmamış</template></p>
                    </div>
                </div>
                <ul class="divide-y divide-stone-100">
                    <li v-for="(dataset, key) in datasets" :key="key" class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ dataset.label }}</p>
                            <p class="text-xs text-stone-500">{{ dataset.description }}</p>
                        </div>
                        <a :href="route('admin.exports.show', [protocol.id, key])" class="btn btn-secondary btn-sm">CSV indir</a>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
