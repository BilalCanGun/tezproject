<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDateTime } from '@/lib/format';

defineProps({
    protocols: Array,
});

const freezing = ref(null);
const freezeForm = useForm({ confirmation: '' });

function activate(protocol) {
    if (confirm(`${protocol.code} etkin protokol yapılsın mı? Yeni katılımcılar bu sürüme kaydedilecek.`)) {
        router.post(route('admin.protocols.activation.store', protocol.id), {}, { preserveScroll: true });
    }
}

function freeze(protocol) {
    freezeForm.post(route('admin.protocols.freeze.store', protocol.id), {
        preserveScroll: true,
        onSuccess: () => { freezing.value = null; freezeForm.reset(); },
    });
}
</script>

<template>
    <Head title="Protokol sürümleri" />

    <AppLayout>
        <PageHeader title="Protokol sürümleri" subtitle="Model ayarları, görevler, rubrik ve kalite eşikleri sürüm bazında tutulur. Ana veri toplamadan önce sürüm dondurulur; dondurulan sürüm değiştirilemez.">
            <template #actions>
                <Link :href="route('admin.protocols.create')" class="btn btn-primary">Yeni sürüm</Link>
            </template>
        </PageHeader>

        <div class="space-y-3">
            <div v-for="protocol in protocols" :key="protocol.id" class="card">
                <div class="flex flex-wrap items-start gap-4 p-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="code font-semibold">{{ protocol.code }}</span>
                            <span class="badge bg-stone-100 text-stone-700">{{ protocol.phase_label }}</span>
                            <span v-if="protocol.is_active" class="badge bg-accent text-white">Etkin</span>
                            <span v-if="protocol.frozen_at" class="badge bg-stone-800 text-white">Donduruldu · {{ formatDateTime(protocol.frozen_at) }}</span>
                        </div>
                        <p class="mt-1 text-sm">{{ protocol.name }}</p>
                        <p class="mt-1 text-xs text-stone-500">
                            {{ protocol.provider_label }}<template v-if="protocol.model"> · {{ protocol.model }}</template>
                            · {{ protocol.tasks_count }} görev · {{ protocol.resources_count }} kaynak sayfası · {{ protocol.rubric_criteria_count }} rubrik ölçütü · {{ protocol.participants_count }} katılımcı
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('admin.protocols.edit', protocol.id)" class="btn btn-secondary btn-sm">Aç</Link>
                        <button v-if="!protocol.is_active" type="button" class="btn btn-secondary btn-sm" @click="activate(protocol)">Etkinleştir</button>
                        <button v-if="!protocol.frozen_at" type="button" class="btn btn-secondary btn-sm" @click="freezing = freezing === protocol.id ? null : protocol.id">Dondur</button>
                    </div>
                </div>

                <form v-if="freezing === protocol.id" class="border-t border-stone-200 bg-stone-50 p-4" @submit.prevent="freeze(protocol)">
                    <p class="text-sm">Dondurma geri alınamaz. Model, sistem istemleri, görevler, rubrik ve eşikler bu sürüm için kilitlenir; ayarların özeti (SHA-256) işlem kaydına yazılır.</p>
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <div>
                            <label class="label">Onay için <span class="code">{{ protocol.code }}</span> yazın</label>
                            <input v-model="freezeForm.confirmation" type="text" class="input w-56">
                        </div>
                        <button type="submit" class="btn btn-danger" :disabled="freezeForm.processing">Sürümü dondur</button>
                    </div>
                    <p v-if="freezeForm.errors.confirmation" class="mt-1 text-xs text-red-700">{{ freezeForm.errors.confirmation }}</p>
                </form>
            </div>
            <p v-if="!protocols.length" class="card p-6 text-center text-sm text-stone-500">Henüz protokol sürümü yok.</p>
        </div>
    </AppLayout>
</template>
