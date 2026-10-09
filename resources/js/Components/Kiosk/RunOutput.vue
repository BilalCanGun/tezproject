<script setup>
defineProps({
    result: { type: Object, default: null },
    running: { type: Boolean, default: false },
});
</script>

<template>
    <div class="h-full overflow-y-auto px-4 py-3 font-mono text-[13px]">
        <p v-if="running" class="text-kiosk-muted">Çalışıyor…</p>
        <p v-else-if="!result" class="text-kiosk-muted">Kodunuzu çalıştırdığınızda çıktı ve test sonuçları burada görünür.</p>
        <template v-else>
            <p class="mb-2 font-sans text-sm">
                <span class="font-medium">{{ result.passed }} / {{ result.total }}</span> test geçti
            </p>
            <pre v-if="result.error" class="mb-2 whitespace-pre-wrap text-amber-200">{{ result.error }}</pre>
            <pre v-if="result.output" class="mb-2 whitespace-pre-wrap text-kiosk-muted">{{ result.output }}</pre>
            <ul class="space-y-1">
                <li v-for="(row, index) in result.results" :key="index" class="flex gap-2">
                    <span :class="row.ok ? 'text-emerald-200' : 'text-amber-200'">{{ row.ok ? 'geçti' : 'kaldı' }}</span>
                    <span v-if="row.hidden" class="text-kiosk-muted">gizli test {{ index + 1 }}</span>
                    <span v-else class="min-w-0 break-all">
                        {{ row.call }}
                        <template v-if="!row.ok">
                            <br><span class="text-kiosk-muted">beklenen {{ row.expected }}, {{ row.error ? row.error : 'dönen ' + row.got }}</span>
                        </template>
                    </span>
                </li>
            </ul>
        </template>
    </div>
</template>
