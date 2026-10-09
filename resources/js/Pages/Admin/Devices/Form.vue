<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({
    device: Object,
    modalities: Array,
    syncMethods: Array,
});

const signalOptions = {
    eeg: ['eeg', 'acc', 'gyro'],
    eye_tracker: ['gaze', 'pupil'],
    wearable: ['eda', 'bvp', 'acc', 'temp', 'gyro', 'hr'],
};

const form = useForm({
    modality: props.device?.modality ?? 'eeg',
    name: props.device?.name ?? '',
    manufacturer: props.device?.manufacturer ?? '',
    model: props.device?.model ?? '',
    channel_count: props.device?.channel_count ?? null,
    channel_labels: props.device?.channel_labels ?? [],
    signals: props.device?.signals ?? [],
    sampling_rate_hz: props.device?.sampling_rate_hz ?? null,
    electrode_type: props.device?.electrode_type ?? null,
    sync_method: props.device?.sync_method ?? 'lsl',
    raw_data_access: props.device?.raw_data_access ?? true,
    requires_subscription: props.device?.requires_subscription ?? false,
    license_notes: props.device?.license_notes ?? '',
    specs_verified: props.device?.specs_verified ?? false,
    notes: props.device?.notes ?? '',
    is_active: props.device?.is_active ?? true,
});

const labelsText = computed({
    get: () => form.channel_labels.join(', '),
    set: (value) => { form.channel_labels = value.split(/[,\s]+/).map((v) => v.trim()).filter(Boolean); },
});

const precision = computed(() => props.syncMethods.find((s) => s.value === form.sync_method)?.precision);

function submit() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));

    if (props.device) {
        form.put(route('admin.devices.update', props.device.id));
    } else {
        form.post(route('admin.devices.store'));
    }
}
</script>

<template>
    <Head :title="device ? device.name : 'Yeni cihaz'" />

    <AppLayout>
        <PageHeader :title="device ? device.name : 'Yeni cihaz profili'">
            <template #breadcrumb><Link :href="route('admin.devices.index')" class="hover:underline">Cihaz profilleri</Link></template>
        </PageHeader>

        <form class="card max-w-3xl space-y-4 p-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-3">
                <Field label="Tür" :error="form.errors.modality">
                    <select v-model="form.modality" class="input"><option v-for="m in modalities" :key="m.value" :value="m.value">{{ m.label }}</option></select>
                </Field>
                <Field label="Ad" class="sm:col-span-2" :error="form.errors.name"><input v-model="form.name" type="text" class="input"></Field>
                <Field label="Üretici"><input v-model="form.manufacturer" type="text" class="input"></Field>
                <Field label="Model"><input v-model="form.model" type="text" class="input"></Field>
                <Field label="Örnekleme (Hz)" :error="form.errors.sampling_rate_hz"><input v-model.number="form.sampling_rate_hz" type="number" step="0.01" class="input"></Field>
            </div>

            <div v-if="form.modality === 'eeg'" class="grid gap-4 sm:grid-cols-3">
                <Field label="Kanal sayısı" :error="form.errors.channel_count"><input v-model.number="form.channel_count" type="number" class="input"></Field>
                <Field label="Elektrot tipi">
                    <select v-model="form.electrode_type" class="input">
                        <option :value="null">—</option>
                        <option value="dry">Kuru</option>
                        <option value="saline">Salin</option>
                        <option value="gel">Jel</option>
                        <option value="semi_dry">Yarı kuru</option>
                    </select>
                </Field>
                <div />
                <Field label="Kanal etiketleri (10-20)" hint="Virgül veya boşlukla ayırın; orta hat ve paryetal-oksipital kapsam bundan hesaplanır" class="sm:col-span-3" :error="form.errors.channel_labels">
                    <input v-model="labelsText" type="text" class="input code">
                </Field>
            </div>

            <div>
                <p class="label">Sinyaller</p>
                <div class="flex flex-wrap gap-3">
                    <label v-for="signal in signalOptions[form.modality]" :key="signal" class="flex items-center gap-1.5 text-sm">
                        <input v-model="form.signals" type="checkbox" :value="signal" class="rounded border-stone-300"> {{ signal.toUpperCase() }}
                    </label>
                </div>
            </div>

            <Field label="Senkronizasyon yöntemi" :hint="precision" :error="form.errors.sync_method">
                <select v-model="form.sync_method" class="input"><option v-for="s in syncMethods" :key="s.value" :value="s.value">{{ s.label }}</option></select>
            </Field>

            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-1.5"><input v-model="form.raw_data_access" type="checkbox" class="rounded border-stone-300"> Ham veri erişimi var</label>
                <label class="flex items-center gap-1.5"><input v-model="form.requires_subscription" type="checkbox" class="rounded border-stone-300"> Ücretli lisans / abonelik gerekiyor</label>
                <label class="flex items-center gap-1.5"><input v-model="form.specs_verified" type="checkbox" class="rounded border-stone-300"> Özellikler proforma ile doğrulandı</label>
                <label class="flex items-center gap-1.5"><input v-model="form.is_active" type="checkbox" class="rounded border-stone-300"> Etkin</label>
            </div>

            <Field label="Lisans ve maliyet notları"><textarea v-model="form.license_notes" rows="2" class="input" /></Field>
            <Field label="Notlar"><textarea v-model="form.notes" rows="3" class="input" /></Field>

            <div class="flex justify-end gap-2">
                <Link :href="route('admin.devices.index')" class="btn btn-secondary">Vazgeç</Link>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
            </div>
        </form>
    </AppLayout>
</template>
