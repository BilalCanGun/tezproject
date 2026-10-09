<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolPicker from '@/Components/ProtocolPicker.vue';
import { formatNumber, formatPercent } from '@/lib/format';

defineProps({
    protocol: Object,
    protocols: Array,
    report: Object,
});

const rows = [
    { key: 'attempts', label: 'Yardımlı görev denemesi', format: (v) => formatNumber(v, 0) },
    { key: 'replies', label: 'YZ yanıtı', format: (v) => formatNumber(v, 0), ai: true },
    { key: 'replies_per_attempt', label: 'Görev başına YZ yanıtı', format: formatNumber, ai: true },
    { key: 'full_code_rate', label: 'Tam kod içeren yanıt oranı (≥3 satır)', format: formatPercent, ai: true },
    { key: 'direct_solution_rate', label: 'Doğrudan çözüm oranı', format: formatPercent, ai: true },
    { key: 'mean_code_lines', label: 'Yanıt başına kod satırı', format: formatNumber, ai: true },
    { key: 'mean_questions', label: 'Yanıt başına soru', format: formatNumber, ai: true },
    { key: 'pre_guard_leak_rate', label: 'Koruma öncesi sızıntı oranı', format: formatPercent, ai: true },
    { key: 'post_guard_leak_rate', label: 'Koruma sonrası sızıntı oranı', format: formatPercent, ai: true },
    { key: 'guard_regenerated', label: 'Yeniden üretilen yanıt', format: (v) => formatNumber(v, 0), ai: true },
    { key: 'guard_redacted', label: 'Kodu kaldırılan yanıt', format: (v) => formatNumber(v, 0), ai: true },
    { key: 'median_latency_ms', label: 'Medyan yanıt süresi (ms)', format: (v) => formatNumber(v, 0), ai: true },
    { key: 'mean_help_requests', label: 'Görev başına YZ isteği', format: formatNumber },
    { key: 'mean_resource_views', label: 'Görev başına kaynak görüntüleme', format: formatNumber },
    { key: 'self_written_ratio', label: 'Kendi yazdığı kod oranı', format: formatPercent },
    { key: 'ai_pasted_ratio', label: 'YZ yanıtından yapıştırılan kod oranı', format: formatPercent },
];
</script>

<template>
    <Head title="Manipülasyon kontrolü" />

    <AppLayout>
        <PageHeader title="Manipülasyon kontrolü" subtitle="Koşulların tasarlandığı gibi ayrışıp ayrışmadığı. Yalnızca 1. oturumdaki yardımı açık, teslim edilmiş görevler sayılır.">
            <template #actions><ProtocolPicker :protocol="protocol" :protocols="protocols" route-name="admin.reports.manipulation" /></template>
        </PageHeader>

        <div v-if="report" class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Gösterge</th><th v-for="(c, key) in report.conditions" :key="key" class="text-right">{{ c.label }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key">
                        <td>{{ row.label }}</td>
                        <td v-for="(c, key) in report.conditions" :key="key" class="text-right tabular-nums">
                            {{ row.ai && key === 'standard_resource' ? '—' : row.format(c[row.key]) }}
                        </td>
                    </tr>
                    <tr>
                        <td>İpucu düzeyi dağılımı</td>
                        <td v-for="(c, key) in report.conditions" :key="key" class="text-right text-xs text-stone-600">
                            <template v-if="c.hints_by_level && Object.keys(c.hints_by_level).length">
                                <span v-for="(n, level) in c.hints_by_level" :key="level" class="ml-2">D{{ level }}: {{ n }}</span>
                            </template>
                            <template v-else>—</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 max-w-3xl text-xs text-stone-500">
            Sızıntı: yanıtın görevin fonksiyonunu tanımlayan ≥3 satır kod içermesi, referans çözüme benzerliğin eşiği aşması veya kod satırının Sokratik sınırı ({{ report?.max_code_lines }}) geçmesi.
            Pilotta Sokratik konuşmalar ayrıca elle incelenmelidir.
        </p>
    </AppLayout>
</template>
