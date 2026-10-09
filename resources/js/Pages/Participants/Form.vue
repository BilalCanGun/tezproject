<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({
    participant: Object,
    screeningItems: Object,
});

const editing = computed(() => Boolean(props.participant));

const form = useForm({
    full_name: props.participant?.full_name ?? '',
    email: props.participant?.email ?? '',
    phone: props.participant?.phone ?? '',
    purge_after: props.participant?.purge_after ?? '',
    consent_version: props.participant?.consent_version ?? '',
    consented_at: props.participant?.consented_at ?? '',
    data_sharing_level: props.participant?.data_sharing_level ?? '',
    age: props.participant?.age ?? '',
    gender: props.participant?.gender ?? '',
    occupation_type: props.participant?.occupation_type ?? '',
    education_level: props.participant?.education_level ?? '',
    programming_experience_months: props.participant?.programming_experience_months ?? '',
    primary_language: props.participant?.primary_language ?? 'Python',
    vision_correction: props.participant?.vision_correction ?? '',
    handedness: props.participant?.handedness ?? '',
    screening: Object.fromEntries(
        Object.keys(props.screeningItems).map((key) => [key, props.participant?.screening?.[key] ?? null]),
    ),
    notes: props.participant?.notes ?? '',
});

const failingItems = computed(() => Object.entries(props.screeningItems)
    .filter(([key, item]) => form.screening[key] !== null && form.screening[key] !== item.expected)
    .map(([key, item]) => ({ key, ...item })));

function submit() {
    const options = { preserveScroll: true };

    form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])));

    if (editing.value) {
        form.put(route('participants.update', props.participant.id), options);
    } else {
        form.post(route('participants.store'), options);
    }
}
</script>

<template>
    <Head :title="editing ? `${participant.code} düzenle` : 'Yeni katılımcı'" />

    <AppLayout>
        <PageHeader :title="editing ? `${participant.code} · bilgileri düzenle` : 'Yeni katılımcı kaydı'" subtitle="Katılımcı kodu kayıt sırasında otomatik verilir. Ön eleme sonucu kayıtla birlikte hesaplanır.">
            <template #breadcrumb>
                <Link :href="route('participants.index')" class="hover:underline">Katılımcılar</Link>
            </template>
        </PageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-6 lg:col-span-2">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Ön eleme</h2>
                        <span class="text-xs text-stone-500">Dahil etme / dışlama ölçütleri</span>
                    </div>
                    <div class="divide-y divide-stone-100">
                        <div v-for="(item, key) in screeningItems" :key="key" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <p class="min-w-0 flex-1 text-sm">
                                {{ item.label }}
                                <span v-if="item.effect === 'review'" class="badge ml-1 bg-amber-50 text-amber-800">değerlendirilir</span>
                            </p>
                            <div class="flex gap-1">
                                <button
                                    v-for="option in [true, false]"
                                    :key="String(option)"
                                    type="button"
                                    class="btn btn-sm w-16"
                                    :class="form.screening[key] === option
                                        ? (option === item.expected ? 'border-accent bg-accent text-white' : 'border-red-700 bg-red-700 text-white')
                                        : 'btn-secondary'"
                                    @click="form.screening[key] = option"
                                >
                                    {{ option ? 'Evet' : 'Hayır' }}
                                </button>
                            </div>
                            <p v-if="form.errors[`screening.${key}`]" class="w-full text-xs text-red-700">Yanıt gerekli.</p>
                        </div>
                    </div>
                    <div v-if="failingItems.length" class="border-t border-stone-200 bg-red-50/60 px-4 py-2 text-xs text-red-800">
                        <template v-if="failingItems.some((i) => i.effect === 'exclude')">Bu yanıtlarla katılımcı “uygun değil” olarak kaydedilecek.</template>
                        <template v-else>Değerlendirme gerektiren yanıt var; katılımcı profilinde işaretlenecek.</template>
                    </div>
                </section>

                <section class="card p-4">
                    <h2 class="card-title mb-3">Demografi ve deneyim</h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <Field label="Yaş" :error="form.errors.age">
                            <input v-model="form.age" type="number" min="18" max="99" class="input">
                        </Field>
                        <Field label="Cinsiyet" :error="form.errors.gender">
                            <select v-model="form.gender" class="input">
                                <option value="">—</option>
                                <option value="female">Kadın</option>
                                <option value="male">Erkek</option>
                                <option value="other">Diğer</option>
                                <option value="undisclosed">Belirtmek istemiyor</option>
                            </select>
                        </Field>
                        <Field label="Statü" :error="form.errors.occupation_type">
                            <select v-model="form.occupation_type" class="input">
                                <option value="">—</option>
                                <option value="student">Öğrenci</option>
                                <option value="employee">Çalışan</option>
                                <option value="other">Diğer</option>
                            </select>
                        </Field>
                        <Field label="Eğitim düzeyi" :error="form.errors.education_level">
                            <select v-model="form.education_level" class="input">
                                <option value="">—</option>
                                <option value="undergraduate">Lisans öğrencisi</option>
                                <option value="graduate">Lisans mezunu</option>
                                <option value="postgraduate">Lisansüstü</option>
                                <option value="other">Diğer</option>
                            </select>
                        </Field>
                        <Field label="Programlama deneyimi (ay)" :error="form.errors.programming_experience_months">
                            <input v-model="form.programming_experience_months" type="number" min="0" class="input">
                        </Field>
                        <Field label="En çok kullandığı dil" :error="form.errors.primary_language">
                            <input v-model="form.primary_language" type="text" class="input">
                        </Field>
                        <Field label="Görme düzeltmesi" hint="Göz izleme kalibrasyonu için" :error="form.errors.vision_correction">
                            <select v-model="form.vision_correction" class="input">
                                <option value="">—</option>
                                <option value="none">Yok</option>
                                <option value="glasses">Gözlük</option>
                                <option value="contacts">Lens</option>
                            </select>
                        </Field>
                        <Field label="El tercihi" :error="form.errors.handedness">
                            <select v-model="form.handedness" class="input">
                                <option value="">—</option>
                                <option value="right">Sağ</option>
                                <option value="left">Sol</option>
                                <option value="both">İki el</option>
                            </select>
                        </Field>
                    </div>
                    <Field label="Notlar" class="mt-4" :error="form.errors.notes">
                        <textarea v-model="form.notes" rows="3" class="input" />
                    </Field>
                </section>
            </div>

            <div class="space-y-6">
                <section class="card p-4">
                    <h2 class="card-title">İletişim bilgileri</h2>
                    <p class="mb-3 text-xs text-stone-500">Yalnızca randevu için. Şifreli saklanır, hiçbir dışa aktarıma girmez.</p>
                    <div class="space-y-3">
                        <Field label="Ad soyad" :error="form.errors.full_name"><input v-model="form.full_name" type="text" class="input" autocomplete="off"></Field>
                        <Field label="E-posta" :error="form.errors.email"><input v-model="form.email" type="email" class="input" autocomplete="off"></Field>
                        <Field label="Telefon" :error="form.errors.phone"><input v-model="form.phone" type="tel" class="input" autocomplete="off"></Field>
                        <Field label="Kişisel veri silme tarihi" hint="Onam formunda belirtilen saklama süresi" :error="form.errors.purge_after">
                            <input v-model="form.purge_after" type="date" class="input">
                        </Field>
                    </div>
                </section>

                <section class="card p-4">
                    <h2 class="card-title mb-3">Onam</h2>
                    <div class="space-y-3">
                        <Field label="Onam formu sürümü" :error="form.errors.consent_version">
                            <input v-model="form.consent_version" type="text" placeholder="ör. v1.2" class="input">
                        </Field>
                        <Field label="Onam tarihi ve saati" :error="form.errors.consented_at">
                            <input v-model="form.consented_at" type="datetime-local" class="input">
                        </Field>
                        <Field label="Veri paylaşım izni" :error="form.errors.data_sharing_level">
                            <select v-model="form.data_sharing_level" class="input">
                                <option value="">—</option>
                                <option value="open_anonymized">Anonimleştirilmiş açık paylaşım</option>
                                <option value="controlled">Yalnızca kontrollü erişim</option>
                                <option value="none">Paylaşım yok (yalnızca bu proje)</option>
                            </select>
                        </Field>
                    </div>
                </section>

                <div class="flex justify-end gap-2">
                    <Link :href="editing ? route('participants.show', participant.id) : route('participants.index')" class="btn btn-secondary">Vazgeç</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
