<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { playTone } from '@/kiosk/tone';

/*
 * The wearable has no live stream, so its UTC clock is verified afterwards by
 * matching accelerometer peaks to these markers: the participant taps the
 * table with the wrist wearing the band on each beep.
 */
const props = defineProps({
    config: { type: Object, required: true },
    log: { type: Object, required: true },
});

const emit = defineEmits(['done']);

const stage = ref('intro');
const count = ref(0);
let timer;

function wait(ms) {
    return new Promise((resolve) => { timer = setTimeout(resolve, ms); });
}

async function start() {
    stage.value = 'tapping';
    await wait(1500);

    for (let i = 1; i <= props.config.taps; i++) {
        count.value = i;
        playTone(880, 0.12);
        props.log.record('sync_gesture', { moment: props.config.moment, tap: i, of: props.config.taps });
        await wait(1200);
    }

    stage.value = 'finished';
    await wait(1000);
    emit('done');
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex h-full flex-col items-center justify-center px-6 text-center">
        <template v-if="stage === 'intro'">
            <h2 class="text-lg font-medium">Bileklik eşlemesi</h2>
            <p class="mt-3 max-w-lg text-sm text-kiosk-muted">
                Bilekliğin takılı olduğu elinizi masanın üzerine koyun. Her sinyal sesinde bileğinizle masaya bir kez hafifçe vurun.
                Toplam {{ config.taps }} ses duyacaksınız.
            </p>
            <button type="button" class="mt-8 rounded border border-kiosk-text px-4 py-2 text-sm" @click="start">Hazırım</button>
        </template>
        <p v-else-if="stage === 'tapping'" class="font-mono text-4xl tabular-nums">{{ count || '…' }}</p>
        <p v-else class="text-sm text-kiosk-muted">Teşekkürler.</p>
    </div>
</template>
