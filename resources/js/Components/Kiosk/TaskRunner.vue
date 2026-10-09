<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Markdown from '@/Components/Markdown.vue';
import CodeEditor from '@/Components/Kiosk/CodeEditor.vue';
import AssistantPanel from '@/Components/Kiosk/AssistantPanel.vue';
import ResourcePanel from '@/Components/Kiosk/ResourcePanel.vue';
import RunOutput from '@/Components/Kiosk/RunOutput.vue';
import { hashCode } from '@/kiosk/pythonRunner';
import { formatDuration } from '@/lib/format';

const props = defineProps({
    step: { type: Object, required: true },
    log: { type: Object, required: true },
    runner: { type: Object, required: true },
    api: { type: Object, required: true },
    paused: { type: Boolean, default: false },
});

const emit = defineEmits(['done']);

const task = props.step.task;
const attempt = props.step.attempt;
const format = task.response_format;
const draftKey = `kiosk-draft-${attempt.id}`;

const phase = ref(attempt.phase);
const elapsed = ref(attempt.elapsed_seconds);
const phaseElapsed = ref(0);
const code = ref(localStorage.getItem(draftKey) ?? task.starter_code ?? '');
const explanation = ref(format === 'text' ? (localStorage.getItem(draftKey) ?? '') : '');
const choice = ref(null);
const lastResult = ref(null);
const running = ref(false);
const submitting = ref(false);
const messages = ref([...attempt.messages]);
const assistantBusy = ref(false);
const assistantError = ref('');
const actionError = ref('');
const root = ref(null);

const remaining = computed(() => Math.max(0, task.time_limit_seconds - elapsed.value));
const readingLocked = computed(() => phase.value === 'reading' && phaseElapsed.value < task.min_reading_seconds);
const planningRemaining = computed(() => Math.max(0, task.planning_seconds - phaseElapsed.value));
const helpMode = computed(() => (phase.value === 'coding' || phase.value === 'checking' ? attempt.help_mode : null));
const visibleTests = computed(() => task.tests.filter((test) => !test.hidden));

const record = (type, payload = null) => props.log.record(type, payload, attempt.id);

/* ------------------------------------------------------------------ timers */

let ticker;
function tick() {
    if (props.paused || submitting.value) {
        return;
    }

    elapsed.value += 1;
    phaseElapsed.value += 1;

    if (phase.value === 'planning' && planningRemaining.value === 0) {
        startCoding();
    }

    if (remaining.value === 0) {
        submit('timeout');
    }
}

/* ------------------------------------------------------- phase transitions */

async function changePhase(next, eventType, extra = null) {
    actionError.value = '';
    record(eventType, extra);
    phase.value = next;
    phaseElapsed.value = 0;

    try {
        await props.api.phase(attempt.id, next);
    } catch (error) {
        actionError.value = error.message;
    }

    if (next === 'coding' || next === 'checking') {
        await nextTick();
        reportLayout();
    }
}

function finishReading() {
    if (readingLocked.value) {
        return;
    }

    if (task.planning_seconds > 0) {
        changePhase('planning', 'planning_start', { seconds: task.planning_seconds });
    } else {
        startCoding();
    }
}

function startCoding() {
    if (phase.value === 'planning') {
        record('planning_end');
    }

    changePhase('coding', 'coding_start');
}

/* -------------------------------------------- silent planning input guard */

let lastViolation = 0;
function onPlanningInput(event) {
    if (phase.value !== 'planning') {
        return;
    }

    event.preventDefault();
    const now = performance.now();

    if (now - lastViolation > 1000) {
        lastViolation = now;
        record('planning_input_violation', { input: event.type });
    }
}

/* ------------------------------------------------------- editing telemetry */

let pendingTyped = 0;
let pendingDeleted = 0;
let codeFlushTimer;

function onTyped({ typed, deleted }) {
    pendingTyped += typed;
    pendingDeleted += deleted;
}

function flushCodeChange() {
    if (!pendingTyped && !pendingDeleted) {
        return;
    }

    record('code_change', { typed: pendingTyped, deleted: pendingDeleted, length: code.value.length, code: code.value });
    pendingTyped = 0;
    pendingDeleted = 0;
}

function pastedFromAssistant(text) {
    const needle = text.trim();

    return needle.length >= 8 && messages.value.some((m) => m.role === 'assistant' && m.content.includes(needle));
}

function onEditorPaste(text) {
    record('paste', { target: 'editor', length: text.length, from_assistant: pastedFromAssistant(text) });
}

function onExplanationPaste(event) {
    const text = event.clipboardData?.getData('text/plain') ?? '';
    record('paste', { target: 'explanation', length: text.length });
}

function onCopy() {
    const selection = String(window.getSelection() ?? '');
    const zone = document.activeElement?.closest?.('[data-aoi]')?.dataset.aoi
        ?? window.getSelection()?.anchorNode?.parentElement?.closest?.('[data-aoi]')?.dataset.aoi
        ?? 'unknown';

    record('copy', { source: zone, length: selection.length });
}

watch(code, (value) => {
    if (format === 'code') {
        localStorage.setItem(draftKey, value);
    }
});

watch(explanation, (value) => {
    if (format === 'text') {
        localStorage.setItem(draftKey, value);
    }
});

/* ------------------------------------------------- AOI geometry for gaze */

let layoutTimer;
function reportLayout() {
    clearTimeout(layoutTimer);
    layoutTimer = setTimeout(() => {
        const regions = {};

        root.value?.querySelectorAll('[data-aoi]').forEach((element) => {
            const rect = element.getBoundingClientRect();
            regions[element.dataset.aoi] = { x: Math.round(rect.left), y: Math.round(rect.top), w: Math.round(rect.width), h: Math.round(rect.height) };
        });

        record('aoi_layout', {
            phase: phase.value,
            device_pixel_ratio: window.devicePixelRatio,
            screen: { w: window.screen.width, h: window.screen.height },
            window: {
                screen_x: window.screenX,
                screen_y: window.screenY,
                inner_w: window.innerWidth,
                inner_h: window.innerHeight,
                outer_w: window.outerWidth,
                outer_h: window.outerHeight,
            },
            fullscreen: Boolean(document.fullscreenElement),
            regions,
        });
    }, 300);
}

/* ------------------------------------------------------------- run & help */

async function runCode() {
    if (running.value || phase.value !== 'coding') {
        return;
    }

    flushCodeChange();
    running.value = true;
    const startedPerf = performance.now();
    const source = code.value;

    try {
        const result = await props.runner.run(source, task.tests);
        lastResult.value = result;

        const failed = Boolean(result.error) || result.passed < result.total;
        record('code_run', {
            tests_passed: result.passed,
            tests_total: result.total,
            error: Boolean(result.error),
            timed_out: result.timedOut,
            code_hash: hashCode(source),
            duration_ms: Math.round(performance.now() - startedPerf),
            error_line: result.error ? result.error.split('\n').pop()?.slice(0, 200) : null,
        });
        record(failed ? 'code_error' : 'code_success', { tests_passed: result.passed, tests_total: result.total });
    } finally {
        running.value = false;
    }
}

async function ask(text) {
    assistantBusy.value = true;
    assistantError.value = '';
    messages.value.push({ id: `local-${Date.now()}`, role: 'user', content: text });
    record('ai_prompt', { length: text.length });

    try {
        const reply = await props.api.ask(attempt.id, {
            message: text,
            code: code.value,
            last_output: lastResult.value ? [lastResult.value.error, lastResult.value.output, `${lastResult.value.passed}/${lastResult.value.total} test geçti`].filter(Boolean).join('\n') : null,
        });
        messages.value.push(reply);
        record('ai_response', { length: reply.content.length, failed: reply.failed, message_id: reply.id });
    } catch (error) {
        assistantError.value = error.message;
        record('ai_response', { failed: true, error: error.status ?? 'network' });
    } finally {
        assistantBusy.value = false;
    }
}

function selectChoice(key) {
    choice.value = key;
    record('mcq_select', { option: key });
}

/* ------------------------------------------------------------------ submit */

async function submit(reason = 'submitted') {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    actionError.value = '';
    flushCodeChange();

    const payload = { end_reason: reason };

    if (format === 'code') {
        const evaluation = await props.runner.run(code.value, task.tests);
        Object.assign(payload, { code: code.value, tests_passed: evaluation.passed, tests_total: evaluation.total });
    } else if (format === 'mcq') {
        payload.mcq_answer = choice.value;
    } else {
        payload.explanation = explanation.value;
    }

    record({ submitted: 'task_submit', timeout: 'task_timeout', gave_up: 'task_give_up' }[reason], {
        tests_passed: payload.tests_passed ?? null,
        tests_total: payload.tests_total ?? null,
        mcq_answer: payload.mcq_answer ?? null,
        length: (payload.code ?? payload.explanation ?? '').length,
    });

    try {
        await props.api.submit(attempt.id, payload);
        localStorage.removeItem(draftKey);
        emit('done');
    } catch (error) {
        actionError.value = `Teslim kaydedilemedi: ${error.message}. Lütfen araştırmacıya haber verin.`;
        submitting.value = false;
    }
}

function giveUp() {
    if (window.confirm('Bu görevi bırakıp bir sonrakine geçmek istediğinize emin misiniz?')) {
        submit('gave_up');
    }
}

/* --------------------------------------------------------------- lifecycle */

onMounted(() => {
    const startedKey = `kiosk-started-${attempt.id}`;

    if (!sessionStorage.getItem(startedKey)) {
        sessionStorage.setItem(startedKey, '1');
        record('task_start', { task_id: task.id, kind: task.kind, help_mode: attempt.help_mode });
        record('reading_start');
    } else {
        record('task_start', { task_id: task.id, resumed: true, phase: phase.value });
    }

    ticker = setInterval(tick, 1000);
    codeFlushTimer = setInterval(flushCodeChange, 5000);
    window.addEventListener('keydown', onPlanningInput, true);
    window.addEventListener('mousedown', onPlanningInput, true);
    window.addEventListener('wheel', onPlanningInput, { capture: true, passive: false });
    window.addEventListener('resize', reportLayout);
    document.addEventListener('copy', onCopy);

    if (phase.value === 'coding' || phase.value === 'checking') {
        nextTick(reportLayout);
    }
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    clearInterval(codeFlushTimer);
    clearTimeout(layoutTimer);
    window.removeEventListener('keydown', onPlanningInput, true);
    window.removeEventListener('mousedown', onPlanningInput, true);
    window.removeEventListener('wheel', onPlanningInput, { capture: true });
    window.removeEventListener('resize', reportLayout);
    document.removeEventListener('copy', onCopy);
});
</script>

<template>
    <div ref="root" class="flex h-full min-h-0 flex-col">
        <header class="flex items-center gap-4 border-b border-kiosk-line px-5 py-2.5">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] tracking-wide text-kiosk-muted uppercase">{{ step.label }}</p>
                <p class="truncate text-sm font-medium">{{ task.title }}</p>
            </div>
            <p class="font-mono text-sm tabular-nums text-kiosk-muted" title="Kalan süre">{{ formatDuration(remaining) }}</p>
        </header>

        <p v-if="actionError" class="border-b border-kiosk-line bg-kiosk-panel px-5 py-2 text-sm text-amber-200">{{ actionError }}</p>

        <!-- Multiple choice -->
        <div v-if="format === 'mcq'" class="mx-auto flex w-full max-w-3xl min-h-0 flex-1 flex-col overflow-y-auto px-6 py-8" data-aoi="problem">
            <Markdown :source="task.prompt" variant="kiosk" />
            <div class="mt-6 space-y-2" data-aoi="options">
                <button
                    v-for="option in task.mcq_options"
                    :key="option.key"
                    type="button"
                    class="flex w-full items-start gap-3 rounded border px-4 py-3 text-left text-sm"
                    :class="choice === option.key ? 'border-kiosk-text bg-kiosk-panel' : 'border-kiosk-line hover:bg-kiosk-panel'"
                    @click="selectChoice(option.key)"
                >
                    <span class="font-mono text-kiosk-muted">{{ option.key }})</span>
                    <span class="font-mono whitespace-pre-wrap">{{ option.text }}</span>
                </button>
            </div>
            <div class="mt-6 flex justify-end">
                <button type="button" class="rounded border border-kiosk-text px-4 py-2 text-sm disabled:opacity-40" :disabled="!choice || submitting" @click="submit()">Yanıtı onayla</button>
            </div>
        </div>

        <!-- Free-text explanation -->
        <div v-else-if="format === 'text'" class="mx-auto flex w-full max-w-3xl min-h-0 flex-1 flex-col px-6 py-6">
            <div data-aoi="problem"><Markdown :source="task.prompt" variant="kiosk" /></div>
            <textarea
                v-model="explanation"
                data-aoi="editor"
                class="mt-4 min-h-48 flex-1 resize-none rounded border border-kiosk-line bg-kiosk-panel p-3 text-sm text-kiosk-text outline-none focus:border-kiosk-muted"
                placeholder="Açıklamanızı buraya yazın"
                @paste="onExplanationPaste"
            />
            <div class="mt-4 flex justify-end">
                <button type="button" class="rounded border border-kiosk-text px-4 py-2 text-sm disabled:opacity-40" :disabled="submitting || explanation.trim().length < 20" @click="submit()">Teslim et</button>
            </div>
        </div>

        <!-- Coding: reading phase -->
        <div v-else-if="phase === 'reading'" class="mx-auto flex w-full max-w-3xl min-h-0 flex-1 flex-col overflow-y-auto px-6 py-8" data-aoi="problem">
            <Markdown :source="task.prompt" variant="kiosk" />
            <div class="mt-8 flex items-center justify-end gap-3">
                <span v-if="readingLocked" class="text-xs text-kiosk-muted">Lütfen problemi okuyun…</span>
                <button type="button" class="rounded border border-kiosk-text px-4 py-2 text-sm disabled:opacity-40" :disabled="readingLocked" @click="finishReading">
                    {{ task.planning_seconds > 0 ? 'Okudum, planlamaya geç' : 'Okudum, koda geç' }}
                </button>
            </div>
        </div>

        <!-- Coding: silent planning phase -->
        <div v-else-if="phase === 'planning'" class="flex min-h-0 flex-1 cursor-none flex-col items-center overflow-y-auto px-6 py-8 select-none">
            <div class="w-full max-w-3xl opacity-90" data-aoi="problem">
                <Markdown :source="task.prompt" variant="kiosk" />
            </div>
            <div class="mt-8 text-center">
                <p class="text-sm">Sessiz planlama</p>
                <p class="mt-1 max-w-md text-xs text-kiosk-muted">Klavyeye ve fareye dokunmadan, başınızı sabit tutarak çözümü düşünün. Süre bitince editör kendiliğinden açılacak.</p>
                <p class="mt-3 font-mono text-lg tabular-nums text-kiosk-muted">{{ planningRemaining }}</p>
            </div>
        </div>

        <!-- Coding / checking workspace -->
        <div v-else class="grid min-h-0 flex-1" :class="helpMode ? 'grid-cols-[minmax(0,0.9fr)_minmax(0,1.3fr)_24rem]' : 'grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)]'">
            <section class="min-h-0 overflow-y-auto border-r border-kiosk-line px-5 py-4" data-aoi="problem">
                <Markdown :source="task.prompt" variant="kiosk" />
                <div v-if="visibleTests.length" class="mt-4">
                    <p class="mb-1 text-[11px] tracking-wide text-kiosk-muted uppercase">Testler</p>
                    <ul class="space-y-0.5 font-mono text-xs text-kiosk-muted">
                        <li v-for="test in visibleTests" :key="test.call" class="break-all">{{ test.call }} → {{ test.expected }}</li>
                    </ul>
                </div>
            </section>

            <section class="flex min-h-0 flex-col border-r border-kiosk-line">
                <div class="min-h-0 flex-[3]" data-aoi="editor">
                    <CodeEditor v-model="code" :readonly="phase !== 'coding' || submitting" @typed="onTyped" @paste="onEditorPaste" />
                </div>
                <div class="flex items-center gap-2 border-y border-kiosk-line px-4 py-2">
                    <template v-if="phase === 'coding'">
                        <button type="button" class="rounded border border-kiosk-text px-3 py-1.5 text-sm disabled:opacity-40" :disabled="running || submitting" @click="runCode">Çalıştır</button>
                        <button type="button" class="rounded border border-kiosk-line px-3 py-1.5 text-sm hover:bg-kiosk-panel disabled:opacity-40" :disabled="running || submitting" @click="changePhase('checking', 'checking_start')">Kontrol et ve teslim et</button>
                        <button type="button" class="ml-auto text-xs text-kiosk-muted hover:text-kiosk-text" :disabled="submitting" @click="giveUp">Bu görevi bırak</button>
                    </template>
                    <template v-else>
                        <p class="flex-1 text-sm">Çözümünüzü gözden geçirin. Teslimden sonra değişiklik yapılamaz.</p>
                        <button type="button" class="rounded border border-kiosk-line px-3 py-1.5 text-sm hover:bg-kiosk-panel" :disabled="submitting" @click="changePhase('coding', 'coding_start', { returned: true })">Koda dön</button>
                        <button type="button" class="rounded border border-kiosk-text px-3 py-1.5 text-sm disabled:opacity-40" :disabled="submitting" @click="submit()">{{ submitting ? 'Teslim ediliyor…' : 'Teslim et' }}</button>
                    </template>
                </div>
                <div class="min-h-0 flex-[2]" data-aoi="output">
                    <RunOutput :result="lastResult" :running="running" />
                </div>
            </section>

            <aside v-if="helpMode === 'assistant'" class="min-h-0" data-aoi="assistant">
                <AssistantPanel :messages="messages" :busy="assistantBusy" :disabled="phase !== 'coding'" :error="assistantError" @send="ask" />
            </aside>
            <aside v-else-if="helpMode === 'resource'" class="min-h-0" data-aoi="resource">
                <ResourcePanel :endpoint="api.resourcesUrl" @open="(p) => record('resource_open', p)" @search="(p) => record('resource_search', p)" />
            </aside>
        </div>
    </div>
</template>
