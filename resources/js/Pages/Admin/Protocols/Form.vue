<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolTabs from '@/Components/ProtocolTabs.vue';
import DesignWarnings from '@/Components/DesignWarnings.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({
    protocol: Object,
    phases: Array,
    providers: Array,
    existingProtocols: Array,
    providerKeys: Object,
    designWarnings: { type: Array, default: () => [] },
});

const editing = computed(() => Boolean(props.protocol.id));
const locked = computed(() => props.protocol.is_frozen);
const section = ref('general');

const form = useForm({
    code: props.protocol.code,
    name: props.protocol.name,
    phase: props.protocol.phase,
    description: props.protocol.description ?? '',
    concept_title: props.protocol.concept_title ?? '',
    concept_rationale: props.protocol.concept_rationale ?? '',
    system_prompt_direct: props.protocol.system_prompt_direct,
    system_prompt_socratic: props.protocol.system_prompt_socratic,
    copy_from: null,
    settings: JSON.parse(JSON.stringify(props.protocol.settings)),
});

const provider = computed(() => props.providers.find((p) => p.value === form.settings.assistant.provider));
const blockSizes = computed({
    get: () => form.settings.stratification.block_sizes.join(', '),
    set: (value) => { form.settings.stratification.block_sizes = value.split(',').map((v) => Number(v.trim())).filter(Boolean); },
});

const sections = [
    { key: 'general', label: 'Genel' },
    { key: 'assistant', label: 'YZ koşulları' },
    { key: 'session', label: 'Oturum akışı' },
    { key: 'sample', label: 'Örneklem ve tabakalama' },
    { key: 'quality', label: 'Kalite eşikleri' },
    { key: 'pilot', label: 'Pilot kabul ölçütleri' },
];

const err = (key) => form.errors[key];

function submit() {
    if (editing.value) {
        form.put(route('admin.protocols.update', props.protocol.id), { preserveScroll: true });
    } else {
        form.post(route('admin.protocols.store'));
    }
}
</script>

<template>
    <Head :title="editing ? protocol.code : 'Yeni protokol sürümü'" />

    <AppLayout>
        <PageHeader :title="editing ? `${protocol.code} · ${protocol.name}` : 'Yeni protokol sürümü'">
            <template #breadcrumb><Link :href="route('admin.protocols.index')" class="hover:underline">Protokol sürümleri</Link></template>
        </PageHeader>

        <ProtocolTabs v-if="editing" :protocol="protocol" />

        <DesignWarnings v-if="editing" :warnings="designWarnings" />

        <div v-if="locked" class="mb-4 rounded border border-stone-300 bg-stone-100 px-3 py-2 text-sm">Bu sürüm dondurulmuş; alanlar salt okunur. Değişiklik için yeni bir sürüm oluşturup içerikleri kopyalayın.</div>

        <form class="grid gap-6 lg:grid-cols-[12rem_1fr]" @submit.prevent="submit">
            <nav class="flex flex-row flex-wrap gap-1 lg:flex-col">
                <button
                    v-for="item in sections"
                    :key="item.key"
                    type="button"
                    class="rounded px-3 py-1.5 text-left text-sm"
                    :class="section === item.key ? 'bg-accent-soft font-medium text-accent-strong' : 'text-stone-600 hover:bg-stone-200/60'"
                    @click="section = item.key"
                >{{ item.label }}</button>
            </nav>

            <fieldset :disabled="locked" class="space-y-6">
                <section v-show="section === 'general'" class="card space-y-4 p-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <Field label="Kod" :error="err('code')" hint="ör. MAIN-v1"><input v-model="form.code" type="text" class="input code"></Field>
                        <Field label="Ad" :error="err('name')" class="sm:col-span-2"><input v-model="form.name" type="text" class="input"></Field>
                        <Field label="Aşama" :error="err('phase')">
                            <select v-model="form.phase" class="input"><option v-for="p in phases" :key="p.value" :value="p.value">{{ p.label }}</option></select>
                        </Field>
                        <Field v-if="!editing" label="İçerikleri kopyala" hint="Görevler, kaynaklar ve rubrik kopyalanır" class="sm:col-span-2">
                            <select v-model="form.copy_from" class="input">
                                <option :value="null">Boş başla</option>
                                <option v-for="p in existingProtocols" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
                            </select>
                        </Field>
                    </div>
                    <Field label="Açıklama" :error="err('description')"><textarea v-model="form.description" rows="2" class="input" /></Field>
                    <Field label="Öğretilen kavram" :error="err('concept_title')"><input v-model="form.concept_title" type="text" class="input"></Field>
                    <Field label="Kavram seçim gerekçesi" :error="err('concept_rationale')" hint="Yeni olma, 20–30 dk'da öğretilebilme, yakın/uzak transfere uygunluk, ezberle çözülememe"><textarea v-model="form.concept_rationale" rows="4" class="input" /></Field>
                </section>

                <section v-show="section === 'assistant'" class="space-y-6">
                    <div class="card space-y-4 p-4">
                        <h2 class="card-title">Model ve üretim parametreleri</h2>
                        <p class="text-xs text-stone-500">İki YZ koşulu aynı modeli ve aynı parametreleri kullanır. Ayarlar oturum hazırlanırken oturuma kopyalanır ve deney boyunca değişmez.</p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <Field label="Sağlayıcı" :error="err('settings.assistant.provider')">
                                <select v-model="form.settings.assistant.provider" class="input"><option v-for="p in providers" :key="p.value" :value="p.value">{{ p.label }}</option></select>
                            </Field>
                            <Field label="Model" :error="err('settings.assistant.model')" :hint="form.settings.assistant.provider === 'anthropic' ? 'Boşsa claude-opus-5-5' : ''">
                                <input v-model="form.settings.assistant.model" type="text" class="input code">
                            </Field>
                            <Field label="Maksimum yanıt (token)" :error="err('settings.assistant.max_tokens')"><input v-model.number="form.settings.assistant.max_tokens" type="number" class="input"></Field>
                            <Field v-if="provider?.supports_temperature" label="Sıcaklık" :error="err('settings.assistant.temperature')"><input v-model.number="form.settings.assistant.temperature" type="number" step="0.05" class="input"></Field>
                            <Field v-if="form.settings.assistant.provider === 'anthropic'" label="Effort" hint="Claude'da sıcaklık yerine kullanılır" :error="err('settings.assistant.effort')">
                                <select v-model="form.settings.assistant.effort" class="input">
                                    <option v-for="e in ['low', 'medium', 'high', 'xhigh', 'max']" :key="e" :value="e">{{ e }}</option>
                                </select>
                            </Field>
                            <Field label="Bağlamdaki konuşma turu" :error="err('settings.assistant.history_turns')"><input v-model.number="form.settings.assistant.history_turns" type="number" class="input"></Field>
                        </div>
                        <p v-if="form.settings.assistant.provider === 'scripted'" class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-900">Simülasyon sürücüsü yalnızca kuru prova ve yazılım testi içindir; gerçek katılımcıyla kullanmayın.</p>
                        <p v-else-if="!providerKeys[form.settings.assistant.provider]" class="rounded bg-red-50 px-3 py-2 text-xs text-red-800">Bu sağlayıcı için .env dosyasında API anahtarı tanımlı değil.</p>
                    </div>

                    <div class="card space-y-4 p-4">
                        <h2 class="card-title">Sokratik koruma</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <Field label="İzin verilen en fazla kod satırı" hint="Aşılırsa yanıt yeniden üretilir, yine aşılırsa kod kaldırılır" :error="err('settings.assistant.socratic_max_code_lines')"><input v-model.number="form.settings.assistant.socratic_max_code_lines" type="number" class="input"></Field>
                            <Field label="Referans çözüme benzerlik eşiği" hint="0–1, token Jaccard" :error="err('settings.assistant.socratic_similarity_threshold')"><input v-model.number="form.settings.assistant.socratic_similarity_threshold" type="number" step="0.05" class="input"></Field>
                        </div>
                    </div>

                    <div class="card space-y-4 p-4">
                        <Field label="Sistem istemi · Doğrudan çözüm veren YZ" :error="err('system_prompt_direct')"><textarea v-model="form.system_prompt_direct" rows="12" class="input code" /></Field>
                        <Field label="Sistem istemi · Sokratik YZ" :error="err('system_prompt_socratic')"><textarea v-model="form.system_prompt_socratic" rows="14" class="input code" /></Field>
                        <p class="text-xs text-stone-500">Görev metni, testler, katılımcının kodu ve son çıktı her iki koşulda da aynı biçimde isteme eklenir. Referans çözüm hiçbir modele gönderilmez.</p>
                    </div>
                </section>

                <section v-show="section === 'session'" class="card grid gap-4 p-4 sm:grid-cols-3">
                    <Field label="Göz açık dinlenim (sn)" :error="err('settings.session.eyes_open_seconds')"><input v-model.number="form.settings.session.eyes_open_seconds" type="number" class="input"></Field>
                    <Field label="Göz kapalı dinlenim (sn)" :error="err('settings.session.eyes_closed_seconds')"><input v-model.number="form.settings.session.eyes_closed_seconds" type="number" class="input"></Field>
                    <Field label="Bileklik senkron vuruşu" :error="err('settings.session.sync_gesture_taps')"><input v-model.number="form.settings.session.sync_gesture_taps" type="number" class="input"></Field>
                    <Field label="Gecikme en az (gün)" :error="err('settings.session.delay_min_days')"><input v-model.number="form.settings.session.delay_min_days" type="number" class="input"></Field>
                    <Field label="Gecikme en çok (gün)" :error="err('settings.session.delay_max_days')"><input v-model.number="form.settings.session.delay_max_days" type="number" class="input"></Field>
                    <Field label="Öğrenme hedef süresi (dk)" :error="err('settings.session.learning_target_minutes')"><input v-model.number="form.settings.session.learning_target_minutes" type="number" class="input"></Field>
                    <Field label="1. oturum hedef süresi (dk)" :error="err('settings.session.session1_target_minutes')"><input v-model.number="form.settings.session.session1_target_minutes" type="number" class="input"></Field>
                    <Field label="2. oturum hedef süresi (dk)" :error="err('settings.session.session2_target_minutes')"><input v-model.number="form.settings.session.session2_target_minutes" type="number" class="input"></Field>
                </section>

                <section v-show="section === 'sample'" class="card grid gap-4 p-4 sm:grid-cols-3">
                    <Field label="Koşul başına tamamlayan hedefi" :error="err('settings.sample.completers_per_condition')"><input v-model.number="form.settings.sample.completers_per_condition" type="number" class="input"></Field>
                    <Field label="Toplam kayıt hedefi" hint="%20–25 kayıp payı dahil" :error="err('settings.sample.enrolment_target')"><input v-model.number="form.settings.sample.enrolment_target" type="number" class="input"></Field>
                    <div />
                    <Field label="Düşük tabaka üst sınırı (ön test %)" :error="err('settings.stratification.low_upper_percent')"><input v-model.number="form.settings.stratification.low_upper_percent" type="number" class="input"></Field>
                    <Field label="Orta tabaka üst sınırı (ön test %)" :error="err('settings.stratification.medium_upper_percent')"><input v-model.number="form.settings.stratification.medium_upper_percent" type="number" class="input"></Field>
                    <Field label="Blok büyüklükleri" hint="3'ün katları, virgülle" :error="err('settings.stratification.block_sizes')"><input v-model="blockSizes" type="text" class="input"></Field>
                    <Field label="Çift puanlanacak açıklama oranı (%)" :error="err('settings.rating.double_rating_percent')"><input v-model.number="form.settings.rating.double_rating_percent" type="number" class="input"></Field>
                    <Field label="Kavram ön kontrolü bayrak eşiği (%)" hint="Üstünde alan katılımcı işaretlenir; otomatik dışlama yapılmaz" :error="err('settings.screening.concept_precheck_exclude_percent')"><input v-model.number="form.settings.screening.concept_precheck_exclude_percent" type="number" class="input"></Field>
                </section>

                <section v-show="section === 'quality'" class="card grid gap-4 p-4 sm:grid-cols-3">
                    <Field label="En az kullanılabilir EEG epok oranı" :error="err('settings.quality.min_eeg_usable_epoch_ratio')"><input v-model.number="form.settings.quality.min_eeg_usable_epoch_ratio" type="number" step="0.01" class="input"></Field>
                    <Field label="En az geçerli bakış oranı" :error="err('settings.quality.min_gaze_valid_ratio')"><input v-model.number="form.settings.quality.min_gaze_valid_ratio" type="number" step="0.01" class="input"></Field>
                    <Field label="En çok bileklik veri kaybı" :error="err('settings.quality.max_wearable_loss_ratio')"><input v-model.number="form.settings.quality.max_wearable_loss_ratio" type="number" step="0.01" class="input"></Field>
                    <Field label="En az marker bütünlüğü" :error="err('settings.quality.min_marker_integrity')"><input v-model.number="form.settings.quality.min_marker_integrity" type="number" step="0.001" class="input"></Field>
                    <Field label="En çok kalibrasyon hatası (°)" :error="err('settings.quality.max_calibration_error_deg')"><input v-model.number="form.settings.quality.max_calibration_error_deg" type="number" step="0.1" class="input"></Field>
                </section>

                <section v-show="section === 'pilot'" class="card grid gap-4 p-4 sm:grid-cols-3">
                    <Field label="En az görev tamamlama oranı" :error="err('settings.pilot.min_task_completion_rate')"><input v-model.number="form.settings.pilot.min_task_completion_rate" type="number" step="0.01" class="input"></Field>
                    <Field label="Uzak transfer taban (%)" :error="err('settings.pilot.far_transfer_floor_percent')"><input v-model.number="form.settings.pilot.far_transfer_floor_percent" type="number" class="input"></Field>
                    <Field label="Uzak transfer tavan (%)" :error="err('settings.pilot.far_transfer_ceiling_percent')"><input v-model.number="form.settings.pilot.far_transfer_ceiling_percent" type="number" class="input"></Field>
                    <Field label="Doğrudan YZ en az tam kod oranı" :error="err('settings.pilot.min_direct_full_code_rate')"><input v-model.number="form.settings.pilot.min_direct_full_code_rate" type="number" step="0.01" class="input"></Field>
                    <Field label="Doğrudan YZ açık çözüm talebini karşılama oranı" :error="err('settings.pilot.min_direct_request_fulfilment_rate')"><input v-model.number="form.settings.pilot.min_direct_request_fulfilment_rate" type="number" step="0.01" class="input"></Field>
                    <Field label="Sokratik en çok sızıntı oranı" :error="err('settings.pilot.max_socratic_leak_rate')"><input v-model.number="form.settings.pilot.max_socratic_leak_rate" type="number" step="0.01" class="input"></Field>
                    <Field label="Sokratik yanıt başına en az soru" :error="err('settings.pilot.min_socratic_questions_per_reply')"><input v-model.number="form.settings.pilot.min_socratic_questions_per_reply" type="number" step="0.1" class="input"></Field>
                    <Field label="Görev başına en çok planlama ihlali" :error="err('settings.pilot.max_planning_violations_per_task')"><input v-model.number="form.settings.pilot.max_planning_violations_per_task" type="number" step="0.1" class="input"></Field>
                </section>

                <div v-if="!locked" class="flex items-center justify-end gap-3">
                    <p v-if="form.hasErrors" class="text-sm text-red-700">Formda hatalar var; bölümleri kontrol edin.</p>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
                </div>
            </fieldset>
        </form>
    </AppLayout>
</template>
