<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolPicker from '@/Components/ProtocolPicker.vue';
import { formatNumber, formatPercent } from '@/lib/format';

defineProps({
    protocol: Object,
    protocols: Array,
    summary: Object,
    primaryDescriptives: Array,
});

const conditionLabels = { direct_ai: 'Doğrudan YZ', socratic_ai: 'Sokratik YZ', standard_resource: 'Standart kaynak' };
</script>

<template>
    <Head title="İlerleme ve örneklem" />

    <AppLayout>
        <PageHeader title="İlerleme ve örneklem" subtitle="Katılımcı akışı (CONSORT benzeri) ve birincil sonucun betimsel özeti. Çıkarımsal analiz önceden kayıtlı analiz hattında, dışa aktarılan verilerle yapılır.">
            <template #actions><ProtocolPicker :protocol="protocol" :protocols="protocols" route-name="admin.reports.progress" /></template>
        </PageHeader>

        <div v-if="summary" class="grid gap-6 lg:grid-cols-2">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Katılımcı akışı</h2><span class="text-xs text-stone-500">{{ summary.enrolled }} / {{ summary.enrolment_target }} kayıt</span></div>
                <table class="table">
                    <tbody>
                        <tr v-for="(row, key) in summary.by_status" :key="key"><td>{{ row.label }}</td><td class="text-right tabular-nums">{{ row.count }}</td></tr>
                    </tbody>
                </table>
                <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-600">
                    1. oturumdan sonra kayıp: {{ formatPercent(summary.attrition.rate) }} ({{ summary.attrition.started }} başlayan, {{ summary.attrition.completed }} tamamlayan)
                </p>
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Koşul × tabaka</h2></div>
                <table class="table">
                    <thead><tr><th>Koşul</th><th class="text-right">Düşük</th><th class="text-right">Orta</th><th class="text-right">Yüksek</th><th class="text-right">Tamamlayan / hedef</th></tr></thead>
                    <tbody>
                        <tr v-for="(row, key) in summary.conditions" :key="key">
                            <td>{{ row.label }}</td>
                            <td class="text-right tabular-nums">{{ row.by_stratum.low }}</td>
                            <td class="text-right tabular-nums">{{ row.by_stratum.medium }}</td>
                            <td class="text-right tabular-nums">{{ row.by_stratum.high }}</td>
                            <td class="text-right tabular-nums">{{ row.completed }} / {{ row.target }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="card lg:col-span-2">
                <div class="card-header"><h2 class="card-title">Birincil sonuç · gecikmeli yardımsız uzak transfer (%)</h2></div>
                <table class="table">
                    <thead><tr><th>Koşul</th><th class="text-right">n</th><th class="text-right">Ortalama</th><th class="text-right">SS</th></tr></thead>
                    <tbody>
                        <tr v-for="row in primaryDescriptives" :key="row.condition">
                            <td>{{ conditionLabels[row.condition] ?? row.condition }}</td>
                            <td class="text-right tabular-nums">{{ row.n }}</td>
                            <td class="text-right tabular-nums">{{ formatNumber(row.mean, 1) }}</td>
                            <td class="text-right tabular-nums">{{ formatNumber(row.sd, 1) }}</td>
                        </tr>
                        <tr v-if="!primaryDescriptives.length"><td colspan="4" class="text-center text-stone-500">Henüz 2. oturumu tamamlayan katılımcı yok.</td></tr>
                    </tbody>
                </table>
                <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">Betimseldir; veri toplama sürerken koşul farklarına bakarak protokol değiştirilmemelidir.</p>
            </section>
        </div>
    </AppLayout>
</template>
