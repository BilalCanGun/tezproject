<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusDot from '@/Components/StatusDot.vue';

defineProps({
    devices: Object,
    modalities: Object,
});
</script>

<template>
    <Head title="Cihaz profilleri" />

    <AppLayout>
        <PageHeader title="Cihaz profilleri" subtitle="Her cihaz, protokolün ölçüm gereksinimlerine göre değerlendirilir. Kanal sayısı ve kapsamına göre savunulabilir EEG analizleri otomatik belirlenir.">
            <template #actions><Link :href="route('admin.devices.create')" class="btn btn-primary">Cihaz ekle</Link></template>
        </PageHeader>

        <div v-for="(label, modality) in modalities" :key="modality" class="mb-8">
            <h2 class="mb-3 text-sm font-semibold text-stone-700">{{ label }}</h2>
            <div class="grid gap-4 xl:grid-cols-2">
                <article v-for="device in devices[modality] ?? []" :key="device.id" class="card" :class="{ 'opacity-60': !device.is_active }">
                    <div class="card-header">
                        <div>
                            <h3 class="text-sm font-semibold">{{ device.name }}</h3>
                            <p class="text-xs text-stone-500">
                                {{ device.manufacturer ?? 'Üretici belirtilmedi' }}
                                <template v-if="device.channel_count"> · {{ device.channel_count }} kanal</template>
                                <template v-if="device.sampling_rate_hz"> · {{ device.sampling_rate_hz }} Hz</template>
                                · {{ device.sessions_count }} oturumda kullanıldı
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="!device.specs_verified" class="badge bg-amber-50 text-amber-800">doğrulanmadı</span>
                            <Link :href="route('admin.devices.edit', device.id)" class="btn btn-secondary btn-sm">Düzenle</Link>
                        </div>
                    </div>
                    <table class="table">
                        <tbody>
                            <tr v-for="check in device.suitability.checks" :key="check.label">
                                <td class="w-5"><StatusDot :state="check.status" /></td>
                                <td class="font-medium">{{ check.label }}</td>
                                <td class="text-stone-600">{{ check.value }}</td>
                                <td class="text-xs text-stone-500">{{ check.requirement }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="device.suitability.analyses.length" class="border-t border-stone-200 p-4">
                        <p class="label">Savunulabilir analizler</p>
                        <ul class="space-y-1 text-sm">
                            <li v-for="analysis in device.suitability.analyses" :key="analysis.name" class="flex gap-2">
                                <StatusDot :state="analysis.allowed ? 'ok' : 'fail'" class="mt-1.5" />
                                <span><span :class="{ 'text-stone-400 line-through': !analysis.allowed }">{{ analysis.name }}</span> <span class="text-xs text-stone-500">— {{ analysis.reason }}</span></span>
                            </li>
                        </ul>
                    </div>
                    <div class="border-t border-stone-200 px-4 py-3 text-xs text-stone-600">
                        <p>Senkron hassasiyeti: {{ device.suitability.sync_precision }}</p>
                        <p v-if="device.license_notes" class="mt-1">Lisans: {{ device.license_notes }}</p>
                    </div>
                </article>
                <p v-if="!(devices[modality] ?? []).length" class="card p-4 text-sm text-stone-500">Bu türde cihaz yok.</p>
            </div>
        </div>
    </AppLayout>
</template>
