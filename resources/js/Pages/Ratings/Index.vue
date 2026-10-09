<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    protocol: Object,
    pending: Array,
    completedCount: Number,
});
</script>

<template>
    <Head title="Açıklama puanlama" />

    <AppLayout>
        <PageHeader title="Açıklama puanlama" :subtitle="`Kör puanlama: koşul, katılımcı kodu ve YZ konuşması gösterilmez. Şimdiye kadar ${completedCount} yanıt puanladınız.`">
            <template #actions>
                <Link v-if="pending.length" :href="route('ratings.edit', pending[0].id)" class="btn btn-primary">Sıradakini puanla</Link>
            </template>
        </PageHeader>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Yanıt</th><th>Görev</th><th class="text-right">Uzunluk</th><th /></tr></thead>
                <tbody>
                    <tr v-for="item in pending" :key="item.id">
                        <td class="code">{{ item.blind_label }}</td>
                        <td>{{ item.task_title }}</td>
                        <td class="text-right tabular-nums">{{ item.length }} karakter</td>
                        <td class="text-right"><Link :href="route('ratings.edit', item.id)" class="btn btn-secondary btn-sm">Puanla</Link></td>
                    </tr>
                    <tr v-if="!pending.length"><td colspan="4" class="py-6 text-center text-stone-500">Kuyrukta bekleyen yanıt yok.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
