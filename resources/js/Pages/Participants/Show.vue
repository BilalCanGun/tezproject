<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import ConditionBadge from '@/Components/ConditionBadge.vue';
import { formatDateTime, sessionStatusStyles } from '@/lib/format';

const props = defineProps({
    participant: Object,
    sessions: Array,
    canRandomize: Boolean,
    screeningItems: Object,
    delayWindow: Object,
});

const isAdmin = usePage().props.auth.user.is_admin;
const scheduling = ref(null);
const showClosing = ref(false);

const scheduleForm = useForm({ session_number: null, scheduled_at: '', window_justification: '' });
const closeForm = useForm({ status: 'withdrawn', reason: '' });

function openSchedule(kind) {
    scheduling.value = kind;
    scheduleForm.reset();
    scheduleForm.clearErrors();
    scheduleForm.session_number = kind;
}

function schedule() {
    scheduleForm.post(route('participants.sessions.store', props.participant.id), { preserveScroll: true });
}

function randomize() {
    if (confirm(`${props.participant.code} bir koşula atanacak. Bu işlem geri alınamaz. Devam edilsin mi?`)) {
        router.post(route('participants.randomization.store', props.participant.id), {}, { preserveScroll: true });
    }
}

function closeParticipant() {
    closeForm.post(route('participants.exclusion.store', props.participant.id), {
        preserveScroll: true,
        onSuccess: () => { showClosing.value = false; },
    });
}

function purgeContact() {
    if (confirm('İletişim bilgileri kalıcı olarak silinecek. Araştırma verileri korunur. Emin misiniz?')) {
        router.delete(route('admin.participants.contact.destroy', props.participant.id), { preserveScroll: true });
    }
}

const labels = {
    gender: { female: 'Kadın', male: 'Erkek', other: 'Diğer', undisclosed: 'Belirtilmedi' },
    occupation_type: { student: 'Öğrenci', employee: 'Çalışan', other: 'Diğer' },
    vision_correction: { none: 'Yok', glasses: 'Gözlük', contacts: 'Lens' },
    data_sharing_level: { open_anonymized: 'Anonim açık paylaşım', controlled: 'Kontrollü erişim', none: 'Paylaşım yok' },
};
</script>

<template>
    <Head :title="participant.code" />

    <AppLayout>
        <PageHeader :title="participant.code" :subtitle="`${participant.protocol.code} · ${participant.status_label}`">
            <template #breadcrumb>
                <Link :href="route('participants.index')" class="hover:underline">Katılımcılar</Link>
            </template>
            <template #actions>
                <Link :href="route('participants.edit', participant.id)" class="btn btn-secondary">Düzenle</Link>
                <button v-if="!['withdrawn', 'excluded', 'ineligible'].includes(participant.status)" type="button" class="btn btn-secondary" @click="showClosing = !showClosing">Çekilme / dışlama</button>
            </template>
        </PageHeader>

        <div v-if="showClosing" class="card mb-6 p-4">
            <form class="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end" @submit.prevent="closeParticipant">
                <Field label="Tür">
                    <select v-model="closeForm.status" class="input">
                        <option value="withdrawn">Katılımcı çekildi</option>
                        <option value="excluded">Önceden tanımlı ölçütle dışlandı</option>
                    </select>
                </Field>
                <Field label="Gerekçe" :error="closeForm.errors.reason">
                    <input v-model="closeForm.reason" type="text" class="input" placeholder="ör. 2. oturuma gelmedi; EEG kaydı kullanılamaz">
                </Field>
                <button type="submit" class="btn btn-danger" :disabled="closeForm.processing">Kaydet</button>
            </form>
            <p class="mt-2 text-xs text-stone-500">Toplanan veriler silinmez; analizde duruma göre ele alınır.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Çalışma akışı</h2>
                    </div>
                    <ol class="divide-y divide-stone-100">
                        <li v-for="row in sessions" :key="row.kind" class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium">{{ row.label }}</p>
                                    <p v-if="row.session" class="text-xs text-stone-500">
                                        {{ row.session.started_at ? 'Başladı ' + formatDateTime(row.session.started_at) : 'Plan: ' + formatDateTime(row.session.scheduled_at) }}
                                        <template v-if="row.session.duration_min"> · {{ row.session.duration_min }} dk</template>
                                        <template v-if="row.session.conductor"> · {{ row.session.conductor }}</template>
                                        <template v-if="row.session.quality"> · Kalite: {{ row.session.quality }}</template>
                                    </p>
                                </div>
                                <span v-if="row.session" class="badge" :class="sessionStatusStyles[row.session.status]">{{ row.session.status_label }}</span>
                                <Link v-if="row.session" :href="route('sessions.show', row.session.id)" class="btn btn-secondary btn-sm">Oturumu aç</Link>
                                <button v-else-if="row.can_schedule" type="button" class="btn btn-primary btn-sm" @click="openSchedule(row.kind)">Planla</button>
                            </div>

                            <form v-if="scheduling === row.kind" class="mt-3 grid gap-3 rounded border border-stone-200 bg-stone-50 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" @submit.prevent="schedule">
                                <Field label="Tarih ve saat" :error="scheduleForm.errors.scheduled_at" :hint="row.kind === 2 ? `1. oturumdan ${delayWindow.min}–${delayWindow.max} gün sonra` : ''">
                                    <input v-model="scheduleForm.scheduled_at" type="datetime-local" class="input">
                                </Field>
                                <Field v-if="row.kind === 2" label="Pencere dışıysa gerekçe">
                                    <input v-model="scheduleForm.window_justification" type="text" class="input">
                                </Field>
                                <div v-else />
                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-ghost" @click="scheduling = null">Vazgeç</button>
                                    <button type="submit" class="btn btn-primary" :disabled="scheduleForm.processing">Oluştur</button>
                                </div>
                            </form>
                        </li>
                    </ol>
                </section>

                <section class="card">
                    <div class="card-header"><h2 class="card-title">Ön eleme yanıtları</h2></div>
                    <ul class="divide-y divide-stone-100 text-sm">
                        <li v-for="(item, key) in screeningItems" :key="key" class="flex items-center justify-between gap-3 px-4 py-2">
                            <span>{{ item.label }}</span>
                            <span class="badge" :class="participant.screening?.[key] === item.expected ? 'bg-stone-100 text-stone-700' : 'bg-red-50 text-red-800'">
                                {{ participant.screening?.[key] ? 'Evet' : 'Hayır' }}
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="space-y-6">
                <section class="card p-4">
                    <h2 class="card-title mb-3">Atama</h2>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <dt class="text-stone-500">Koşul</dt>
                        <dd><ConditionBadge :condition="participant.condition" :label="participant.condition_label" /></dd>
                        <dt class="text-stone-500">Ön test</dt>
                        <dd>{{ participant.baseline_pct !== null ? `%${participant.baseline_pct} (${participant.baseline_score}/${participant.baseline_max})` : '—' }}</dd>
                        <dt class="text-stone-500">Kavram ön kontrolü</dt>
                        <dd>{{ participant.concept_precheck_pct !== null ? `%${participant.concept_precheck_pct} (${participant.concept_precheck_score}/${participant.concept_precheck_max})` : '—' }}</dd>
                        <dt class="text-stone-500">Tabaka</dt>
                        <dd>{{ participant.stratum_label ?? '—' }}</dd>
                        <dt class="text-stone-500">Atama zamanı</dt>
                        <dd>{{ formatDateTime(participant.randomized_at) }}</dd>
                    </dl>
                    <button v-if="canRandomize" type="button" class="btn btn-primary mt-4 w-full" @click="randomize">Tabakalı rastgele ata</button>
                    <p v-else-if="!participant.condition" class="mt-3 text-xs text-stone-500">Atama için onam kaydı ve tamamlanmış ön değerlendirme gerekir.</p>
                </section>

                <section v-if="participant.review_flags.length || participant.exclusion_reason || participant.may_know_concept" class="card border-amber-300 p-4">
                    <h2 class="card-title mb-2">Dikkat</h2>
                    <p v-if="participant.may_know_concept" class="mb-2 text-sm text-amber-900">
                        Kavramı önceden biliyor olabilir: kavram ön kontrolünde %{{ participant.concept_precheck_pct }} aldı (eşik %{{ participant.concept_precheck_threshold }}). Otomatik dışlama yapılmadı; dışlama kararı araştırmacıya aittir.
                    </p>
                    <p v-if="participant.exclusion_reason" class="text-sm text-red-800">{{ participant.exclusion_reason }}</p>
                    <ul v-if="participant.review_flags.length" class="list-disc pl-5 text-sm text-amber-900">
                        <li v-for="flag in participant.review_flags" :key="flag">{{ flag }}</li>
                    </ul>
                </section>

                <section class="card p-4">
                    <h2 class="card-title mb-3">Profil</h2>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <dt class="text-stone-500">Yaş</dt><dd>{{ participant.age ?? '—' }}</dd>
                        <dt class="text-stone-500">Cinsiyet</dt><dd>{{ labels.gender[participant.gender] ?? '—' }}</dd>
                        <dt class="text-stone-500">Statü</dt><dd>{{ labels.occupation_type[participant.occupation_type] ?? '—' }}</dd>
                        <dt class="text-stone-500">Deneyim</dt><dd>{{ participant.programming_experience_months !== null ? participant.programming_experience_months + ' ay' : '—' }}</dd>
                        <dt class="text-stone-500">Görme düzeltmesi</dt><dd>{{ labels.vision_correction[participant.vision_correction] ?? '—' }}</dd>
                        <dt class="text-stone-500">Onam</dt><dd>{{ participant.consent_version ?? '—' }} · {{ formatDateTime(participant.consented_at) }}</dd>
                        <dt class="text-stone-500">Paylaşım</dt><dd>{{ labels.data_sharing_level[participant.data_sharing_level] ?? '—' }}</dd>
                        <dt class="text-stone-500">Kaydeden</dt><dd>{{ participant.created_by ?? '—' }}</dd>
                    </dl>
                    <p v-if="participant.notes" class="mt-3 border-t border-stone-100 pt-3 text-sm whitespace-pre-line text-stone-700">{{ participant.notes }}</p>
                </section>

                <section class="card p-4">
                    <h2 class="card-title mb-3">İletişim</h2>
                    <dl v-if="participant.contact" class="space-y-1 text-sm">
                        <div>{{ participant.contact.full_name ?? '—' }}</div>
                        <div class="text-stone-600">{{ participant.contact.email ?? '—' }}</div>
                        <div class="text-stone-600">{{ participant.contact.phone ?? '—' }}</div>
                        <div class="text-xs text-stone-500">Silinme tarihi: {{ participant.contact.purge_after ?? 'belirtilmedi' }}</div>
                    </dl>
                    <p v-else class="text-sm text-stone-500">Kayıtlı iletişim bilgisi yok.</p>
                    <button v-if="isAdmin && participant.contact" type="button" class="btn btn-secondary btn-sm mt-3" @click="purgeContact">İletişim verisini sil</button>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
