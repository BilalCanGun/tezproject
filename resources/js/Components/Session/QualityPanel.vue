<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';
import StatusDot from '@/Components/StatusDot.vue';
import { formatDateTime, formatPercent } from '@/lib/format';

const props = defineProps({
    sessionId: { type: Number, required: true },
    quality: { type: Object, required: true },
    recordsPhysiology: { type: Boolean, default: true },
});

const review = props.quality.review;

const form = useForm({
    eeg_usable_epoch_ratio: review?.eeg_usable_epoch_ratio ?? '',
    gaze_valid_ratio: review?.gaze_valid_ratio ?? '',
    wearable_loss_ratio: review?.wearable_loss_ratio ?? '',
    verdict: review?.verdict ?? '',
    needs_repeat: review?.needs_repeat ?? false,
    notes: review?.notes ?? '',
});

const t = props.quality.thresholds;
const integrity = computed(() => (props.quality.markers_expected ? props.quality.markers_acknowledged / props.quality.markers_expected : null));

function stateFor(value, threshold, higherIsBetter = true) {
    if (value === '' || value === null) {
        return 'idle';
    }

    return (higherIsBetter ? Number(value) >= threshold : Number(value) <= threshold) ? 'ok' : 'fail';
}

function save() {
    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])))
        .put(route('sessions.quality-review.update', props.sessionId), { preserveScroll: true });
}
</script>

<template>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Veri kalitesi değerlendirmesi</h2>
            <span v-if="review" class="text-xs text-stone-500">{{ review.reviewer }} · {{ formatDateTime(review.updated_at) }}</span>
        </div>
        <form class="space-y-3 p-4" @submit.prevent="save">
            <div v-if="recordsPhysiology" class="flex items-center gap-2 rounded bg-stone-50 px-3 py-2 text-sm">
                <StatusDot :state="integrity === null ? 'idle' : integrity >= t.min_marker_integrity ? 'ok' : 'fail'" />
                Marker bütünlüğü: <span class="tabular-nums">{{ quality.markers_acknowledged }} / {{ quality.markers_expected }}</span>
                <span class="text-stone-500">({{ formatPercent(integrity) }}, eşik {{ formatPercent(t.min_marker_integrity, 0) }})</span>
            </div>

            <template v-if="recordsPhysiology">
                <Field :label="`Kullanılabilir EEG epok oranı (≥ ${t.min_eeg_usable_epoch_ratio})`" :error="form.errors.eeg_usable_epoch_ratio">
                    <div class="flex items-center gap-2"><StatusDot :state="stateFor(form.eeg_usable_epoch_ratio, t.min_eeg_usable_epoch_ratio)" /><input v-model="form.eeg_usable_epoch_ratio" type="number" step="0.01" min="0" max="1" class="input" placeholder="0–1"></div>
                </Field>
                <Field :label="`Geçerli bakış örneği oranı (≥ ${t.min_gaze_valid_ratio})`" :error="form.errors.gaze_valid_ratio">
                    <div class="flex items-center gap-2"><StatusDot :state="stateFor(form.gaze_valid_ratio, t.min_gaze_valid_ratio)" /><input v-model="form.gaze_valid_ratio" type="number" step="0.01" min="0" max="1" class="input" placeholder="0–1"></div>
                </Field>
                <Field :label="`Bileklik veri kaybı oranı (≤ ${t.max_wearable_loss_ratio})`" :error="form.errors.wearable_loss_ratio">
                    <div class="flex items-center gap-2"><StatusDot :state="stateFor(form.wearable_loss_ratio, t.max_wearable_loss_ratio, false)" /><input v-model="form.wearable_loss_ratio" type="number" step="0.01" min="0" max="1" class="input" placeholder="0–1"></div>
                </Field>
                <p class="text-xs text-stone-500">Oranlar ön işleme betiğinin oturum raporundan girilir. Boş bırakılan ölçüm değerlendirmeye katılmaz.</p>
            </template>

            <Field label="Karar" hint="Boş bırakılırsa eşiklere göre önerilen karar kaydedilir." :error="form.errors.verdict">
                <select v-model="form.verdict" class="input">
                    <option value="">Otomatik öner</option>
                    <option v-for="v in quality.verdicts" :key="v.value" :value="v.value">{{ v.label }}</option>
                </select>
            </Field>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.needs_repeat" type="checkbox" class="rounded border-stone-300">
                Oturum tekrar edilmeli (etik izin ve takvim uygunsa)
            </label>
            <Field label="Not"><textarea v-model="form.notes" rows="2" class="input" /></Field>

            <div v-if="review?.failed_checks?.length" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                Eşik altında kalan ölçümler: {{ review.failed_checks.join(', ') }}
            </div>

            <div class="flex justify-end"><button type="submit" class="btn btn-primary" :disabled="form.processing">Değerlendirmeyi kaydet</button></div>
        </form>
    </section>
</template>
