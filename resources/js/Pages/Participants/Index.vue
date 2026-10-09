<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import ConditionBadge from '@/Components/ConditionBadge.vue';
import { sessionStatusStyles } from '@/lib/format';

const props = defineProps({
    participants: Object,
    filters: Object,
    protocols: Array,
    statuses: Array,
    conditions: Array,
});

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    condition: props.filters.condition ?? '',
    protocol: props.filters.protocol ?? '',
});

let timer;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('participants.index'), Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), {
            preserveState: true,
            replace: true,
        });
    }, 250);
});

const sessionLabels = ['Ö', '1', '2'];
</script>

<template>
    <Head title="Katılımcılar" />

    <AppLayout>
        <PageHeader title="Katılımcılar" subtitle="Kimlik bilgileri ayrı ve şifreli saklanır; listelerde yalnızca katılımcı kodu görünür.">
            <template #actions>
                <Link :href="route('participants.create')" class="btn btn-primary">Yeni katılımcı</Link>
            </template>
        </PageHeader>

        <div class="card">
            <div class="flex flex-wrap gap-2 border-b border-stone-200 p-3">
                <input v-model="filters.search" type="search" placeholder="Kod ara (P-012)" class="input w-40">
                <select v-model="filters.protocol" class="input w-auto">
                    <option v-for="p in protocols" :key="p.id" :value="p.id">{{ p.code }}</option>
                </select>
                <select v-model="filters.status" class="input w-auto">
                    <option value="">Tüm durumlar</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select v-model="filters.condition" class="input w-auto">
                    <option value="">Tüm koşullar</option>
                    <option v-for="c in conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Durum</th>
                            <th>Koşul</th>
                            <th>Tabaka</th>
                            <th class="text-right">Ön test</th>
                            <th>Oturumlar</th>
                            <th>Kayıt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in participants.data" :key="p.id" class="cursor-pointer" @click="router.visit(route('participants.show', p.id))">
                            <td class="code font-medium">{{ p.code }}</td>
                            <td>{{ p.status_label }}</td>
                            <td><ConditionBadge :condition="p.condition" :label="p.condition_label" /></td>
                            <td>{{ p.stratum_label ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ p.baseline_pct !== null ? '%' + p.baseline_pct : '—' }}</td>
                            <td>
                                <div class="flex gap-1">
                                    <span
                                        v-for="s in p.sessions"
                                        :key="s.number"
                                        class="badge w-6 justify-center"
                                        :class="s.status ? sessionStatusStyles[s.status] : 'bg-white text-stone-300 ring-1 ring-stone-200'"
                                        :title="s.status ?? 'planlanmadı'"
                                    >{{ sessionLabels[s.number] }}</span>
                                </div>
                            </td>
                            <td class="text-stone-500">{{ p.created_at }}</td>
                        </tr>
                        <tr v-if="!participants.data.length">
                            <td colspan="7" class="py-8 text-center text-stone-500">Kayıt bulunamadı.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="participants" />
        </div>
    </AppLayout>
</template>
