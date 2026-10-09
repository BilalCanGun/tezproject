<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login.store'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Giriş" />

    <div class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="mb-6">
                <h1 class="text-lg font-semibold">YZ Yardım Biçimi Deneyi</h1>
                <p class="text-sm text-stone-600">Araştırma ekibi girişi. Katılımcılar bu ekranı kullanmaz.</p>
            </div>

            <form class="card space-y-4 p-5" @submit.prevent="submit">
                <Field label="E-posta" for="email" :error="form.errors.email">
                    <input id="email" v-model="form.email" type="email" class="input" :class="{ 'input-error': form.errors.email }" autocomplete="username" required autofocus>
                </Field>

                <Field label="Parola" for="password" :error="form.errors.password">
                    <input id="password" v-model="form.password" type="password" class="input" autocomplete="current-password" required>
                </Field>

                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input v-model="form.remember" type="checkbox" class="rounded border-stone-300">
                    Bu bilgisayarda oturumu açık tut
                </label>

                <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">Giriş yap</button>
            </form>
        </div>
    </div>
</template>
