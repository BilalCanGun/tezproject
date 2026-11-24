<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolTabs from '@/Components/ProtocolTabs.vue';
import DesignWarnings from '@/Components/DesignWarnings.vue';
import { formatDuration } from '@/lib/format';

const props = defineProps({
    protocol: Object,
    tasks: Object,
    designWarnings: { type: Array, default: () => [] },
});

const sessionTitles = {
    0: 'Ön değerlendirme (sensörsüz, yardımsız)',
    1: '1. Oturum · Öğrenme (koşula göre yardım)',
    2: '2. Oturum · Gecikmeli (yardımsız)',
    form_A: 'Form A · A-B sırasında 1. oturumda, B-A sırasında 2. oturumda',
    form_B: 'Form B · A-B sırasında 2. oturumda, B-A sırasında 1. oturumda',
};

const groups = ['0', '1', '2', 'form_A', 'form_B'];

function remove(task) {
    if (confirm(`"${task.title}" silinsin mi? Denemesi olan görevler silinmez, pasifleştirilir.`)) {
        router.delete(route('admin.protocols.tasks.destroy', [props.protocol.id, task.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${protocol.code} · Görevler`" />

    <AppLayout>
        <PageHeader :title="`${protocol.code} · ${protocol.name}`">
            <template #breadcrumb><Link :href="route('admin.protocols.index')" class="hover:underline">Protokol sürümleri</Link></template>
            <template #actions>
                <Link v-if="!protocol.is_frozen" :href="route('admin.protocols.tasks.create', protocol.id)" class="btn btn-primary">Görev ekle</Link>
            </template>
        </PageHeader>

        <ProtocolTabs :protocol="protocol" />

        <DesignWarnings :warnings="designWarnings" />

        <p class="mb-4 max-w-3xl text-sm text-stone-600">
            Görevler sıra numarasına göre sunulur. İki oturumdaki eşdeğer görevler aynı <span class="code">izomorf grup</span> etiketini taşır;
            oturum × koşul etkileşimi ancak bu gruplar üzerinden karşılaştırılabilir. Birincil sonuç, 2. oturumdaki uzak transfer görevleridir.
            Yakın/uzak transfer ve hata bulma görevleri A/B formlarına konursa, hangi oturumda çıkacakları katılımcının karşı dengelenmiş form sırasına
            (A-B veya B-A) göre belirlenir; formlu görevlerde yardım yalnızca 1. oturumda verilir.
        </p>

        <section v-for="session in groups" :key="session" class="card mb-6">
            <div class="card-header"><h2 class="card-title">{{ sessionTitles[session] }}</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th class="w-10">Sıra</th><th>Görev</th><th>Tür</th><th>İzomorf</th><th class="text-right">Puan</th><th class="text-right">Planlama</th><th class="text-right">Süre</th><th>Yardım</th><th class="text-right">Test</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="task in tasks[session] ?? []" :key="task.id" :class="{ 'opacity-50': !task.is_active }">
                            <td class="tabular-nums text-stone-500">{{ task.sort_order }}</td>
                            <td class="font-medium">{{ task.title }}<span v-if="!task.is_active" class="badge ml-1 bg-stone-100 text-stone-500">pasif</span></td>
                            <td>{{ task.kind_label }}</td>
                            <td class="code text-stone-600">{{ task.isomorph_group ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ task.max_score }}</td>
                            <td class="text-right tabular-nums">{{ task.planning_seconds ? task.planning_seconds + ' sn' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ formatDuration(task.time_limit_seconds) }}</td>
                            <td>{{ task.help_allowed ? 'Açık' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ task.tests_count || '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <Link :href="route('admin.protocols.tasks.edit', [protocol.id, task.id])" class="btn btn-ghost btn-sm">{{ protocol.is_frozen ? 'Görüntüle' : 'Düzenle' }}</Link>
                                <button v-if="!protocol.is_frozen" type="button" class="btn btn-ghost btn-sm text-red-700" @click="remove(task)">Sil</button>
                            </td>
                        </tr>
                        <tr v-if="!(tasks[session] ?? []).length"><td colspan="10" class="text-center text-stone-500">Görev yok.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
