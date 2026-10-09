<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDateTime } from '@/lib/format';

const props = defineProps({
    logs: Object,
    filters: Object,
});

const action = ref(props.filters.action ?? '');

function filter() {
    router.get(route('admin.audit-logs.index'), action.value ? { action: action.value } : {}, { preserveState: true });
}
</script>

<template>
    <Head title="İşlem kayıtları" />

    <AppLayout>
        <PageHeader title="İşlem kayıtları" subtitle="Randomizasyon, protokol dondurma, veri indirme, dosya ve kişisel veri silme gibi araştırma açısından kritik işlemler.">
            <template #actions>
                <form class="flex gap-2" @submit.prevent="filter">
                    <input v-model="action" type="search" class="input w-56" placeholder="İşlem ön eki (ör. participant.)">
                    <button type="submit" class="btn btn-secondary">Süz</button>
                </form>
            </template>
        </PageHeader>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Zaman</th><th>Kullanıcı</th><th>İşlem</th><th>Kayıt</th><th>Ayrıntı</th><th>IP</th></tr></thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id">
                        <td class="whitespace-nowrap text-stone-600">{{ formatDateTime(log.created_at) }}</td>
                        <td>{{ log.user }}</td>
                        <td class="code">{{ log.action }}</td>
                        <td class="code text-stone-600">{{ log.subject ?? '—' }}</td>
                        <td class="code max-w-md truncate text-xs text-stone-500" :title="JSON.stringify(log.properties)">{{ log.properties ? JSON.stringify(log.properties) : '' }}</td>
                        <td class="code text-xs text-stone-500">{{ log.ip_address }}</td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="6" class="text-center text-stone-500">Kayıt yok.</td></tr>
                </tbody>
            </table>
            <Pagination :paginator="logs" />
        </div>
    </AppLayout>
</template>
