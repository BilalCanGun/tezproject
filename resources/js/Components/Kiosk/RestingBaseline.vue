<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { playTone } from '@/kiosk/tone';

const props = defineProps({
    config: { type: Object, required: true },
    log: { type: Object, required: true },
});

const emit = defineEmits(['done']);

const stage = ref('intro');
let timer;

function wait(seconds) {
    return new Promise((resolve) => { timer = setTimeout(resolve, seconds * 1000); });
}

async function start() {
    if (props.config.eyes_open_seconds > 0) {
        stage.value = 'open';
        props.log.record('eyes_open_start', { seconds: props.config.eyes_open_seconds });
        await wait(props.config.eyes_open_seconds);
        props.log.record('eyes_open_end');
    }

    if (props.config.eyes_closed_seconds > 0) {
        stage.value = 'closed-intro';
        await wait(4);
        stage.value = 'closed';
        props.log.record('eyes_closed_start', { seconds: props.config.eyes_closed_seconds });
        await wait(props.config.eyes_closed_seconds);
        props.log.record('eyes_closed_end');
        playTone(660, 0.4);
    }

    stage.value = 'finished';
    await wait(1.5);
    emit('done');
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex h-full flex-col items-center justify-center px-6 text-center">
        <template v-if="stage === 'intro'">
            <h2 class="text-lg font-medium">Dinlenim kaydı</h2>
            <p class="mt-3 max-w-lg text-sm text-kiosk-muted">
                Önce ekranın ortasında bir artı işareti göreceksiniz; konuşmadan ve kıpırdamadan ona bakın.
                Ardından gözlerinizi kapatmanız istenecek; bir sinyal sesi duyduğunuzda gözlerinizi açın.
            </p>
            <button type="button" class="mt-8 rounded border border-kiosk-text px-4 py-2 text-sm" @click="start">Hazırım</button>
        </template>
        <span v-else-if="stage === 'open'" class="font-mono text-5xl leading-none select-none">+</span>
        <p v-else-if="stage === 'closed-intro'" class="text-base">Şimdi gözlerinizi kapatın. Sinyal sesini duyunca açın.</p>
        <span v-else-if="stage === 'closed'" class="font-mono text-5xl leading-none text-kiosk-muted select-none">+</span>
        <p v-else class="text-sm text-kiosk-muted">Teşekkürler.</p>
    </div>
</template>
