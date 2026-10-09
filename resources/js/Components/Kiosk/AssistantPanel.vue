<script setup>
import { nextTick, ref, watch } from 'vue';
import Markdown from '@/Components/Markdown.vue';

const props = defineProps({
    messages: { type: Array, required: true },
    busy: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['send']);

const draft = ref('');
const list = ref(null);

function send() {
    const text = draft.value.trim();

    if (!text || props.busy || props.disabled) {
        return;
    }

    emit('send', text);
    draft.value = '';
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

watch(() => [props.messages.length, props.busy], async () => {
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight });
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="border-b border-kiosk-line px-4 py-2 text-sm font-medium">Asistan</div>

        <div ref="list" class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
            <p v-if="!messages.length" class="text-sm text-kiosk-muted">Takıldığınız yeri ya da sorunuzu yazın. Editördeki kodunuz ve son çalıştırma çıktısı asistana otomatik iletilir.</p>
            <div v-for="message in messages" :key="message.id" :class="message.role === 'user' ? 'ml-8' : 'mr-4'">
                <p class="mb-0.5 text-[11px] tracking-wide text-kiosk-muted uppercase">{{ message.role === 'user' ? 'Siz' : 'Asistan' }}</p>
                <div class="rounded px-3 py-2" :class="message.role === 'user' ? 'bg-kiosk-bg' : 'bg-kiosk-panel ring-1 ring-kiosk-line'">
                    <p v-if="message.role === 'user'" class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
                    <Markdown v-else :source="message.content" variant="kiosk" />
                </div>
            </div>
            <p v-if="busy" class="text-sm text-kiosk-muted">Asistan yanıtlıyor…</p>
        </div>

        <form class="border-t border-kiosk-line p-3" @submit.prevent="send">
            <p v-if="error" class="mb-2 text-xs text-amber-200">{{ error }}</p>
            <textarea
                v-model="draft"
                rows="3"
                class="w-full resize-none rounded border border-kiosk-line bg-kiosk-bg px-2.5 py-2 text-sm text-kiosk-text outline-none placeholder:text-kiosk-muted focus:border-kiosk-muted"
                placeholder="Mesajınızı yazın (Enter gönderir, Shift+Enter yeni satır)"
                :disabled="disabled"
                @keydown="onKeydown"
            />
            <div class="mt-2 flex justify-end">
                <button type="submit" class="rounded border border-kiosk-muted px-3 py-1.5 text-sm hover:bg-kiosk-bg disabled:opacity-40" :disabled="busy || disabled || !draft.trim()">Gönder</button>
            </div>
        </form>
    </div>
</template>
