<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { Head } from '@inertiajs/vue3';
import { http } from '@/lib/http';
import { EventLog } from '@/kiosk/eventLog';
import { LslBridge } from '@/kiosk/lslBridge';
import { PythonRunner } from '@/kiosk/pythonRunner';
import TaskRunner from '@/Components/Kiosk/TaskRunner.vue';
import InstructionsStep from '@/Components/Kiosk/InstructionsStep.vue';
import RestingBaseline from '@/Components/Kiosk/RestingBaseline.vue';
import SyncGesture from '@/Components/Kiosk/SyncGesture.vue';

const props = defineProps({
    apiBase: String,
    records_physiology: Boolean,
    pyodideIndexUrl: String,
    bridgeUrl: String,
    runTimeoutSeconds: Number,
    participantCode: String,
});

const SERVER_SEQUENCE_START = 1_000_000;

const state = ref(null);
const started = ref(false);
const busy = ref(false);
const connectionError = ref('');
const log = shallowRef(null);
const runner = shallowRef(null);
let bridge = null;
let pollTimer;
let heartbeatTimer;
const relayed = new Set();

const step = computed(() => state.value?.step);
const finished = computed(() => ['completed', 'aborted'].includes(state.value?.session.status));
const paused = computed(() => Boolean(state.value?.session.is_paused));

const api = {
    phase: (attemptId, phase) => http.patch(`${props.apiBase}/attempts/${attemptId}/phase`, { phase }),
    submit: (attemptId, data) => http.post(`${props.apiBase}/attempts/${attemptId}/submit`, data),
    ask: (attemptId, data) => http.post(`${props.apiBase}/attempts/${attemptId}/assistant`, data),
    resourcesUrl: `${props.apiBase}/resources`,
};

async function loadState() {
    try {
        state.value = await http.get(`${props.apiBase}/state`);
        connectionError.value = '';
        relayServerMarkers();
    } catch (error) {
        connectionError.value = error.status === 404
            ? 'Bu bağlantı artık geçerli değil. Lütfen araştırmacıya haber verin.'
            : 'Sunucuya ulaşılamıyor. Çalışmaya devam edebilirsiniz; kayıtlar bağlantı gelince gönderilecek.';
    }
}

/* Tester notes and pause/resume are created on the server; forward them to the
   LSL stream so they appear next to the EEG and gaze data. */
function relayServerMarkers() {
    for (const item of state.value?.relay ?? []) {
        if (!relayed.has(item.seq) && bridge?.marker(item.seq, item.marker_code, item.type)) {
            relayed.add(item.seq);
        }
    }
}

function initialise() {
    if (props.records_physiology) {
        bridge = new LslBridge(props.bridgeUrl, {
            onAck: (seq, lslTime) => {
                if (seq >= SERVER_SEQUENCE_START) {
                    log.value?.record('marker_ack', { target_seq: seq, lsl_time: lslTime });
                } else {
                    log.value?.acknowledge(seq, lslTime);
                }
            },
            onSync: (sync) => log.value?.record('clock_sync', sync),
        });
    }

    log.value = new EventLog({
        sessionId: state.value.session.id,
        endpoint: `${props.apiBase}/events`,
        bridge,
        markerCodes: state.value.marker_codes,
        lastSequence: state.value.last_sequence,
    });

    runner.value = new PythonRunner(props.pyodideIndexUrl, props.runTimeoutSeconds);
}

async function begin() {
    try {
        await document.documentElement.requestFullscreen?.();
    } catch {
        // Fullscreen can be refused; the tester checks it on the monitor.
    }

    started.value = true;

    if (!sessionStorage.getItem(`kiosk-session-started-${state.value.session.id}`)) {
        sessionStorage.setItem(`kiosk-session-started-${state.value.session.id}`, '1');
        log.value.record('session_start', {
            participant: props.participantCode,
            user_agent: navigator.userAgent,
            screen: { w: window.screen.width, h: window.screen.height, dpr: window.devicePixelRatio },
        });
    }

    await advance();
}

/** Start the current step if it is still pending. */
async function advance() {
    await loadState();

    if (step.value?.status === 'pending' && !paused.value) {
        busy.value = true;

        try {
            await http.post(`${props.apiBase}/steps/${step.value.id}/start`);
            log.value.record('step_start', { step_id: step.value.id, type: step.value.type });
            await loadState();
        } catch (error) {
            connectionError.value = error.message;
        } finally {
            busy.value = false;
        }
    }

    if (step.value?.type === 'closing' && step.value.status === 'active') {
        await finishSession();
    }
}

async function completeStep() {
    const current = step.value;
    log.value.record('step_end', { step_id: current.id, type: current.type });

    try {
        await http.post(`${props.apiBase}/steps/${current.id}/complete`);
    } catch (error) {
        connectionError.value = error.message;

        return;
    }

    await advance();
}

async function taskDone() {
    log.value.record('step_end', { step_id: step.value.id, type: 'task' });
    await advance();
}

const closingMessage = ref('');

async function finishSession() {
    closingMessage.value = step.value.config?.body ?? 'Oturum tamamlandı.';
    log.value.record('session_end');
    await log.value.flush();
    await completeStep();
    await log.value.flush();
    document.exitFullscreen?.().catch(() => {});
}

async function heartbeat() {
    try {
        await http.post(`${props.apiBase}/heartbeat`, {
            client_epoch_ms: Date.now(),
            bridge: bridge ? bridge.snapshot() : null,
            pending_events: log.value?.pending ?? 0,
        });
    } catch {
        // Reported as offline on the tester monitor.
    }
}

function onVisibility() {
    log.value?.record(document.hidden ? 'focus_lost' : 'focus_gained', { reason: 'visibility' }, step.value?.attempt?.id ?? null);

    if (document.hidden) {
        log.value?.flush({ keepalive: true });
    }
}

function onBlur() {
    log.value?.record('focus_lost', { reason: 'blur' }, step.value?.attempt?.id ?? null);
}

function onFocus() {
    log.value?.record('focus_gained', { reason: 'focus' }, step.value?.attempt?.id ?? null);
}

function onPageHide() {
    log.value?.flush({ keepalive: true });
}

onMounted(async () => {
    await loadState();

    if (state.value) {
        initialise();
    }

    pollTimer = setInterval(async () => {
        if (paused.value || !step.value || step.value.status === 'pending' || finished.value) {
            const wasPaused = paused.value;
            await loadState();

            if (wasPaused && !paused.value && started.value) {
                await advance();
            }
        } else {
            relayServerMarkers();
            loadState();
        }
    }, 3000);

    heartbeatTimer = setInterval(heartbeat, 5000);
    heartbeat();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('blur', onBlur);
    window.addEventListener('focus', onFocus);
    window.addEventListener('pagehide', onPageHide);
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(heartbeatTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('blur', onBlur);
    window.removeEventListener('focus', onFocus);
    window.removeEventListener('pagehide', onPageHide);
    log.value?.destroy();
    runner.value?.destroy();
    bridge?.close();
});
</script>

<template>
    <Head title="Çalışma" />

    <div class="fixed inset-0 flex flex-col bg-kiosk-bg text-kiosk-text" @contextmenu.prevent>
        <p v-if="connectionError" class="border-b border-kiosk-line bg-kiosk-panel px-5 py-2 text-center text-sm text-amber-200">{{ connectionError }}</p>

        <div v-if="!state" class="flex flex-1 items-center justify-center text-sm text-kiosk-muted">Yükleniyor…</div>

        <div v-else-if="closingMessage || finished" class="flex flex-1 flex-col items-center justify-center px-6 text-center">
            <p class="max-w-lg text-lg">{{ closingMessage || 'Bu oturum sona erdi.' }}</p>
        </div>

        <div v-else-if="!started" class="flex flex-1 flex-col items-center justify-center px-6 text-center">
            <h1 class="text-xl font-medium">Hoş geldiniz</h1>
            <p class="mt-3 max-w-md text-sm text-kiosk-muted">Araştırmacı hazır olduğunu söylediğinde aşağıdaki düğmeye basın. Ekran tam ekran moduna geçecek.</p>
            <button type="button" class="mt-8 rounded border border-kiosk-text px-5 py-2.5 text-sm" @click="begin">Başla</button>
        </div>

        <template v-else-if="step && step.status === 'active'">
            <TaskRunner v-if="step.type === 'task'" :key="step.id" :step="step" :log="log" :runner="runner" :api="api" :paused="paused" class="min-h-0 flex-1" @done="taskDone" />
            <InstructionsStep v-else-if="step.type === 'instructions'" :key="`i-${step.id}`" :config="step.config" class="flex-1 overflow-y-auto" @done="completeStep" />
            <RestingBaseline v-else-if="step.type === 'resting_baseline'" :key="`r-${step.id}`" :config="step.config" :log="log" class="flex-1" @done="completeStep" />
            <SyncGesture v-else-if="step.type === 'sync_gesture'" :key="`s-${step.id}`" :config="step.config" :log="log" class="flex-1" @done="completeStep" />
            <div v-else class="flex flex-1 items-center justify-center text-sm text-kiosk-muted">Lütfen bekleyin…</div>
        </template>

        <div v-else class="flex flex-1 items-center justify-center text-sm text-kiosk-muted">{{ busy ? 'Hazırlanıyor…' : 'Lütfen araştırmacının yönergesini bekleyin.' }}</div>

        <div class="h-1 bg-kiosk-panel">
            <div class="h-1 bg-kiosk-line transition-all" :style="{ width: state ? (state.progress.done / Math.max(1, state.progress.total)) * 100 + '%' : '0%' }" />
        </div>

        <div v-if="paused && started && !finished" class="fixed inset-0 z-50 flex items-center justify-center bg-kiosk-bg">
            <p class="text-lg">Kısa bir ara verildi. Lütfen araştırmacının yönergesini bekleyin.</p>
        </div>
    </div>
</template>
