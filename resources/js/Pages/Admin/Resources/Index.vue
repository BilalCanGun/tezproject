<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ProtocolTabs from '@/Components/ProtocolTabs.vue';

const props = defineProps({
    protocol: Object,
    sections: Object,
    articles: Array,
});

function remove(article) {
    if (confirm(`"${article.title}" silinsin mi?`)) {
        router.delete(route('admin.protocols.resources.destroy', [props.protocol.id, article.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${protocol.code} · Standart kaynak`" />

    <AppLayout>
        <PageHeader :title="`${protocol.code} · ${protocol.name}`">
            <template #breadcrumb><Link :href="route('admin.protocols.index')" class="hover:underline">Protokol sürümleri</Link></template>
            <template #actions>
                <Link v-if="!protocol.is_frozen" :href="route('admin.protocols.resources.create', protocol.id)" class="btn btn-primary">Sayfa ekle</Link>
            </template>
        </PageHeader>

        <ProtocolTabs :protocol="protocol" />

        <p class="mb-4 max-w-3xl text-sm text-stone-600">
            Standart kaynak koşulundaki katılımcılar bu sayfaları görür. İçerik herkese aynıdır ve katılımcının problemine özel çözüm içermemelidir;
            örnekler görev bataryasındaki hiçbir görevle aynı olmamalıdır.
        </p>

        <div class="grid gap-6 md:grid-cols-2">
            <section v-for="(label, key) in sections" :key="key" class="card">
                <div class="card-header"><h2 class="card-title">{{ label }}</h2></div>
                <ul class="divide-y divide-stone-100">
                    <li v-for="article in articles.filter((a) => a.section === key)" :key="article.id" class="flex items-center gap-2 px-4 py-2 text-sm">
                        <span class="w-6 text-stone-400 tabular-nums">{{ article.sort_order }}</span>
                        <span class="flex-1">{{ article.title }}</span>
                        <Link :href="route('admin.protocols.resources.edit', [protocol.id, article.id])" class="btn btn-ghost btn-sm">{{ protocol.is_frozen ? 'Görüntüle' : 'Düzenle' }}</Link>
                        <button v-if="!protocol.is_frozen" type="button" class="btn btn-ghost btn-sm text-red-700" @click="remove(article)">Sil</button>
                    </li>
                    <li v-if="!articles.some((a) => a.section === key)" class="px-4 py-2 text-sm text-stone-500">Sayfa yok.</li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
