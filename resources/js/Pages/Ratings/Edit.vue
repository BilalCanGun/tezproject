<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Markdown from '@/Components/Markdown.vue';

const props = defineProps({
    attempt: Object,
    criteria: Array,
    existing: Object,
});

const form = useForm({
    scores: Object.fromEntries(props.criteria.map((c) => [c.key, props.existing?.scores?.[c.key] ?? null])),
    notes: props.existing?.notes ?? '',
});

const complete = computed(() => props.criteria.every((c) => form.scores[c.key] !== null));
const total = computed(() => Object.values(form.scores).reduce((sum, v) => sum + (v ?? 0), 0));

function range(criterion) {
    return Array.from({ length: criterion.max_score - criterion.min_score + 1 }, (_, i) => criterion.min_score + i);
}

function submit() {
    form.put(route('ratings.update', props.attempt.id));
}
</script>

<template>
    <Head :title="`Puanlama ${attempt.blind_label}`" />

    <AppLayout>
        <PageHeader :title="`Yanıt ${attempt.blind_label}`" :subtitle="attempt.task_title">
            <template #breadcrumb><Link :href="route('ratings.index')" class="hover:underline">Açıklama puanlama</Link></template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-6">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Katılımcının açıklaması</h2></div>
                    <p class="p-4 text-sm leading-relaxed whitespace-pre-wrap">{{ attempt.explanation }}</p>
                </section>
                <details class="card">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Görev metni</summary>
                    <div class="border-t border-stone-200 p-4"><Markdown :source="attempt.task_prompt" /></div>
                </details>
                <section v-if="attempt.scoring_key" class="card">
                    <div class="card-header"><h2 class="card-title">Puanlama anahtarı</h2></div>
                    <div class="space-y-3 p-4 text-sm">
                        <div v-if="attempt.scoring_key.explanation_elements?.length">
                            <p class="font-medium">İyi bir açıklamada bulunması gereken unsurlar</p>
                            <ul class="list-disc pl-5 text-stone-700"><li v-for="(item, i) in attempt.scoring_key.explanation_elements" :key="i">{{ item }}</li></ul>
                        </div>
                        <div v-if="attempt.scoring_key.expected_approach">
                            <p class="font-medium">Beklenen çözüm yaklaşımı</p>
                            <p class="whitespace-pre-wrap text-stone-700">{{ attempt.scoring_key.expected_approach }}</p>
                        </div>
                        <div v-if="attempt.scoring_key.misconceptions?.length">
                            <p class="font-medium">Temel kavramsal hatalar</p>
                            <ul class="list-disc pl-5 text-stone-700"><li v-for="(item, i) in attempt.scoring_key.misconceptions" :key="i"><span class="code">{{ item.code }}</span> — {{ item.description }}</li></ul>
                        </div>
                        <div v-if="attempt.scoring_key.partial_credit?.length">
                            <p class="font-medium">Kısmi puan ölçütleri</p>
                            <ul class="list-disc pl-5 text-stone-700"><li v-for="(item, i) in attempt.scoring_key.partial_credit" :key="i">{{ item.criterion }} ({{ item.points }} puan)</li></ul>
                        </div>
                        <div v-if="attempt.scoring_key.target_bugs?.length">
                            <p class="font-medium">Bulunması gereken hatalar</p>
                            <ul class="list-disc pl-5 text-stone-700"><li v-for="(item, i) in attempt.scoring_key.target_bugs" :key="i"><span v-if="item.line_hint" class="code">{{ item.line_hint }}: </span>{{ item.description }}</li></ul>
                        </div>
                        <div v-if="attempt.scoring_key.alternative_solutions?.length">
                            <p class="font-medium">Alternatif doğru çözümler</p>
                            <pre v-for="(item, i) in attempt.scoring_key.alternative_solutions" :key="i" class="code mt-1 overflow-x-auto rounded bg-stone-50 p-2 text-xs">{{ item }}</pre>
                        </div>
                    </div>
                </section>
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <section v-for="criterion in criteria" :key="criterion.key" class="card p-4">
                    <p class="text-sm font-medium">{{ criterion.label }}</p>
                    <p class="mb-3 text-xs text-stone-500">{{ criterion.description }}</p>
                    <div class="grid gap-2">
                        <label
                            v-for="score in range(criterion)"
                            :key="score"
                            class="flex cursor-pointer items-start gap-3 rounded border px-3 py-2 text-sm"
                            :class="form.scores[criterion.key] === score ? 'border-accent bg-accent-soft' : 'border-stone-200 hover:bg-stone-50'"
                        >
                            <input v-model="form.scores[criterion.key]" type="radio" :value="score" class="mt-0.5">
                            <span class="w-4 font-semibold tabular-nums">{{ score }}</span>
                            <span class="text-stone-700">{{ criterion.anchors?.[score] }}</span>
                        </label>
                    </div>
                    <p v-if="form.errors[`scores.${criterion.key}`]" class="mt-1 text-xs text-red-700">{{ form.errors[`scores.${criterion.key}`] }}</p>
                </section>

                <section class="card p-4">
                    <label class="label">Not (isteğe bağlı)</label>
                    <textarea v-model="form.notes" rows="2" class="input" />
                </section>

                <div class="flex items-center justify-between">
                    <p class="text-sm text-stone-600">Toplam: <span class="font-semibold tabular-nums">{{ total }}</span></p>
                    <button type="submit" class="btn btn-primary" :disabled="!complete || form.processing">Kaydet ve sıradakine geç</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
