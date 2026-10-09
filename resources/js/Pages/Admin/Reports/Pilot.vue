<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolPicker from '@/Components/ProtocolPicker.vue';
import StatusDot from '@/Components/StatusDot.vue';

defineProps({
    protocol: Object,
    protocols: Array,
    rows: Array,
});

const statusLabels = { pass: 'Sağlandı', fail: 'Sağlanmadı', no_data: 'Veri yok' };
</script>

<template>
    <Head title="Pilot doğrulama" />

    <AppLayout>
        <PageHeader title="Pilot doğrulama" subtitle="Tablo 1. Protokol bileşenleri, kontrol ölçütleri ve önceden belirlenen kabul kriterleri. Eşikler protokol sürümündeki “Pilot kabul ölçütleri” ve “Kalite eşikleri” bölümlerinden gelir.">
            <template #actions><ProtocolPicker :protocol="protocol" :protocols="protocols" route-name="admin.reports.pilot" /></template>
        </PageHeader>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Doğrulanan bileşen</th><th>Kontrol ölçütü</th><th>Kabul kriteri</th><th class="text-right">Gözlenen</th><th>Durum</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.component">
                        <td class="font-medium">{{ row.component }}</td>
                        <td class="text-stone-600">{{ row.criterion }}</td>
                        <td class="code">{{ row.threshold }}</td>
                        <td class="text-right tabular-nums">{{ row.observed }}</td>
                        <td><span class="flex items-center gap-1.5"><StatusDot :state="row.status" />{{ statusLabels[row.status] }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-stone-500">Pilot sonrasında protokolde değişiklik yapılırsa pilot katılımcıların verileri birincil doğrulayıcı analize alınmaz; ana çalışma yeni bir protokol sürümüyle başlar.</p>
    </AppLayout>
</template>
