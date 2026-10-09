<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';

const props = defineProps({
    sessionId: { type: Number, required: true },
    devices: { type: Array, required: true },
    profiles: { type: Array, required: true },
});

const addForm = useForm({ device_profile_id: '' });
const editing = ref(null);
const editForm = useForm({ serial_number: '', lsl_stream_name: '', notes: '', checks: {} });

function add() {
    addForm.post(route('sessions.devices.store', props.sessionId), {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

function edit(device) {
    editing.value = device.id;
    editForm.serial_number = device.serial_number ?? '';
    editForm.lsl_stream_name = device.lsl_stream_name ?? '';
    editForm.notes = device.notes ?? '';
    editForm.checks = { ...device.checks };
}

function save(device) {
    editForm.put(route('sessions.devices.update', [props.sessionId, device.id]), {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

function remove(device) {
    if (confirm(`${device.name} oturumdan çıkarılsın mı?`)) {
        router.delete(route('sessions.devices.destroy', [props.sessionId, device.id]), { preserveScroll: true });
    }
}

function summary(device) {
    const c = device.checks || {};

    if (device.modality === 'eeg') {
        return c.signal_quality_ok === undefined ? 'Sinyal kontrolü girilmedi' : `Sinyal ${c.signal_quality_ok ? 'uygun' : 'sorunlu'}${c.bad_channels ? ' · kötü kanal: ' + c.bad_channels : ''}`;
    }

    if (device.modality === 'eye_tracker') {
        return c.calibration_accuracy_deg ? `Doğruluk ${c.calibration_accuracy_deg}° · kesinlik ${c.calibration_precision_deg ?? '—'}°` : 'Kalibrasyon girilmedi';
    }

    return c.recording_started_utc ? `Kayıt başlangıcı ${c.recording_started_utc} UTC · sapma ${c.device_clock_offset_ms ?? '—'} ms` : 'Kayıt başlangıcı girilmedi';
}
</script>

<template>
    <section class="card">
        <div class="card-header"><h2 class="card-title">Cihazlar</h2></div>

        <ul class="divide-y divide-stone-100">
            <li v-for="device in devices" :key="device.id" class="px-4 py-3">
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ device.name }}</p>
                        <p class="text-xs text-stone-500">{{ device.modality_label }} · {{ summary(device) }}</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="editing === device.id ? (editing = null) : edit(device)">{{ editing === device.id ? 'Kapat' : 'Kontroller' }}</button>
                    <button type="button" class="btn btn-ghost btn-sm text-red-700" @click="remove(device)">Çıkar</button>
                </div>

                <form v-if="editing === device.id" class="mt-3 grid grid-cols-2 gap-3 rounded border border-stone-200 bg-stone-50 p-3" @submit.prevent="save(device)">
                    <Field label="Seri no"><input v-model="editForm.serial_number" type="text" class="input"></Field>
                    <Field label="LSL akış adı"><input v-model="editForm.lsl_stream_name" type="text" class="input"></Field>

                    <template v-if="device.modality === 'eeg'">
                        <label class="col-span-2 flex items-center gap-2 text-sm">
                            <input v-model="editForm.checks.signal_quality_ok" type="checkbox" class="rounded border-stone-300">
                            Tüm kanallarda sinyal kalitesi kabul aralığında
                        </label>
                        <Field label="Sorunlu kanallar"><input v-model="editForm.checks.bad_channels" type="text" class="input" placeholder="ör. PO7"></Field>
                        <Field label="Empedans / temas notu"><input v-model="editForm.checks.impedance_note" type="text" class="input"></Field>
                    </template>

                    <template v-else-if="device.modality === 'eye_tracker'">
                        <Field label="Kalibrasyon noktası"><input v-model="editForm.checks.calibration_points" type="number" class="input"></Field>
                        <Field label="İzlenen göz">
                            <select v-model="editForm.checks.tracked_eye" class="input">
                                <option value="both">İki göz</option>
                                <option value="left">Sol</option>
                                <option value="right">Sağ</option>
                            </select>
                        </Field>
                        <Field label="Doğruluk (°)" :error="editForm.errors['checks.calibration_accuracy_deg']"><input v-model="editForm.checks.calibration_accuracy_deg" type="number" step="0.01" class="input"></Field>
                        <Field label="Kesinlik (°)"><input v-model="editForm.checks.calibration_precision_deg" type="number" step="0.01" class="input"></Field>
                    </template>

                    <template v-else>
                        <Field label="Kayıt başlangıcı (UTC)"><input v-model="editForm.checks.recording_started_utc" type="datetime-local" step="1" class="input"></Field>
                        <Field label="Cihaz saat sapması (ms)" hint="Telefon/uygulama saatine göre"><input v-model="editForm.checks.device_clock_offset_ms" type="number" class="input"></Field>
                        <Field label="Takıldığı el">
                            <select v-model="editForm.checks.worn_on" class="input">
                                <option value="left">Sol</option>
                                <option value="right">Sağ</option>
                            </select>
                        </Field>
                    </template>

                    <Field label="Not" class="col-span-2"><input v-model="editForm.notes" type="text" class="input"></Field>
                    <div class="col-span-2 flex justify-end"><button type="submit" class="btn btn-primary btn-sm" :disabled="editForm.processing">Kaydet</button></div>
                </form>
            </li>
            <li v-if="!devices.length" class="px-4 py-3 text-sm text-stone-500">Oturuma cihaz eklenmedi.</li>
        </ul>

        <form class="flex gap-2 border-t border-stone-200 p-3" @submit.prevent="add">
            <select v-model="addForm.device_profile_id" class="input" required>
                <option value="" disabled>Cihaz seç</option>
                <option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.modality_label }} · {{ profile.name }}</option>
            </select>
            <button type="submit" class="btn btn-secondary" :disabled="addForm.processing">Ekle</button>
        </form>
        <p v-if="addForm.errors.device_profile_id" class="px-3 pb-3 text-xs text-red-700">{{ addForm.errors.device_profile_id }}</p>
    </section>
</template>
