<script setup lang="ts" generic="T extends string">
export type SegmentedOption<V> = {
    value: V;
    label: string;
    hint?: string;
};

defineProps<{
    options: SegmentedOption<T>[];
    label: string;
}>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="flex gap-0.5 rounded-lg border border-subtle bg-page/40 p-0.5"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            :title="option.hint"
            class="flex h-8 min-w-0 flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[5px] border px-2 text-xs font-semibold tabular-nums transition-colors"
            :class="
                model === option.value
                    ? 'border-subtle bg-field text-ink'
                    : 'border-transparent text-ink-muted hover:bg-field-hover hover:text-ink'
            "
            @click="model = option.value"
        >
            <slot name="icon" :option="option" />
            {{ option.label }}
        </button>
    </div>
</template>
