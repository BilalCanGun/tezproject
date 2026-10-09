<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';
import { formatBytes, formatDateTime } from '@/lib/format';

const props = defineProps({
    sessionId: { type: Number, required: true },
    files: { type: Array, required: true },
    kinds: { type: Array, required: true },
});

const isAdmin = usePage().props.auth.user.is_admin;

const form = useForm({
    kind: 'xdf',
    file: null,
    recorded_start_utc: '',
    recorded_end_utc: '',
    notes: '',
});

function upload() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])))
        .post(route('sessions.files.store', props.sessionId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
}

function remove(file) {
    if (confirm(`${file.original_name} kalıcı olarak silinsin mi?`)) {
        router.delete(route('sessions.files.destroy', [props.sessionId, file.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <section class="card">
        <div class="card-header"><h2 class="card-title">Kayıt dosyaları</h2></div>

        <ul class="divide-y divide-stone-100">
            <li v-for="file in files" :key="file.id" class="flex items-start gap-2 px-4 py-2.5 text-sm">
                <div class="min-w-0 flex-1">
                    <a :href="route('sessions.files.show', [sessionId, file.id])" class="font-medium text-accent-strong hover:underline">{{ file.original_name }}</a>
                    <p class="text-xs text-stone-500">{{ file.kind_label }} · {{ formatBytes(file.size_bytes) }} · {{ file.uploader }} · {{ formatDateTime(file.created_at) }}</p>
                    <p class="code truncate text-[11px] text-stone-400" :title="file.sha256">sha256 {{ file.sha256 }}</p>
                </div>
                <button v-if="isAdmin" type="button" class="btn btn-ghost btn-sm text-red-700" @click="remove(file)">Sil</button>
            </li>
            <li v-if="!files.length" class="px-4 py-3 text-sm text-stone-500">Dosya yüklenmedi.</li>
        </ul>

        <form class="space-y-3 border-t border-stone-200 p-4" @submit.prevent="upload">
            <div class="grid grid-cols-2 gap-3">
                <Field label="Tür" :error="form.errors.kind">
                    <select v-model="form.kind" class="input">
                        <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                    </select>
                </Field>
                <Field label="Dosya" :error="form.errors.file">
                    <input type="file" class="input py-1 text-xs" @input="form.file = $event.target.files[0]">
                </Field>
                <Field label="Kayıt başlangıcı (UTC)" :error="form.errors.recorded_start_utc">
                    <input v-model="form.recorded_start_utc" type="datetime-local" step="0.001" class="input">
                </Field>
                <Field label="Kayıt bitişi (UTC)" :error="form.errors.recorded_end_utc">
                    <input v-model="form.recorded_end_utc" type="datetime-local" step="0.001" class="input">
                </Field>
            </div>
            <progress v-if="form.progress" :value="form.progress.percentage" max="100" class="h-1 w-full" />
            <div class="flex justify-end"><button type="submit" class="btn btn-secondary" :disabled="form.processing || !form.file">Yükle</button></div>
        </form>
    </section>
</template>
