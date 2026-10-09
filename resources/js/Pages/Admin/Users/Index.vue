<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDateTime } from '@/lib/format';

defineProps({
    users: Array,
});
</script>

<template>
    <Head title="Kullanıcılar" />

    <AppLayout>
        <PageHeader title="Kullanıcılar" subtitle="Yönetici protokolü, cihazları, raporları ve dışa aktarımı yönetir. Deney sorumlusu katılımcı kaydı, oturum yürütme ve açıklama puanlaması yapar.">
            <template #actions><Link :href="route('admin.users.create')" class="btn btn-primary">Kullanıcı ekle</Link></template>
        </PageHeader>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Ad</th><th>E-posta</th><th>Rol</th><th class="text-right">Oturum</th><th class="text-right">Puanlama</th><th>Son giriş</th><th /></tr></thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id" :class="{ 'opacity-50': !user.is_active }">
                        <td class="font-medium">{{ user.name }}<span v-if="!user.is_active" class="badge ml-1 bg-stone-100 text-stone-500">pasif</span></td>
                        <td class="text-stone-600">{{ user.email }}</td>
                        <td>{{ user.role_label }}</td>
                        <td class="text-right tabular-nums">{{ user.sessions_count }}</td>
                        <td class="text-right tabular-nums">{{ user.ratings_count }}</td>
                        <td class="text-stone-600">{{ formatDateTime(user.last_login_at) }}</td>
                        <td class="text-right"><Link :href="route('admin.users.edit', user.id)" class="btn btn-ghost btn-sm">Düzenle</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
