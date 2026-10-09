<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import LiveMonitor from '@/Components/Session/LiveMonitor.vue';
import SetupPanel from '@/Components/Session/SetupPanel.vue';
import DevicesPanel from '@/Components/Session/DevicesPanel.vue';
import QualityPanel from '@/Components/Session/QualityPanel.vue';
import FilesPanel from '@/Components/Session/FilesPanel.vue';
import { formatDateTime, sessionStatusStyles } from '@/lib/format';

const props = defineProps({
    session: Object,
    participant: Object,
    checklist: Object,
    steps: Array,
    devices: Array,
    deviceProfiles: Array,
    modalities: Array,
    quality: Object,
    files: Array,
    fileKinds: Array,
    kioskUrl: String,
});

const copied = ref(false);
const showAbort = ref(false);
const abortForm = useForm({ reason: '' });
const finished = computed(() => ['completed', 'aborted'].includes(props.session.status));

function issueLink() {
    const message = props.session.kiosk.issued
        ? 'Yeni bağlantı oluşturulursa mevcut katılımcı ekranı bağlantısı geçersiz olur. Devam edilsin mi?'
        : 'Oturum hazırlanacak: YZ ayarları dondurulacak ve adım planı oluşturulacak. Devam edilsin mi?';

    if (confirm(message)) {
        router.post(route('sessions.kiosk-link.store', props.session.id), {}, { preserveScroll: true });
    }
}

async function copyLink() {
    await navigator.clipboard.writeText(props.kioskUrl);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

function togglePause() {
    router.post(route(props.session.is_paused ? 'sessions.resume' : 'sessions.pause', props.session.id), {}, { preserveScroll: true });
}

function abort() {
    abortForm.post(route('sessions.abort', props.session.id), {
        preserveScroll: true,
        onSuccess: () => { showAbort.value = false; },
    });
}

function onStatusChanged() {
    router.reload({ only: ['session', 'steps', 'quality'] });
}
</script>

<template>
    <Head :title="`${participant.code} · ${session.kind_label}`" />

    <AppLayout>
        <PageHeader :title="`${participant.code} · ${session.kind_label}`">
            <template #breadcrumb>
                <Link :href="route('participants.show', participant.id)" class="hover:underline">{{ participant.code }}</Link>
            </template>
            <template #actions>
                <span class="badge" :class="sessionStatusStyles[session.status]">{{ session.status_label }}</span>
                <span v-if="session.is_paused" class="badge bg-amber-100 text-amber-900">Duraklatıldı</span>
                <template v-if="!finished">
                    <button type="button" class="btn btn-primary" @click="issueLink">{{ session.kiosk.issued ? 'Bağlantıyı yenile' : 'Oturumu hazırla ve bağlantı al' }}</button>
                    <button v-if="session.status === 'running'" type="button" class="btn btn-secondary" @click="togglePause">{{ session.is_paused ? 'Devam ettir' : 'Duraklat' }}</button>
                    <button type="button" class="btn btn-secondary" @click="showAbort = !showAbort">Sonlandır</button>
                </template>
            </template>
        </PageHeader>

        <div v-if="kioskUrl" class="card mb-6 border-accent p-4">
            <p class="text-sm font-medium">Katılımcı ekranı bağlantısı</p>
            <p class="text-xs text-stone-600">Deney bilgisayarında tam ekran açın (F11). Bağlantı yalnızca şimdi gösterilir.</p>
            <div class="mt-2 flex gap-2">
                <input :value="kioskUrl" readonly class="input code" @focus="$event.target.select()">
                <button type="button" class="btn btn-secondary" @click="copyLink">{{ copied ? 'Kopyalandı' : 'Kopyala' }}</button>
                <a :href="kioskUrl" target="_blank" rel="noopener" class="btn btn-secondary">Yeni pencerede aç</a>
            </div>
        </div>

        <form v-if="showAbort" class="card mb-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="abort">
            <div class="min-w-64 flex-1">
                <label class="label">Sonlandırma gerekçesi</label>
                <input v-model="abortForm.reason" type="text" class="input" placeholder="ör. katılımcı rahatsızlandı; EEG başlığı uyumsuz">
                <p v-if="abortForm.errors.reason" class="mt-1 text-xs text-red-700">{{ abortForm.errors.reason }}</p>
            </div>
            <button type="submit" class="btn btn-danger" :disabled="abortForm.processing">Oturumu sonlandır</button>
        </form>

        <div class="mb-6 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-3"><p class="text-xs text-stone-500">Koşul</p><p class="font-medium">{{ session.condition_label ?? 'Koşulsuz (ön değerlendirme)' }}</p></div>
            <div class="card p-3"><p class="text-xs text-stone-500">Planlanan / başlangıç</p><p class="font-medium">{{ formatDateTime(session.started_at ?? session.scheduled_at) }}</p></div>
            <div class="card p-3"><p class="text-xs text-stone-500">Süre</p><p class="font-medium">{{ session.duration_min ? session.duration_min + ' dk' : '—' }}</p></div>
            <div class="card p-3">
                <p class="text-xs text-stone-500">YZ yapılandırması</p>
                <p v-if="session.ai_snapshot" class="font-medium">
                    {{ session.ai_snapshot.provider }}<template v-if="session.ai_snapshot.model"> · {{ session.ai_snapshot.model }}</template>
                    <span v-if="!session.ai_snapshot.protocol_frozen" class="badge ml-1 bg-amber-100 text-amber-800">protokol dondurulmamış</span>
                </p>
                <p v-else class="text-stone-500">Hazırlıkta sabitlenecek</p>
            </div>
        </div>

        <p v-if="session.abort_reason" class="mb-6 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Sonlandırma gerekçesi: {{ session.abort_reason }}</p>

        <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
            <div class="space-y-6">
                <LiveMonitor v-if="session.status !== 'scheduled'" :session-id="session.id" :records-physiology="session.records_physiology" @status-changed="onStatusChanged" />
                <div v-else class="card p-6 text-sm text-stone-600">
                    Oturum henüz hazırlanmadı. Hazırlık listesini tamamlayıp <span class="font-medium">“Oturumu hazırla ve bağlantı al”</span> düğmesine basın. Bu adımda YZ model ayarları oturum için sabitlenir ve görev planı oluşturulur.
                </div>
                <QualityPanel v-if="finished" :session-id="session.id" :quality="quality" :records-physiology="session.records_physiology" />
                <FilesPanel v-if="session.records_physiology" :session-id="session.id" :files="files" :kinds="fileKinds" />
            </div>

            <div class="space-y-6">
                <SetupPanel :session="session" :checklist="checklist" />
                <DevicesPanel v-if="session.records_physiology" :session-id="session.id" :devices="devices" :profiles="deviceProfiles" />
            </div>
        </div>
    </AppLayout>
</template>
