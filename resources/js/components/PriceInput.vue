<script setup>
import { ref, watch } from 'vue';
import { formatNumberInput, parseNumberInput } from '../format';

// Shows comma-grouped digits ("900,000") once you're not editing the field,
// and the plain digits while you are — a native <input type="number"> can't
// render commas at all, so this trades the number spinner for readability.
const props = defineProps({
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const display = ref(formatNumberInput(props.modelValue));
const isFocused = ref(false);

// Only react to changes from outside this component (e.g. a form reset)
// while it isn't focused — otherwise the round-trip through the parent's
// v-model would reformat the display (adding commas) on every keystroke,
// resetting the cursor to the end and making mid-string edits impossible.
watch(
    () => props.modelValue,
    (value) => {
        if (!isFocused.value) {
            display.value = formatNumberInput(value);
        }
    },
);

function onInput(event) {
    const raw = parseNumberInput(event.target.value);
    display.value = raw;
    emit('update:modelValue', raw);
}

function onFocus() {
    isFocused.value = true;
    display.value = props.modelValue;
}

function onBlur() {
    isFocused.value = false;
    display.value = formatNumberInput(props.modelValue);
}
</script>

<template>
    <input
        type="text"
        inputmode="numeric"
        maxlength="8"
        :value="display"
        @input="onInput"
        @focus="onFocus"
        @blur="onBlur"
    />
</template>
