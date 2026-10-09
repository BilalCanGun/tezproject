<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Markdown from '@/Components/Markdown.vue';
import { http } from '@/lib/http';

const props = defineProps({
    endpoint: { type: String, required: true },
});

const emit = defineEmits(['open', 'search']);

const sections = ref({});
const articles = ref([]);
const query = ref('');
const openId = ref(null);
const loadError = ref('');

const normalize = (text) => text.toLocaleLowerCase('tr-TR');

const filtered = computed(() => {
    const q = normalize(query.value.trim());

    return q ? articles.value.filter((a) => normalize(a.title + ' ' + a.body).includes(q)) : articles.value;
});

const grouped = computed(() => Object.entries(sections.value)
    .map(([key, label]) => ({ key, label, items: filtered.value.filter((a) => a.section === key) }))
    .filter((group) => group.items.length));

const current = computed(() => articles.value.find((a) => a.id === openId.value));

function open(article) {
    openId.value = article.id;
    emit('open', { article_id: article.id, title: article.title, section: article.section });
}

let searchTimer;
watch(query, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        if (value.trim()) {
            emit('search', { query: value.trim(), results: filtered.value.length });
        }
    }, 800);
});

onMounted(async () => {
    try {
        const data = await http.get(props.endpoint);
        sections.value = data.sections;
        articles.value = data.articles;
    } catch {
        loadError.value = 'Kaynak yüklenemedi. Lütfen araştırmacıya haber verin.';
    }
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="border-b border-kiosk-line px-4 py-2 text-sm font-medium">Başvuru kaynağı</div>
        <div class="border-b border-kiosk-line p-3">
            <input v-model="query" type="search" placeholder="Kaynakta ara" class="w-full rounded border border-kiosk-line bg-kiosk-bg px-2.5 py-1.5 text-sm text-kiosk-text outline-none placeholder:text-kiosk-muted">
        </div>

        <div v-if="current" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <button type="button" class="mb-2 text-xs text-kiosk-muted hover:text-kiosk-text" @click="openId = null">← İçindekiler</button>
            <h3 class="mb-2 text-base font-semibold">{{ current.title }}</h3>
            <Markdown :source="current.body" variant="kiosk" />
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <p v-if="loadError" class="text-sm text-amber-200">{{ loadError }}</p>
            <div v-for="group in grouped" :key="group.key" class="mb-4">
                <p class="mb-1 text-[11px] tracking-wide text-kiosk-muted uppercase">{{ group.label }}</p>
                <button v-for="article in group.items" :key="article.id" type="button" class="block w-full rounded px-2 py-1.5 text-left text-sm hover:bg-kiosk-bg" @click="open(article)">{{ article.title }}</button>
            </div>
            <p v-if="!loadError && articles.length && !grouped.length" class="text-sm text-kiosk-muted">Sonuç bulunamadı.</p>
        </div>
    </div>
</template>
