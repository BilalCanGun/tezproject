<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EditorState, Compartment } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { python } from '@codemirror/lang-python';
import { syntaxHighlighting, HighlightStyle, indentUnit } from '@codemirror/language';
import { tags } from '@lezer/highlight';

const props = defineProps({
    modelValue: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'typed', 'paste']);

const host = ref(null);
const editable = new Compartment();
let view;

/*
 * Typed vs. pasted characters are separated here because the self-written
 * code ratio is a manipulation-check variable.
 */
const tracker = EditorView.updateListener.of((update) => {
    if (!update.docChanged) {
        return;
    }

    let typed = 0;
    let deleted = 0;

    for (const tr of update.transactions) {
        if (!tr.docChanged || tr.isUserEvent('input.paste')) {
            continue;
        }

        tr.changes.iterChanges((fromA, toA, fromB, toB) => {
            deleted += toA - fromA;

            if (tr.isUserEvent('input') || tr.isUserEvent('undo') || tr.isUserEvent('redo')) {
                typed += toB - fromB;
            }
        });
    }

    if (typed || deleted) {
        emit('typed', { typed, deleted });
    }

    emit('update:modelValue', update.state.doc.toString());
});

const pasteHandler = EditorView.domEventHandlers({
    paste(event) {
        emit('paste', event.clipboardData?.getData('text/plain') ?? '');
    },
    drop(event) {
        event.preventDefault();

        return true;
    },
});

/* Light, low-saturation token colours that stay readable on the mid-grey
   kiosk background without introducing bright luminance changes. */
const highlight = HighlightStyle.define([
    { tag: [tags.keyword, tags.controlKeyword, tags.definitionKeyword, tags.operatorKeyword], color: '#ffd9a8' },
    { tag: [tags.string, tags.special(tags.string)], color: '#cdeeb8' },
    { tag: [tags.number, tags.bool, tags.null], color: '#ffc4c4' },
    { tag: tags.comment, color: '#c3c6c9', fontStyle: 'italic' },
    { tag: [tags.function(tags.variableName), tags.function(tags.definition(tags.variableName))], color: '#bfe2ff' },
    { tag: [tags.standard(tags.variableName), tags.className], color: '#e6ccff' },
    { tag: [tags.variableName, tags.propertyName, tags.punctuation, tags.operator], color: '#f1f1f1' },
]);

const theme = EditorView.theme({
    '&': { height: '100%', fontSize: '14px', backgroundColor: '#55595e', color: '#f1f1f1' },
    '.cm-content': { fontFamily: 'var(--font-mono)', caretColor: '#ffffff' },
    '.cm-gutters': { backgroundColor: '#55595e', color: '#a9acaf', border: 'none' },
    '.cm-activeLine': { backgroundColor: '#5c6065' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground': { backgroundColor: '#3d5f5b !important' },
    '&.cm-focused': { outline: 'none' },
}, { dark: true });

onMounted(() => {
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                lineNumbers(),
                highlightActiveLine(),
                drawSelection(),
                history(),
                indentUnit.of('    '),
                keymap.of([indentWithTab, ...defaultKeymap, ...historyKeymap]),
                python(),
                syntaxHighlighting(highlight),
                theme,
                tracker,
                pasteHandler,
                editable.of([EditorState.readOnly.of(props.readonly), EditorView.editable.of(!props.readonly)]),
            ],
        }),
    });
});

watch(() => props.readonly, (readonly) => {
    view?.dispatch({ effects: editable.reconfigure([EditorState.readOnly.of(readonly), EditorView.editable.of(!readonly)]) });
});

watch(() => props.modelValue, (value) => {
    if (view && value !== view.state.doc.toString()) {
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } });
    }
});

onBeforeUnmount(() => view?.destroy());

defineExpose({ focus: () => view?.focus() });
</script>

<template>
    <div ref="host" class="h-full min-h-0 overflow-hidden" />
</template>
