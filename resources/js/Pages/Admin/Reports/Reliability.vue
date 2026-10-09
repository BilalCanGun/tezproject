<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolPicker from '@/Components/ProtocolPicker.vue';
import { formatNumber } from '@/lib/format';

const props = defineProps({
    protocol: Object,
    protocols: Array,
    report: Object,
});

function dispatchLlm() {
    if (confirm('Henüz LLM puanı olmayan tüm açıklamalar puanlama kuyruğuna eklensin mi? Gerçek sağlayıcı seçiliyse API maliyeti oluşur.')) {
        router.post(route('admin.reports.llm-ratings.store'), { protocol_id: props.protocol.id }, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Puanlayıcı güvenirliği" />

    <AppLayout>
        <PageHeader title="Puanlayıcı güvenirliği" subtitle="Açıklama kalitesi puanları için insan–insan ve LLM–insan uyumu.">
            <template #actions>
                <ProtocolPicker :protocol="protocol" :protocols="protocols" route-name="admin.reports.reliability" />
                <button v-if="protocol" type="button" class="btn btn-secondary" @click="dispatchLlm">LLM puanlamasını başlat</button>
            </template>
        </PageHeader>

        <template v-if="report">
            <div class="mb-6 grid gap-4 sm:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-stone-500">Açıklama yanıtı</p><p class="text-2xl font-semibold">{{ report.responses }}</p></div>
                <div class="card p-4"><p class="text-xs text-stone-500">En az bir insan puanı</p><p class="text-2xl font-semibold">{{ report.rated_once }}</p></div>
                <div class="card p-4"><p class="text-xs text-stone-500">Çift puanlanan</p><p class="text-2xl font-semibold">{{ report.double_rated }}</p></div>
                <div class="card p-4"><p class="text-xs text-stone-500">LLM puanlı</p><p class="text-2xl font-semibold">{{ report.llm_rated }}</p></div>
            </div>

            <div class="card overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Ölçüm</th><th>Yöntem</th><th class="text-right">Değer</th><th>Yorum</th></tr></thead>
                    <tbody>
                        <tr>
                            <td class="font-medium">Toplam puan · insan–insan</td>
                            <td class="text-stone-600">ICC(1,1), tek yönlü rastgele etkiler</td>
                            <td class="text-right tabular-nums">{{ formatNumber(report.total_icc.value, 3) }}</td>
                            <td>{{ report.total_icc.label }}</td>
                        </tr>
                        <tr v-for="c in report.criteria" :key="c.key">
                            <td>{{ c.label }} · insan–insan</td>
                            <td class="text-stone-600">Ağırlıklı kappa (karesel)</td>
                            <td class="text-right tabular-nums">{{ formatNumber(c.kappa.value, 3) }}</td>
                            <td>{{ c.kappa.label }}</td>
                        </tr>
                        <tr>
                            <td class="font-medium">Toplam puan · LLM–insan ortalaması</td>
                            <td class="text-stone-600">ICC(2,1), mutlak uyum · {{ report.llm_pairs }} çift</td>
                            <td class="text-right tabular-nums">{{ formatNumber(report.llm_vs_human_icc.value, 3) }}</td>
                            <td>{{ report.llm_vs_human_icc.label }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 max-w-3xl text-xs text-stone-500">
                Çift puanlanacak yanıtlar protokoldeki orana göre deterministik olarak seçilir; puanlayıcılar koşulu, katılımcı kodunu ve YZ konuşmasını görmez.
                LLM puanları insan puanlarının yerine geçmez; uyum raporlandıktan sonra yalnızca ikincil olarak kullanılır.
            </p>
        </template>
    </AppLayout>
</template>
