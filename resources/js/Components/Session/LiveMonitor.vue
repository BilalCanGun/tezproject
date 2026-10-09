<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { http } from '@/lib/http';
import { formatDuration, formatTime } from '@/lib/format';
import StatusDot from '@/Components/StatusDot.vue';

const props = defineProps({
    sessionId: { type: Number, required: true },
    recordsPhysiology: { type: Boolean, default: true },
});

const emit = defineEmits(['status-changed']);

const state = ref(null);
const error = ref('');
const noteCategory = ref('movement');
const noteText = ref('');
const noteSending = ref(false);
const now = ref(Date.now());
let pollTimer;
let clockTimer;

const categories = {
    movement: 'Hareket',
    speech: 'Konuşma',
    device: 'Cihaz müdahalesi',
    external: 'Dış kesinti',
    participant_question: 'Soru sordu',
    other: 'Diğer',
};

async function poll() {
    try {
        const previous = state.value?.status;
        state.value = await http.get(route('sessions.monitor', props.sessionId));
        error.value = '';

        if (previous && previous !== state.value.status) {
            emit('status-changed', state.value.status);
        }
    } catch (e) {
        error.value = 'İzleme verisi alınamadı.';
    }
}

async function sendNote(category = noteCategory.value) {
    noteSending.value = true;

    try {
        await http.post(route('sessions.notes.store', props.sessionId), { category, text: noteText.value || null });
        noteText.value = '';
        await poll();
    } finally {
        noteSending.value = false;
    }
}

const stepElapsed = computed(() => {
    const startedAt = state.value?.current?.started_at;

    return startedAt ? (now.value - new Date(startedAt).getTime()) / 1000 : null;
});

const markerIntegrity = computed(() => {
    const markers = state.value?.markers;

    return markers?.sent ? markers.acknowledged / markers.sent : null;
});

const bridgeStreams = computed(() => state.value?.bridge?.streams ?? []);

onMounted(() => {
    poll();
    pollTimer = setInterval(poll, 2000);
    clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
});
</script>

<template>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Canlı izleme</h2>
            <span v-if="error" class="text-xs text-red-700">{{ error }}</span>
            <span v-else-if="state" class="flex items-center gap-1.5 text-xs text-stone-500">
                <StatusDot :state="state.kiosk_online ? 'ok' : 'fail'" />
                Katılımcı ekranı {{ state.kiosk_online ? 'bağlı' : 'çevrimdışı' }}
            </span>
        </div>

        <div v-if="!state" class="p-4 text-sm text-stone-500">Yükleniyor…</div>

        <div v-else class="grid gap-0 lg:grid-cols-[16rem_1fr]">
            <ol class="border-b border-stone-200 py-2 lg:border-r lg:border-b-0">
                <li v-for="step in state.steps" :key="step.id" class="flex items-center gap-2 px-3 py-1 text-xs">
                    <span
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-medium"
                        :class="{
                            'bg-stone-800 text-white': step.status === 'done',
                            'bg-accent text-white ring-2 ring-accent/30': step.status === 'active',
                            'bg-stone-100 text-stone-500': step.status === 'pending',
                        }"
                    >{{ step.position }}</span>
                    <span :class="step.status === 'active' ? 'font-medium text-ink' : 'text-stone-600'">{{ step.label }}</span>
                </li>
            </ol>

            <div class="space-y-4 p-4">
                <div v-if="state.current" class="rounded border border-stone-200 p-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium">{{ state.current.label }}</p>
                        <p class="code text-stone-600">{{ formatDuration(stepElapsed) }}<template v-if="state.current.attempt"> / {{ formatDuration(state.current.attempt.time_limit_seconds) }}</template></p>
                    </div>
                    <div v-if="state.current.attempt" class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                        <div><span class="text-stone-500">Faz</span><br><span class="font-medium">{{ state.current.attempt.phase_label }}</span></div>
                        <div><span class="text-stone-500">Çalıştırma / hatalı</span><br><span class="font-medium tabular-nums">{{ state.current.attempt.run_count }} / {{ state.current.attempt.failed_run_count }}</span></div>
                        <div><span class="text-stone-500">Yardım isteği</span><br><span class="font-medium tabular-nums">{{ state.current.attempt.help_available ? state.current.attempt.help_request_count + state.current.attempt.resource_view_count : 'kapalı' }}</span></div>
                        <div><span class="text-stone-500">Yazılan / yapıştırılan</span><br><span class="font-medium tabular-nums">{{ state.current.attempt.typed_chars }} / {{ state.current.attempt.pasted_chars }}</span></div>
                        <div><span class="text-stone-500">Planlama ihlali</span><br><span class="font-medium tabular-nums" :class="{ 'text-red-700': state.current.attempt.planning_input_violations }">{{ state.current.attempt.planning_input_violations }}</span></div>
                        <div><span class="text-stone-500">Odak kaybı</span><br><span class="font-medium tabular-nums" :class="{ 'text-red-700': state.current.attempt.focus_loss_count }">{{ state.current.attempt.focus_loss_count }}</span></div>
                    </div>
                </div>
                <p v-else class="text-sm text-stone-500">{{ state.status === 'completed' ? 'Oturum tamamlandı.' : 'Katılımcı ekranı henüz başlamadı.' }}</p>

                <div v-if="recordsPhysiology" class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded border border-stone-200 p-2.5 text-xs">
                        <p class="flex items-center gap-1.5 font-medium"><StatusDot :state="state.bridge?.connected ? 'ok' : 'fail'" /> LSL köprüsü</p>
                        <p class="mt-1 text-stone-500">
                            {{ state.bridge?.connected ? `RTT ${state.bridge.rtt_ms?.toFixed?.(1) ?? '—'} ms` : 'Bağlı değil' }}
                            <template v-if="state.bridge?.pending_events"> · {{ state.bridge.pending_events }} olay kuyrukta</template>
                        </p>
                    </div>
                    <div class="rounded border border-stone-200 p-2.5 text-xs">
                        <p class="flex items-center gap-1.5 font-medium"><StatusDot :state="markerIntegrity === null ? 'idle' : markerIntegrity >= 0.99 ? 'ok' : 'warn'" /> Marker bütünlüğü</p>
                        <p class="mt-1 text-stone-500 tabular-nums">{{ state.markers.acknowledged }} / {{ state.markers.sent }} LSL onaylı</p>
                    </div>
                    <div class="rounded border border-stone-200 p-2.5 text-xs">
                        <p class="flex items-center gap-1.5 font-medium"><StatusDot :state="state.client_clock_offset_ms === null ? 'idle' : Math.abs(state.client_clock_offset_ms) < 500 ? 'ok' : 'warn'" /> Saat farkı</p>
                        <p class="mt-1 text-stone-500 tabular-nums">Kiosk − sunucu: {{ state.client_clock_offset_ms ?? '—' }} ms</p>
                    </div>
                    <div class="rounded border border-stone-200 p-2.5 text-xs sm:col-span-3">
                        <p class="font-medium">Görünen LSL akışları</p>
                        <ul v-if="bridgeStreams.length" class="mt-1 flex flex-wrap gap-1.5">
                            <li v-for="stream in bridgeStreams" :key="stream.name" class="badge bg-stone-100 text-stone-700">
                                {{ stream.name }} · {{ stream.type }} · {{ stream.channel_count }} kn · {{ stream.nominal_srate }} Hz
                            </li>
                        </ul>
                        <p v-else class="mt-1 text-amber-800">EEG / göz izleyici akışı görünmüyor. LabRecorder kaydından önce kontrol edin.</p>
                    </div>
                </div>

                <div>
                    <p class="label">Araştırmacı notu (LSL markerı olarak işlenir)</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="(label, key) in categories" :key="key" type="button" class="btn btn-secondary btn-sm" :disabled="noteSending" @click="sendNote(key)">{{ label }}</button>
                    </div>
                    <form class="mt-2 flex gap-2" @submit.prevent="sendNote(noteCategory)">
                        <select v-model="noteCategory" class="input w-auto">
                            <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <input v-model="noteText" type="text" class="input" placeholder="Açıklama (isteğe bağlı)">
                        <button type="submit" class="btn btn-primary" :disabled="noteSending">Ekle</button>
                    </form>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div>
                        <p class="label">Son olaylar</p>
                        <ul class="max-h-64 divide-y divide-stone-100 overflow-y-auto rounded border border-stone-200 text-xs">
                            <li v-for="event in state.events" :key="event.id" class="flex items-center gap-2 px-2 py-1">
                                <span class="code w-16 shrink-0 text-stone-500">{{ formatTime(event.at) }}</span>
                                <span v-if="event.marker_code" class="code w-6 shrink-0 text-right text-stone-400">{{ event.marker_code }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ event.label }}<template v-if="event.detail"> · {{ event.detail }}</template></span>
                                <StatusDot v-if="event.marker_code" :state="event.lsl ? 'ok' : 'warn'" :title="event.lsl ? 'LSL onaylı' : 'LSL onayı yok'" />
                            </li>
                            <li v-if="!state.events.length" class="px-2 py-3 text-center text-stone-400">Henüz olay yok.</li>
                        </ul>
                    </div>
                    <div>
                        <p class="label">Asistan konuşması (geçerli görev)</p>
                        <ul class="max-h-64 space-y-2 overflow-y-auto rounded border border-stone-200 p-2 text-xs">
                            <li v-for="m in state.ai_messages" :key="m.id" :class="m.role === 'user' ? 'text-stone-800' : 'text-stone-600'">
                                <span class="font-medium">{{ m.role === 'user' ? 'Katılımcı' : 'Asistan' }}:</span>
                                <span class="whitespace-pre-line">{{ m.content }}</span>
                                <span v-if="m.guard_action" class="badge ml-1 bg-indigo-50 text-indigo-800">koruma: {{ m.guard_action }}</span>
                                <span v-if="m.full_solution" class="badge ml-1 bg-amber-50 text-amber-800">tam çözüm</span>
                                <span v-if="m.error" class="badge ml-1 bg-red-50 text-red-800">hata</span>
                            </li>
                            <li v-if="!state.ai_messages.length" class="py-2 text-center text-stone-400">Mesaj yok.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
