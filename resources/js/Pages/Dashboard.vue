<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDate, formatDateTime, sessionStatusStyles } from '@/lib/format';

defineProps({
    protocol: Object,
    summary: Object,
    delayedWindow: Array,
    upcoming: Array,
    qualityFlags: Array,
});

const windowStyles = {
    upcoming: 'bg-stone-100 text-stone-700',
    open: 'bg-emerald-50 text-emerald-800',
    overdue: 'bg-red-50 text-red-800',
};

const windowLabels = {
    upcoming: 'Pencere açılmadı',
    open: 'Pencere açık',
    overdue: 'Pencere kaçtı',
};
</script>

<template>
    <Head title="Genel durum" />

    <AppLayout>
        <PageHeader title="Genel durum" :subtitle="protocol ? `${protocol.code} · ${protocol.name}` : 'Etkin protokol yok. Yönetici bir protokol sürümünü etkinleştirmeli.'">
            <template #actions>
                <Link :href="route('participants.create')" class="btn btn-primary">Yeni katılımcı</Link>
            </template>
        </PageHeader>

        <div v-if="summary" class="grid gap-4 md:grid-cols-4">
            <div class="card p-4">
                <p class="text-xs text-stone-500">Kayıtlı katılımcı</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.enrolled }} <span class="text-sm font-normal text-stone-500">/ {{ summary.enrolment_target }}</span></p>
                <div class="mt-2 h-1.5 rounded bg-stone-100">
                    <div class="h-1.5 rounded bg-accent" :style="{ width: Math.min(100, (summary.enrolled / summary.enrolment_target) * 100) + '%' }" />
                </div>
            </div>
            <div v-for="(row, key) in summary.conditions" :key="key" class="card p-4">
                <p class="text-xs text-stone-500">{{ row.label }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ row.completed }} <span class="text-sm font-normal text-stone-500">/ {{ row.target }} tamamlayan</span></p>
                <p class="mt-1 text-xs text-stone-500">{{ row.assigned }} atanmış · D {{ row.by_stratum.low }} / O {{ row.by_stratum.medium }} / Y {{ row.by_stratum.high }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Yaklaşan ve süren oturumlar</h2>
                    <span class="text-xs text-stone-500">7 gün</span>
                </div>
                <table class="table">
                    <thead>
                        <tr><th>Katılımcı</th><th>Oturum</th><th>Zaman</th><th>Durum</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="session in upcoming" :key="session.id">
                            <td><Link :href="route('sessions.show', session.id)" class="code font-medium text-accent-strong hover:underline">{{ session.participant_code }}</Link></td>
                            <td>{{ session.kind }}</td>
                            <td class="text-stone-600">{{ formatDateTime(session.scheduled_at) }}</td>
                            <td><span class="badge" :class="sessionStatusStyles[session.status]">{{ session.status_label }}</span></td>
                        </tr>
                        <tr v-if="!upcoming.length"><td colspan="4" class="text-center text-stone-500">Planlanmış oturum yok.</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Gecikmeli oturum pencereleri</h2>
                    <span class="text-xs text-stone-500">1. oturumdan 6–8 gün sonra</span>
                </div>
                <table class="table">
                    <thead>
                        <tr><th>Katılımcı</th><th>Pencere</th><th>Planlanan</th><th /></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in delayedWindow" :key="row.participant_id">
                            <td><Link :href="route('participants.show', row.participant_id)" class="code font-medium text-accent-strong hover:underline">{{ row.code }}</Link></td>
                            <td class="text-stone-600">{{ formatDate(row.window_opens) }} – {{ formatDate(row.window_closes) }}</td>
                            <td class="text-stone-600">{{ row.scheduled_at ? formatDateTime(row.scheduled_at) : 'Planlanmadı' }}</td>
                            <td><span class="badge" :class="windowStyles[row.state]">{{ windowLabels[row.state] }}</span></td>
                        </tr>
                        <tr v-if="!delayedWindow.length"><td colspan="4" class="text-center text-stone-500">Bekleyen gecikmeli oturum yok.</td></tr>
                    </tbody>
                </table>
            </section>

            <section v-if="qualityFlags.length" class="card lg:col-span-2">
                <div class="card-header"><h2 class="card-title">Veri kalitesi uyarıları</h2></div>
                <table class="table">
                    <tbody>
                        <tr v-for="flag in qualityFlags" :key="flag.session_id">
                            <td class="code">{{ flag.participant_code }}</td>
                            <td>{{ flag.session_label }}</td>
                            <td>{{ flag.verdict }}</td>
                            <td><span v-if="flag.needs_repeat" class="badge bg-red-50 text-red-800">Tekrar gerekli</span></td>
                            <td class="text-right"><Link :href="route('sessions.show', flag.session_id)" class="btn btn-secondary btn-sm">Aç</Link></td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
