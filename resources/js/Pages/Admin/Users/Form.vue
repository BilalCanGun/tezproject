<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({
    user: Object,
    roles: Array,
});

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    role: props.user?.role ?? 'tester',
    password: '',
    password_confirmation: '',
    is_active: props.user?.is_active ?? true,
});

function submit() {
    if (props.user) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
}
</script>

<template>
    <Head :title="user ? user.name : 'Yeni kullanıcı'" />

    <AppLayout>
        <PageHeader :title="user ? user.name : 'Yeni kullanıcı'">
            <template #breadcrumb><Link :href="route('admin.users.index')" class="hover:underline">Kullanıcılar</Link></template>
        </PageHeader>

        <form class="card max-w-xl space-y-4 p-4" @submit.prevent="submit">
            <Field label="Ad soyad" :error="form.errors.name"><input v-model="form.name" type="text" class="input"></Field>
            <Field label="E-posta" :error="form.errors.email"><input v-model="form.email" type="email" class="input" autocomplete="off"></Field>
            <Field label="Rol" :error="form.errors.role">
                <select v-model="form.role" class="input"><option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option></select>
            </Field>
            <div class="grid gap-4 sm:grid-cols-2">
                <Field :label="user ? 'Yeni parola (değişmeyecekse boş)' : 'Parola'" hint="En az 10 karakter, harf ve rakam" :error="form.errors.password">
                    <input v-model="form.password" type="password" class="input" autocomplete="new-password">
                </Field>
                <Field label="Parola tekrar"><input v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password"></Field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-stone-300"> Hesap etkin</label>
            <div class="flex justify-end gap-2">
                <Link :href="route('admin.users.index')" class="btn btn-secondary">Vazgeç</Link>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
            </div>
        </form>
    </AppLayout>
</template>
