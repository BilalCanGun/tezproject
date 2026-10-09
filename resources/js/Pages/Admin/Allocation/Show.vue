<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolTabs from '@/Components/ProtocolTabs.vue';
import Field from '@/Components/Field.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({
    protocol: Object,
    hasList: Boolean,
    blockSizes: Array,
    defaultSlots: Number,
    strata: Array,
    assigned: Array,
    conditions: Array,
});

const form = useForm({
    slots_per_stratum: props.defaultSlots,
    seed: Math.floor(Math.random() * 2_000_000_000) + 1,
});

function generate() {
    const warning = props.hasList
        ? 'Mevcut (henüz kullanılmamış) liste silinip yeniden üretilecek. Devam edilsin mi?'
        : 'Atama listesi oluşturulsun mu?';

    if (confirm(warning)) {
        form.post(route('admin.protocols.allocation.store', props.protocol.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${protocol.code} · Atama listesi`" />

    <AppLayout>
        <PageHeader :title="`${protocol.code} · ${protocol.name}`">
            <template #breadcrumb><Link :href="route('admin.protocols.index')" class="hover:underline">Protokol sürümleri</Link></template>
        </PageHeader>

        <ProtocolTabs :protocol="protocol" />

        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="space-y-6">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Tabaka bazında doluluk</h2></div>
                    <table class="table">
                        <thead>
                            <tr><th>Tabaka</th><th class="text-right">Kullanılan / toplam</th><th v-for="c in conditions" :key="c.value" class="text-right">{{ c.label }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in strata" :key="s.value">
                                <td>{{ s.label }}</td>
                                <td class="text-right tabular-nums">{{ s.used }} / {{ s.total }}</td>
                                <td v-for="c in conditions" :key="c.value" class="text-right tabular-nums">{{ s.by_condition[c.value] }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">Henüz kullanılmamış atamalar gösterilmez; sıra gizliliği (allocation concealment) korunur.</p>
                </section>

                <section class="card">
                    <div class="card-header"><h2 class="card-title">Yapılan atamalar</h2></div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Katılımcı</th><th>Tabaka</th><th class="text-right">Sıra</th><th class="text-right">Blok</th><th>Koşul</th><th>Zaman</th><th>Atayan</th></tr></thead>
                            <tbody>
                                <tr v-for="row in assigned" :key="`${row.stratum_label}-${row.position}`">
                                    <td class="code">{{ row.participant_code }}</td>
                                    <td>{{ row.stratum_label }}</td>
                                    <td class="text-right tabular-nums">{{ row.position }}</td>
                                    <td class="text-right tabular-nums">{{ row.block_number }}</td>
                                    <td>{{ row.condition_label }}</td>
                                    <td class="text-stone-600">{{ formatDateTime(row.assigned_at) }}</td>
                                    <td class="text-stone-600">{{ row.assigned_by }}</td>
                                </tr>
                                <tr v-if="!assigned.length"><td colspan="7" class="text-center text-stone-500">Henüz atama yok.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="card h-fit p-4">
                <h2 class="card-title">Liste üret</h2>
                <p class="mt-1 mb-3 text-xs text-stone-500">
                    Her tabaka için değişken büyüklüklü permütasyon blokları ({{ blockSizes.join(', ') }}) kullanılır. Aynı tohum aynı listeyi üretir;
                    tohumu protokol belgelerinde saklayın. İşlem kaydına yalnızca tohumun özeti yazılır. Atama yapılmış liste yeniden üretilemez.
                </p>
                <form class="space-y-3" @submit.prevent="generate">
                    <Field label="Tabaka başına atama sayısı" :error="form.errors.slots_per_stratum"><input v-model.number="form.slots_per_stratum" type="number" class="input"></Field>
                    <Field label="Tohum (seed)" :error="form.errors.seed"><input v-model.number="form.seed" type="number" class="input code"></Field>
                    <button type="submit" class="btn btn-primary w-full" :disabled="form.processing || assigned.length > 0">{{ hasList ? 'Listeyi yeniden üret' : 'Listeyi üret' }}</button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
