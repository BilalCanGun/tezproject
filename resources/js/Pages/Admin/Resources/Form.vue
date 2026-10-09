<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import Markdown from '@/Components/Markdown.vue';

const props = defineProps({
    protocol: Object,
    article: Object,
    sections: Object,
});

const form = useForm({
    section: props.article?.section ?? 'concept',
    title: props.article?.title ?? '',
    body: props.article?.body ?? '',
    sort_order: props.article?.sort_order ?? 0,
});

function submit() {
    if (props.article) {
        form.put(route('admin.protocols.resources.update', [props.protocol.id, props.article.id]));
    } else {
        form.post(route('admin.protocols.resources.store', props.protocol.id));
    }
}
</script>

<template>
    <Head :title="article ? article.title : 'Yeni kaynak sayfası'" />

    <AppLayout>
        <PageHeader :title="article ? article.title : 'Yeni kaynak sayfası'">
            <template #breadcrumb><Link :href="route('admin.protocols.resources.index', protocol.id)" class="hover:underline">{{ protocol.code }} · Standart kaynak</Link></template>
        </PageHeader>

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="submit">
            <div class="card space-y-4 p-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <Field label="Bölüm" :error="form.errors.section">
                        <select v-model="form.section" class="input"><option v-for="(label, key) in sections" :key="key" :value="key">{{ label }}</option></select>
                    </Field>
                    <Field label="Başlık" class="sm:col-span-2" :error="form.errors.title"><input v-model="form.title" type="text" class="input"></Field>
                    <Field label="Sıra" :error="form.errors.sort_order"><input v-model.number="form.sort_order" type="number" class="input"></Field>
                </div>
                <Field label="İçerik (Markdown)" :error="form.errors.body"><textarea v-model="form.body" rows="20" class="input code" /></Field>
                <div class="flex justify-end gap-2">
                    <Link :href="route('admin.protocols.resources.index', protocol.id)" class="btn btn-secondary">Vazgeç</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
                </div>
            </div>
            <div class="card p-4">
                <p class="label">Önizleme</p>
                <h3 class="mb-2 font-semibold">{{ form.title }}</h3>
                <Markdown :source="form.body" />
            </div>
        </form>
    </AppLayout>
</template>
