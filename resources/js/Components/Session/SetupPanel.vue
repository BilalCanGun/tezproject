<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Field from '@/Components/Field.vue';

const props = defineProps({
    session: { type: Object, required: true },
    checklist: { type: Object, required: true },
});

const form = useForm({
    scheduled_at: props.session.scheduled_at ? props.session.scheduled_at.slice(0, 16) : '',
    notes: props.session.notes ?? '',
    setup_checklist: Object.fromEntries(Object.keys(props.checklist).map((key) => [key, Boolean(props.session.setup_checklist?.[key])])),
    environment: {
        room_lux: props.session.environment?.room_lux ?? '',
        screen_brightness_percent: props.session.environment?.screen_brightness_percent ?? '',
        viewing_distance_cm: props.session.environment?.viewing_distance_cm ?? '',
        room_temperature_c: props.session.environment?.room_temperature_c ?? '',
        monitor: props.session.environment?.monitor ?? '',
        screen_resolution: props.session.environment?.screen_resolution ?? '',
    },
});

const done = computed(() => Object.values(form.setup_checklist).filter(Boolean).length);
const total = computed(() => Object.keys(props.checklist).length);

function save() {
    form.transform((data) => ({
        ...data,
        scheduled_at: data.scheduled_at || null,
        environment: Object.fromEntries(Object.entries(data.environment).map(([k, v]) => [k, v === '' ? null : v])),
    })).patch(route('sessions.update', props.session.id), { preserveScroll: true });
}
</script>

<template>
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Hazırlık listesi</h2>
            <span class="text-xs tabular-nums" :class="done === total ? 'text-emerald-700' : 'text-stone-500'">{{ done }} / {{ total }}</span>
        </div>
        <form class="space-y-4 p-4" @submit.prevent="save">
            <ul class="space-y-1.5">
                <li v-for="(item, key) in checklist" :key="key">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.setup_checklist[key]" type="checkbox" class="mt-0.5 rounded border-stone-300">
                        <span :class="{ 'text-stone-400 line-through': form.setup_checklist[key] }">{{ item.label }}</span>
                    </label>
                </li>
            </ul>

            <div class="grid grid-cols-2 gap-3 border-t border-stone-100 pt-4">
                <Field label="Oda aydınlığı (lüks)" :error="form.errors['environment.room_lux']">
                    <input v-model="form.environment.room_lux" type="number" step="1" class="input">
                </Field>
                <Field label="Ekran parlaklığı (%)" :error="form.errors['environment.screen_brightness_percent']">
                    <input v-model="form.environment.screen_brightness_percent" type="number" class="input">
                </Field>
                <Field label="Göz–ekran mesafesi (cm)" :error="form.errors['environment.viewing_distance_cm']">
                    <input v-model="form.environment.viewing_distance_cm" type="number" class="input">
                </Field>
                <Field label="Oda sıcaklığı (°C)" :error="form.errors['environment.room_temperature_c']">
                    <input v-model="form.environment.room_temperature_c" type="number" step="0.1" class="input">
                </Field>
                <Field label="Monitör">
                    <input v-model="form.environment.monitor" type="text" class="input" placeholder="Model, boyut">
                </Field>
                <Field label="Çözünürlük">
                    <input v-model="form.environment.screen_resolution" type="text" class="input" placeholder="1920x1080">
                </Field>
            </div>

            <Field label="Planlanan zaman" :error="form.errors.scheduled_at">
                <input v-model="form.scheduled_at" type="datetime-local" class="input">
            </Field>
            <Field label="Oturum notları" :error="form.errors.notes">
                <textarea v-model="form.notes" rows="3" class="input" />
            </Field>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
            </div>
        </form>
    </section>
</template>
