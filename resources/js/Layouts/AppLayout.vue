<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';

defineProps({
    title: { type: String, default: '' },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const protocol = computed(() => page.props.activeProtocol);
const mobileOpen = ref(false);

const sections = computed(() => {
    const items = [
        {
            heading: 'Deney',
            links: [
                { label: 'Genel durum', route: 'dashboard', match: 'dashboard' },
                { label: 'Katılımcılar', route: 'participants.index', match: 'participants.*' },
                { label: 'Açıklama puanlama', route: 'ratings.index', match: 'ratings.*' },
            ],
        },
    ];

    if (user.value?.is_admin) {
        items.push(
            {
                heading: 'Protokol',
                links: [
                    { label: 'Protokol sürümleri', route: 'admin.protocols.index', match: 'admin.protocols.*' },
                    { label: 'Cihaz profilleri', route: 'admin.devices.index', match: 'admin.devices.*' },
                ],
            },
            {
                heading: 'Analiz',
                links: [
                    { label: 'İlerleme ve örneklem', route: 'admin.reports.progress', match: 'admin.reports.progress' },
                    { label: 'Manipülasyon kontrolü', route: 'admin.reports.manipulation', match: 'admin.reports.manipulation' },
                    { label: 'Pilot doğrulama', route: 'admin.reports.pilot', match: 'admin.reports.pilot' },
                    { label: 'Puanlayıcı güvenirliği', route: 'admin.reports.reliability', match: 'admin.reports.reliability' },
                    { label: 'Cihaz doğrulama ve modeller', route: 'admin.reports.devices', match: 'admin.reports.devices' },
                    { label: 'Veri dışa aktarımı', route: 'admin.exports.index', match: 'admin.exports.*' },
                ],
            },
            {
                heading: 'Yönetim',
                links: [
                    { label: 'Kullanıcılar', route: 'admin.users.index', match: 'admin.users.*' },
                    { label: 'İşlem kayıtları', route: 'admin.audit-logs.index', match: 'admin.audit-logs.*' },
                ],
            },
        );
    }

    return items;
});

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="min-h-screen lg:grid lg:grid-cols-[15rem_1fr]">
        <aside
            class="fixed inset-y-0 left-0 z-30 w-60 shrink-0 border-r border-stone-200 bg-white transition-transform lg:static lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-14 items-center border-b border-stone-200 px-4">
                <Link :href="route('dashboard')" class="leading-tight">
                    <span class="block text-sm font-semibold">YZ Yardım Deneyi</span>
                    <span class="block text-[11px] text-stone-500">Öğrenme · Kalıcılık · Transfer</span>
                </Link>
            </div>

            <nav class="flex flex-col gap-5 overflow-y-auto px-3 py-4">
                <div v-for="section in sections" :key="section.heading">
                    <p class="px-2 pb-1 text-[11px] font-medium tracking-wider text-stone-400 uppercase">{{ section.heading }}</p>
                    <Link
                        v-for="link in section.links"
                        :key="link.route"
                        :href="route(link.route)"
                        class="block rounded px-2 py-1.5 text-sm"
                        :class="route().current(link.match) ? 'bg-accent-soft font-medium text-accent-strong' : 'text-stone-700 hover:bg-stone-100'"
                        @click="mobileOpen = false"
                    >
                        {{ link.label }}
                    </Link>
                </div>
            </nav>
        </aside>

        <div v-if="mobileOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="mobileOpen = false" />

        <div class="flex min-w-0 flex-col">
            <header class="sticky top-0 z-10 flex h-14 items-center gap-3 border-b border-stone-200 bg-white/95 px-4 backdrop-blur">
                <button type="button" class="btn btn-ghost btn-sm lg:hidden" @click="mobileOpen = true">Menü</button>

                <div v-if="protocol" class="hidden items-center gap-2 text-xs text-stone-500 sm:flex">
                    <span>Etkin protokol:</span>
                    <span class="code rounded bg-stone-100 px-1.5 py-0.5 text-stone-700">{{ protocol.code }}</span>
                    <span v-if="protocol.frozen_at" class="badge bg-stone-800 text-white">Donduruldu</span>
                    <span v-else class="badge bg-amber-100 text-amber-800">Düzenlenebilir</span>
                </div>
                <div v-else class="text-xs text-red-700">Etkin protokol yok</div>

                <div class="ml-auto flex items-center gap-3">
                    <div class="text-right leading-tight">
                        <p class="text-sm font-medium">{{ user?.name }}</p>
                        <p class="text-[11px] text-stone-500">{{ user?.role_label }}</p>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" @click="logout">Çıkış</button>
                </div>
            </header>

            <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">
                <FlashMessages />
                <slot />
            </main>
        </div>
    </div>
</template>
