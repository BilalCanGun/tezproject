<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, formatNumber, formatPercent } from '@/lib/format';

const props = defineProps({
    report: Object,
});

const metricLabels = {
    mae: 'MAE',
    rmse: 'RMSE',
    r2: 'R²',
    balanced_accuracy: 'Dengeli doğruluk',
    f1_macro: 'Makro F1',
    roc_auc: 'ROC AUC',
    delta_mae: 'Δ MAE (başlangıca göre)',
    delta_balanced_accuracy: 'Δ dengeli doğruluk (başlangıca göre)',
};

const lowerIsBetter = ['mae', 'rmse'];
const modalityLabels = { eeg: 'EEG', eye_tracker: 'Göz izleyici', wearable: 'Giyilebilir otonom sensör' };
const statusLabels = { ok: 'Karşılıyor', warn: 'Sınırlı', fail: 'Karşılamıyor', no_data: 'Veri yok' };

const devicesByModality = computed(() => {
    const groups = {};
    for (const device of props.report?.devices ?? []) {
        (groups[device.modality] ??= []).push(device);
    }
    return groups;
});

function metricKeys(experiment) {
    return Object.keys(experiment.results[0]?.metrics ?? {});
}

function bestModel(experiment) {
    const key = experiment.primary_metric;
    const candidates = experiment.results.filter((row) => !row.model.startsWith('Dummy'));
    const sorted = [...candidates].sort((a, b) => (lowerIsBetter.includes(key) ? a.metrics[key] - b.metrics[key] : b.metrics[key] - a.metrics[key]));
    return sorted[0]?.model;
}

function formatMetric(key, value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }
    if (key.startsWith('delta_')) {
        const sign = value > 0 ? '+' : '';
        return key === 'delta_balanced_accuracy' ? `${sign}${formatNumber(value * 100, 1)} puan` : `${sign}${formatNumber(value, 3)}`;
    }
    return ['balanced_accuracy', 'f1_macro', 'roc_auc'].includes(key) ? formatPercent(value) : formatNumber(value, 3);
}
</script>

<template>
    <Head title="Cihaz doğrulama ve modeller" />

    <AppLayout>
        <PageHeader
            title="Cihaz doğrulama ve model karşılaştırması"
            subtitle="Açık veri setleri her aday cihazın kanal ve örnekleme özelliklerine indirgenerek analiz hattı çalıştırılır: formda öngörülen ölçümlerin o cihazla elde edilip edilemediği ve katılımcı bağımsız modellerin performansı."
        />

        <div v-if="!report" class="card p-6 text-sm text-stone-700">
            <p class="font-medium">Henüz doğrulama raporu üretilmedi.</p>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-stone-600">
                <li><span class="code">php artisan devices:export-profiles</span> ile panelde tanımlı cihazları dışa aktarın.</li>
                <li><span class="code">analysis\.venv\Scripts\python analysis\run_validation.py --download</span> ile veri setlerini indirip doğrulamayı çalıştırın.</li>
                <li>Bu sayfayı yenileyin.</li>
            </ol>
        </div>

        <template v-else>
            <p class="mb-6 text-xs text-stone-500">Üretildi: {{ formatDateTime(report.generated_at) }}</p>

            <section class="mb-8">
                <h2 class="mb-3 text-sm font-semibold text-stone-700">Kullanılan veri setleri</h2>
                <div class="card overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Veri seti</th><th>Modalite</th><th class="text-right">Katılımcı</th><th>Kullanım</th></tr></thead>
                        <tbody>
                            <tr v-for="dataset in report.datasets" :key="dataset.key">
                                <td><a :href="dataset.url" target="_blank" rel="noopener" class="font-medium underline decoration-stone-300">{{ dataset.title }}</a></td>
                                <td>{{ dataset.modality }}</td>
                                <td class="text-right tabular-nums">{{ dataset.participants }}</td>
                                <td class="text-stone-600">{{ dataset.note }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-for="(devices, modality) in devicesByModality" :key="modality" class="mb-8">
                <h2 class="mb-3 text-sm font-semibold text-stone-700">{{ modalityLabels[modality] ?? modality }}: cihazdan beklenenler</h2>
                <div class="grid gap-4 xl:grid-cols-2">
                    <article v-for="device in devices" :key="device.device" class="card">
                        <div class="card-header">
                            <div>
                                <h3 class="text-sm font-semibold">{{ device.device }}</h3>
                                <p class="text-xs text-stone-500">{{ device.dataset }}<template v-if="device.source === 'karşılaştırma'"> · karşılaştırma profili</template></p>
                            </div>
                        </div>
                        <table class="table">
                            <tbody>
                                <tr v-for="check in device.checks" :key="check.label">
                                    <td class="w-5"><StatusDot :state="check.status" /></td>
                                    <td class="font-medium">{{ check.label }}</td>
                                    <td class="tabular-nums text-stone-700">{{ check.value }}</td>
                                    <td class="text-xs text-stone-500">{{ check.requirement }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="device.summary" class="border-t border-stone-200 px-4 py-3 text-xs text-stone-600">{{ device.summary }}</p>
                    </article>
                </div>
            </section>

            <section v-for="experiment in report.models" :key="experiment.key" class="mb-8">
                <h2 class="text-sm font-semibold text-stone-700">{{ experiment.title }}</h2>
                <p class="mb-3 mt-1 text-xs text-stone-500">
                    {{ experiment.dataset }} · hedef: {{ experiment.target }} · {{ experiment.n_participants }} katılımcı, {{ experiment.n_samples }} örnek ·
                    {{ experiment.evaluation }}
                </p>
                <div class="card overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th v-if="experiment.results[0]?.feature_set">Özellik seti</th>
                                <th>Model</th>
                                <th v-for="key in metricKeys(experiment)" :key="key" class="text-right">{{ metricLabels[key] ?? key }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in experiment.results" :key="(row.feature_set ?? '') + row.model" :class="{ 'bg-emerald-50/60': !row.feature_set && row.model === bestModel(experiment) }">
                                <td v-if="row.feature_set" class="text-stone-600">{{ row.feature_set }}</td>
                                <td class="font-medium">{{ row.model }}</td>
                                <td v-for="key in metricKeys(experiment)" :key="key" class="text-right tabular-nums">{{ formatMetric(key, row.metrics[key]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="experiment.record_wise" class="mt-2 text-xs text-stone-600">
                    Karşılaştırma için kayıt bazlı (sızdıran) çapraz doğrulama, {{ experiment.record_wise.model }}:
                    {{ metricLabels[experiment.primary_metric] }} = {{ formatMetric(experiment.primary_metric, experiment.record_wise.metrics[experiment.primary_metric]) }}.
                    Aradaki fark, aynı katılımcının verisi hem eğitimde hem testte olduğunda tahminin ne kadar iyimser çıktığını gösterir.
                </p>
                <p v-if="experiment.note" class="mt-1 text-xs text-stone-500">{{ experiment.note }}</p>
            </section>

            <section v-if="report.limitations?.length" class="card p-4">
                <h2 class="mb-2 text-sm font-semibold text-stone-700">Sınırlılıklar</h2>
                <ul class="list-disc space-y-1 pl-5 text-sm text-stone-600">
                    <li v-for="item in report.limitations" :key="item">{{ item }}</li>
                </ul>
            </section>
        </template>
    </AppLayout>
</template>
