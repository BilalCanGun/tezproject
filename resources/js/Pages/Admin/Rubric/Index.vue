<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolTabs from '@/Components/ProtocolTabs.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({
    protocol: Object,
    criteria: Array,
});

const blank = () => ({ key: '', label: '', description: '', min_score: 0, max_score: 3, anchors: { 0: '', 1: '', 2: '', 3: '' } });

const form = useForm({
    criteria: props.criteria.length ? props.criteria.map((c) => ({ ...c, anchors: { ...(c.anchors ?? {}) } })) : [blank()],
});

function range(criterion) {
    const scores = [];

    for (let s = Number(criterion.min_score); s <= Number(criterion.max_score); s++) {
        scores.push(s);
    }

    return scores;
}

function move(index, delta) {
    const target = index + delta;

    if (target < 0 || target >= form.criteria.length) {
        return;
    }

    const [item] = form.criteria.splice(index, 1);
    form.criteria.splice(target, 0, item);
}

function submit() {
    form.put(route('admin.protocols.rubric.update', props.protocol.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${protocol.code} · Rubrik`" />

    <AppLayout>
        <PageHeader :title="`${protocol.code} · ${protocol.name}`">
            <template #breadcrumb><Link :href="route('admin.protocols.index')" class="hover:underline">Protokol sürümleri</Link></template>
        </PageHeader>

        <ProtocolTabs :protocol="protocol" />

        <p class="mb-4 max-w-3xl text-sm text-stone-600">
            Açıklama kalitesi bu rubrikle puanlanır. Ana veri incelenmeden önce kesinleştirilmeli ve sonuçlara göre değiştirilmemelidir.
            Her puan düzeyi için somut bir tanım (çapa) yazmak puanlayıcılar arası uyumu artırır.
        </p>

        <form @submit.prevent="submit">
            <fieldset :disabled="protocol.is_frozen" class="space-y-4">
                <section v-for="(criterion, index) in form.criteria" :key="index" class="card p-4">
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr_5rem_5rem_auto]">
                        <Field label="Anahtar" :error="form.errors[`criteria.${index}.key`]"><input v-model="criterion.key" type="text" class="input code"></Field>
                        <Field label="Ölçüt" :error="form.errors[`criteria.${index}.label`]"><input v-model="criterion.label" type="text" class="input"></Field>
                        <Field label="Alt"><input v-model.number="criterion.min_score" type="number" class="input"></Field>
                        <Field label="Üst" :error="form.errors[`criteria.${index}.max_score`]"><input v-model.number="criterion.max_score" type="number" class="input"></Field>
                        <div class="flex items-end gap-1">
                            <button type="button" class="btn btn-ghost btn-sm" @click="move(index, -1)">↑</button>
                            <button type="button" class="btn btn-ghost btn-sm" @click="move(index, 1)">↓</button>
                            <button type="button" class="btn btn-ghost btn-sm text-red-700" @click="form.criteria.splice(index, 1)">Sil</button>
                        </div>
                    </div>
                    <Field label="Açıklama" class="mt-3"><input v-model="criterion.description" type="text" class="input"></Field>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <Field v-for="score in range(criterion)" :key="score" :label="`${score} puan`">
                            <input v-model="criterion.anchors[score]" type="text" class="input">
                        </Field>
                    </div>
                </section>

                <div v-if="!protocol.is_frozen" class="flex justify-between">
                    <button type="button" class="btn btn-secondary" @click="form.criteria.push(blank())">Ölçüt ekle</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Rubriği kaydet</button>
                </div>
            </fieldset>
        </form>
    </AppLayout>
</template>
