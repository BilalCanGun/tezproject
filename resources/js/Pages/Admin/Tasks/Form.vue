<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import Markdown from '@/Components/Markdown.vue';
import { PythonRunner } from '@/kiosk/pythonRunner';

const props = defineProps({
    protocol: Object,
    task: Object,
    kinds: Array,
    measures: Array,
    pyodideIndexUrl: String,
});

const editing = computed(() => Boolean(props.task));
const locked = computed(() => props.protocol.is_frozen);

const form = useForm({
    session_number: props.task?.session_number ?? 1,
    form: props.task?.form ?? '',
    kind: props.task?.kind ?? 'learning',
    title: props.task?.title ?? '',
    isomorph_group: props.task?.isomorph_group ?? '',
    sort_order: props.task?.sort_order ?? 0,
    prompt: props.task?.prompt ?? '',
    starter_code: props.task?.starter_code ?? '',
    reference_solution: props.task?.reference_solution ?? '',
    test_cases: props.task?.test_cases ?? [{ call: '', expected: '', hidden: false }],
    mcq_options: props.task?.mcq_options ?? [{ key: 'a', text: '' }, { key: 'b', text: '' }],
    mcq_answer: props.task?.mcq_answer ?? '',
    max_score: props.task?.max_score ?? 10,
    min_reading_seconds: props.task?.min_reading_seconds ?? 15,
    planning_seconds: props.task?.planning_seconds ?? 30,
    time_limit_seconds: props.task?.time_limit_seconds ?? 600,
    help_allowed: props.task?.help_allowed ?? false,
    is_active: props.task?.is_active ?? true,
    scoring_key: {
        measures: '',
        expected_approach: '',
        difficulty: '',
        estimated_minutes: null,
        category_rationale: '',
        alternative_solutions: [],
        partial_credit: [],
        misconceptions: [],
        target_bugs: [],
        explanation_elements: [],
        ...(props.task?.scoring_key ?? {}),
    },
});

const key = form.scoring_key;

function cleanScoringKey(scoringKey) {
    const cleaned = {
        ...scoringKey,
        measures: scoringKey.measures || null,
        difficulty: scoringKey.difficulty || null,
        estimated_minutes: scoringKey.estimated_minutes === '' ? null : scoringKey.estimated_minutes,
        alternative_solutions: scoringKey.alternative_solutions.filter((item) => item.trim() !== ''),
        explanation_elements: scoringKey.explanation_elements.filter((item) => item.trim() !== ''),
        partial_credit: scoringKey.partial_credit.filter((item) => item.criterion.trim() !== ''),
        misconceptions: scoringKey.misconceptions.filter((item) => item.code.trim() !== '' || item.description.trim() !== ''),
        target_bugs: scoringKey.target_bugs.filter((item) => item.description.trim() !== ''),
    };

    const empty = Object.values(cleaned).every((value) => value === null || value === '' || (Array.isArray(value) && value.length === 0));

    return empty ? null : cleaned;
}

const formKinds = ['near_transfer', 'far_transfer', 'debugging'];
const canHaveForm = computed(() => formKinds.includes(form.kind));
const helpEditable = computed(() => (form.form ? true : form.session_number === 1));

const format = computed(() => props.kinds.find((k) => k.value === form.kind)?.format);
const err = (key) => form.errors[key];

const check = ref(null);
const checking = ref(false);
let runner;

async function checkReference() {
    checking.value = true;
    runner ??= new PythonRunner(props.pyodideIndexUrl, 10);

    try {
        const [reference, starter] = await Promise.all([
            runner.run(form.reference_solution, form.test_cases),
            runner.run(form.starter_code || '', form.test_cases),
        ]);
        check.value = { reference, starter };
    } finally {
        checking.value = false;
    }
}

onBeforeUnmount(() => runner?.destroy());

function addOption() {
    form.mcq_options.push({ key: String.fromCharCode(97 + form.mcq_options.length), text: '' });
}

function submit() {
    form.transform((data) => ({
        ...data,
        isomorph_group: data.isomorph_group || null,
        form: canHaveForm.value && data.form ? data.form : null,
        session_number: canHaveForm.value && data.form ? null : data.session_number,
        test_cases: format.value === 'code' ? data.test_cases : null,
        mcq_options: format.value === 'mcq' ? data.mcq_options : null,
        mcq_answer: format.value === 'mcq' ? data.mcq_answer : null,
        starter_code: format.value === 'code' ? data.starter_code : null,
        reference_solution: format.value === 'code' ? data.reference_solution : null,
        scoring_key: cleanScoringKey(data.scoring_key),
    }));

    if (editing.value) {
        form.put(route('admin.protocols.tasks.update', [props.protocol.id, props.task.id]));
    } else {
        form.post(route('admin.protocols.tasks.store', props.protocol.id));
    }
}
</script>

<template>
    <Head :title="editing ? task.title : 'Yeni görev'" />

    <AppLayout>
        <PageHeader :title="editing ? task.title : 'Yeni görev'">
            <template #breadcrumb>
                <Link :href="route('admin.protocols.tasks.index', protocol.id)" class="hover:underline">{{ protocol.code }} · Görevler</Link>
            </template>
        </PageHeader>

        <form class="grid gap-6 xl:grid-cols-2" @submit.prevent="submit">
            <fieldset :disabled="locked" class="space-y-6">
                <section class="card grid gap-4 p-4 sm:grid-cols-3">
                    <Field v-if="canHaveForm" label="A/B formu" hint="Formlu görevin oturumu form sırasına göre belirlenir" :error="err('form')">
                        <select v-model="form.form" class="input">
                            <option value="">Form yok (sabit oturum)</option>
                            <option value="A">Form A</option>
                            <option value="B">Form B</option>
                        </select>
                    </Field>
                    <Field v-if="!(canHaveForm && form.form)" label="Oturum" :error="err('session_number')">
                        <select v-model.number="form.session_number" class="input">
                            <option :value="0">Ön değerlendirme</option>
                            <option :value="1">1. oturum</option>
                            <option :value="2">2. oturum</option>
                        </select>
                    </Field>
                    <Field label="Tür" :error="err('kind')">
                        <select v-model="form.kind" class="input"><option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select>
                    </Field>
                    <Field label="Sıra" :error="err('sort_order')"><input v-model.number="form.sort_order" type="number" class="input"></Field>
                    <Field label="Başlık" :error="err('title')" class="sm:col-span-2"><input v-model="form.title" type="text" class="input"></Field>
                    <Field label="İzomorf grup" hint="ör. far-a" :error="err('isomorph_group')"><input v-model="form.isomorph_group" type="text" class="input code"></Field>
                    <Field label="Azami puan" :error="err('max_score')"><input v-model.number="form.max_score" type="number" step="0.5" class="input"></Field>
                    <Field label="Süre sınırı (sn)" :error="err('time_limit_seconds')"><input v-model.number="form.time_limit_seconds" type="number" class="input"></Field>
                    <Field v-if="format === 'code'" label="En az okuma (sn)" :error="err('min_reading_seconds')"><input v-model.number="form.min_reading_seconds" type="number" class="input"></Field>
                    <Field v-if="format === 'code'" label="Sessiz planlama (sn)" hint="EEG analiz penceresi" :error="err('planning_seconds')"><input v-model.number="form.planning_seconds" type="number" class="input"></Field>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input v-model="form.help_allowed" type="checkbox" class="rounded border-stone-300" :disabled="!helpEditable">
                        Koşula göre yardım açık (yalnızca 1. oturum; formlu görevde 2. oturumda her zaman kapalı)
                    </label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-stone-300"> Etkin</label>
                    <p v-if="err('help_allowed')" class="text-xs text-red-700 sm:col-span-3">{{ err('help_allowed') }}</p>
                </section>

                <section class="card p-4">
                    <Field label="Görev metni (Markdown)" :error="err('prompt')"><textarea v-model="form.prompt" rows="12" class="input code" /></Field>
                </section>

                <section v-if="format === 'code'" class="card space-y-4 p-4">
                    <Field label="Başlangıç kodu" :error="err('starter_code')" hint="Fonksiyon imzası buradan alınır; hata ayıklama görevinde hatalı kod buraya yazılır."><textarea v-model="form.starter_code" rows="6" class="input code" /></Field>
                    <Field label="Referans çözüm (katılımcıya ve YZ'ye gönderilmez)" :error="err('reference_solution')"><textarea v-model="form.reference_solution" rows="8" class="input code" /></Field>

                    <div>
                        <p class="label">Test durumları</p>
                        <div v-for="(test, index) in form.test_cases" :key="index" class="mb-2 grid grid-cols-[1fr_8rem_auto_auto] items-center gap-2">
                            <input v-model="test.call" type="text" class="input code" placeholder="fonksiyon([1, [2]])">
                            <input v-model="test.expected" type="text" class="input code" placeholder="3">
                            <label class="flex items-center gap-1 text-xs text-stone-600"><input v-model="test.hidden" type="checkbox" class="rounded border-stone-300"> gizli</label>
                            <button type="button" class="btn btn-ghost btn-sm" @click="form.test_cases.splice(index, 1)">Sil</button>
                        </div>
                        <p v-if="err('test_cases')" class="text-xs text-red-700">{{ err('test_cases') }}</p>
                        <button type="button" class="btn btn-secondary btn-sm" @click="form.test_cases.push({ call: '', expected: '', hidden: false })">Test ekle</button>
                    </div>
                </section>

                <section v-if="format === 'mcq'" class="card space-y-2 p-4">
                    <p class="label">Seçenekler (doğru olanı işaretleyin)</p>
                    <div v-for="(option, index) in form.mcq_options" :key="index" class="grid grid-cols-[auto_3rem_1fr_auto] items-center gap-2">
                        <input v-model="form.mcq_answer" type="radio" :value="option.key">
                        <input v-model="option.key" type="text" class="input code">
                        <input v-model="option.text" type="text" class="input">
                        <button type="button" class="btn btn-ghost btn-sm" @click="form.mcq_options.splice(index, 1)">Sil</button>
                    </div>
                    <p v-if="err('mcq_answer') || err('mcq_options')" class="text-xs text-red-700">{{ err('mcq_answer') || err('mcq_options') }}</p>
                    <button type="button" class="btn btn-secondary btn-sm" @click="addOption">Seçenek ekle</button>
                </section>

                <section class="card space-y-4 p-4">
                    <div>
                        <h2 class="card-title">Görev künyesi ve puanlama anahtarı</h2>
                        <p class="text-xs text-stone-500">Yalnızca araştırmacılar görür; katılımcıya ve YZ asistanına gönderilmez. Puanlayıcı ekranında gösterilir.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <Field label="Ölçtüğü değişken" :error="err('scoring_key.measures')">
                            <select v-model="key.measures" class="input">
                                <option value="">—</option>
                                <option v-for="m in measures" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                        </Field>
                        <Field label="Tahmini zorluk" :error="err('scoring_key.difficulty')">
                            <select v-model="key.difficulty" class="input">
                                <option value="">—</option>
                                <option value="kolay">Kolay</option>
                                <option value="orta">Orta</option>
                                <option value="zor">Zor</option>
                            </select>
                        </Field>
                        <Field label="Tahmini süre (dk)" :error="err('scoring_key.estimated_minutes')"><input v-model.number="key.estimated_minutes" type="number" min="0" class="input"></Field>
                    </div>
                    <Field label="Beklenen çözüm yaklaşımı" :error="err('scoring_key.expected_approach')"><textarea v-model="key.expected_approach" rows="3" class="input" /></Field>
                    <Field label="Neden bu kategoride?" hint="Ör. uzak transferde öğrencinin ilkeyi kendisinin fark etmesi gerekir" :error="err('scoring_key.category_rationale')"><textarea v-model="key.category_rationale" rows="3" class="input" /></Field>

                    <div>
                        <p class="label">Alternatif doğru çözümler</p>
                        <div v-for="(item, index) in key.alternative_solutions" :key="index" class="mb-2 grid grid-cols-[1fr_auto] items-start gap-2">
                            <textarea v-model="key.alternative_solutions[index]" rows="3" class="input code" />
                            <button type="button" class="btn btn-ghost btn-sm" @click="key.alternative_solutions.splice(index, 1)">Sil</button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="key.alternative_solutions.push('')">Çözüm ekle</button>
                    </div>

                    <div>
                        <p class="label">Kısmi puan ölçütleri</p>
                        <div v-for="(item, index) in key.partial_credit" :key="index" class="mb-2 grid grid-cols-[1fr_6rem_auto] items-center gap-2">
                            <input v-model="item.criterion" type="text" class="input" placeholder="Temel durum doğru ama özyineleme eksik">
                            <input v-model.number="item.points" type="number" step="0.5" class="input" placeholder="puan">
                            <button type="button" class="btn btn-ghost btn-sm" @click="key.partial_credit.splice(index, 1)">Sil</button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="key.partial_credit.push({ criterion: '', points: 0 })">Ölçüt ekle</button>
                    </div>

                    <div>
                        <p class="label">Temel kavramsal hatalar</p>
                        <div v-for="(item, index) in key.misconceptions" :key="index" class="mb-2 grid grid-cols-[10rem_1fr_auto] items-center gap-2">
                            <input v-model="item.code" type="text" class="input code" placeholder="temel_durum_yok">
                            <input v-model="item.description" type="text" class="input" placeholder="Açıklama">
                            <button type="button" class="btn btn-ghost btn-sm" @click="key.misconceptions.splice(index, 1)">Sil</button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="key.misconceptions.push({ code: '', description: '' })">Hata ekle</button>
                    </div>

                    <div v-if="form.kind === 'debugging' || key.target_bugs.length">
                        <p class="label">Bulunması gereken hatalar (hata bulma görevi)</p>
                        <div v-for="(item, index) in key.target_bugs" :key="index" class="mb-2 grid grid-cols-[10rem_1fr_auto] items-center gap-2">
                            <input v-model="item.line_hint" type="text" class="input code" placeholder="satır 3">
                            <input v-model="item.description" type="text" class="input" placeholder="Hatanın tanımı">
                            <button type="button" class="btn btn-ghost btn-sm" @click="key.target_bugs.splice(index, 1)">Sil</button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="key.target_bugs.push({ line_hint: '', description: '' })">Hata ekle</button>
                    </div>

                    <div v-if="form.kind === 'explanation' || key.explanation_elements.length">
                        <p class="label">İyi bir açıklamada bulunması gereken unsurlar</p>
                        <div v-for="(item, index) in key.explanation_elements" :key="index" class="mb-2 grid grid-cols-[1fr_auto] items-center gap-2">
                            <input v-model="key.explanation_elements[index]" type="text" class="input">
                            <button type="button" class="btn btn-ghost btn-sm" @click="key.explanation_elements.splice(index, 1)">Sil</button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="key.explanation_elements.push('')">Unsur ekle</button>
                    </div>
                </section>

                <div v-if="!locked" class="flex justify-end gap-2">
                    <Link :href="route('admin.protocols.tasks.index', protocol.id)" class="btn btn-secondary">Vazgeç</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Kaydet</button>
                </div>
            </fieldset>

            <div class="space-y-6">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Katılımcının göreceği metin</h2></div>
                    <div class="p-4"><Markdown :source="form.prompt" /></div>
                </section>

                <section v-if="format === 'code'" class="card">
                    <div class="card-header">
                        <h2 class="card-title">Test doğrulaması</h2>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="checking || !form.reference_solution" @click="checkReference">{{ checking ? 'Çalışıyor…' : 'Testleri çalıştır' }}</button>
                    </div>
                    <div class="space-y-3 p-4 text-sm">
                        <p class="text-xs text-stone-500">Referans çözüm tüm testleri geçmeli; başlangıç kodu ise geçmemeli. İlk çalıştırmada Python ortamı indirilir.</p>
                        <template v-if="check">
                            <p :class="check.reference.passed === check.reference.total ? 'text-emerald-700' : 'text-red-700'">
                                Referans çözüm: {{ check.reference.passed }} / {{ check.reference.total }} test
                                <span v-if="check.reference.error" class="code block whitespace-pre-wrap">{{ check.reference.error }}</span>
                            </p>
                            <p :class="check.starter.passed < check.starter.total ? 'text-emerald-700' : 'text-amber-700'">
                                Başlangıç kodu: {{ check.starter.passed }} / {{ check.starter.total }} test
                                <template v-if="check.starter.passed === check.starter.total"> — başlangıç kodu zaten tüm testleri geçiyor!</template>
                            </p>
                            <ul class="code space-y-0.5 text-xs">
                                <li v-for="(row, i) in check.reference.results" :key="i" :class="row.ok ? 'text-stone-600' : 'text-red-700'">
                                    {{ row.ok ? '✓' : '✗' }} {{ row.call }} → {{ row.got ?? row.error }}
                                </li>
                            </ul>
                        </template>
                    </div>
                </section>
            </div>
        </form>
    </AppLayout>
</template>
