<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Markdown from '@/Components/Markdown.vue';

const props = defineProps({
    attempt: Object,
    existing: Object,
});

const key = computed(() => props.attempt.scoring_key ?? {});

const form = useForm({
    score: props.existing?.score ?? props.attempt.auto_score ?? 0,
    partial_credit: props.existing?.partial_credit ?? [],
    misconceptions: props.existing?.misconceptions ?? [],
    bugs_found: props.existing?.bugs_found ?? [],
    alternative_solution: props.existing?.alternative_solution ?? false,
    notes: props.existing?.notes ?? '',
});

const suggested = computed(() => (key.value.partial_credit ?? [])
    .filter((_, index) => form.partial_credit.includes(index))
    .reduce((sum, item) => sum + Number(item.points || 0), 0));

function submit() {
    form.put(route('code-reviews.update', props.attempt.id));
}
</script>

<template>
    <Head :title="`Kod puanlama ${attempt.blind_label}`" />

    <AppLayout>
        <PageHeader :title="`Çözüm ${attempt.blind_label}`" :subtitle="`${attempt.kind_label} · ${attempt.task_title}`">
            <template #breadcrumb><Link :href="route('code-reviews.index')" class="hover:underline">Kod puanlama</Link></template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-6">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Katılımcının kodu</h2>
                        <span class="text-xs text-stone-500">Testler: {{ attempt.tests_passed ?? 0 }} / {{ attempt.tests_total ?? 0 }} · otomatik puan {{ attempt.auto_score ?? '—' }} / {{ attempt.max_score }}</span>
                    </div>
                    <pre class="code overflow-x-auto p-4 text-xs">{{ attempt.final_code || '(boş)' }}</pre>
                </section>
                <details class="card">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Görev metni</summary>
                    <div class="border-t border-stone-200 p-4"><Markdown :source="attempt.task_prompt" /></div>
                </details>
                <details class="card">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Başlangıç kodu ve referans çözüm</summary>
                    <div class="space-y-3 border-t border-stone-200 p-4">
                        <pre class="code overflow-x-auto rounded bg-stone-50 p-2 text-xs">{{ attempt.starter_code }}</pre>
                        <pre class="code overflow-x-auto rounded bg-stone-50 p-2 text-xs">{{ attempt.reference_solution }}</pre>
                    </div>
                </details>
                <details v-if="key.alternative_solutions?.length" class="card">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Alternatif doğru çözümler</summary>
                    <div class="space-y-2 border-t border-stone-200 p-4">
                        <pre v-for="(item, i) in key.alternative_solutions" :key="i" class="code overflow-x-auto rounded bg-stone-50 p-2 text-xs">{{ item }}</pre>
                    </div>
                </details>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <section v-if="key.expected_approach" class="card p-4 text-sm">
                    <p class="font-medium">Beklenen çözüm yaklaşımı</p>
                    <p class="whitespace-pre-wrap text-stone-700">{{ key.expected_approach }}</p>
                </section>

                <section v-if="key.partial_credit?.length" class="card space-y-2 p-4">
                    <p class="text-sm font-medium">Karşılanan kısmi puan ölçütleri</p>
                    <label v-for="(item, index) in key.partial_credit" :key="index" class="flex items-start gap-2 text-sm">
                        <input v-model="form.partial_credit" type="checkbox" :value="index" class="mt-0.5 rounded border-stone-300">
                        <span>{{ item.criterion }} <span class="text-stone-500">({{ item.points }} puan)</span></span>
                    </label>
                    <p class="text-xs text-stone-500">İşaretlenen ölçütlerin toplamı: {{ suggested }}</p>
                </section>

                <section v-if="key.misconceptions?.length" class="card space-y-2 p-4">
                    <p class="text-sm font-medium">Görülen kavramsal hatalar</p>
                    <label v-for="item in key.misconceptions" :key="item.code" class="flex items-start gap-2 text-sm">
                        <input v-model="form.misconceptions" type="checkbox" :value="item.code" class="mt-0.5 rounded border-stone-300">
                        <span><span class="code">{{ item.code }}</span> — {{ item.description }}</span>
                    </label>
                </section>

                <section v-if="key.target_bugs?.length" class="card space-y-2 p-4">
                    <p class="text-sm font-medium">Bulunup düzeltilen hatalar</p>
                    <label v-for="(item, index) in key.target_bugs" :key="index" class="flex items-start gap-2 text-sm">
                        <input v-model="form.bugs_found" type="checkbox" :value="index" class="mt-0.5 rounded border-stone-300">
                        <span><span v-if="item.line_hint" class="code">{{ item.line_hint }}: </span>{{ item.description }}</span>
                    </label>
                </section>

                <section class="card space-y-3 p-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.alternative_solution" type="checkbox" class="rounded border-stone-300">
                        Referanstan farklı ama doğru (alternatif) bir çözüm
                    </label>
                    <div>
                        <label class="label">İnsan puanı (0–{{ attempt.max_score }})</label>
                        <input v-model.number="form.score" type="number" step="0.5" min="0" :max="attempt.max_score" class="input w-32">
                        <p v-if="form.errors.score" class="mt-1 text-xs text-red-700">{{ form.errors.score }}</p>
                    </div>
                    <div>
                        <label class="label">Not (isteğe bağlı)</label>
                        <textarea v-model="form.notes" rows="2" class="input" />
                    </div>
                </section>

                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet ve sıradakine geç</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
