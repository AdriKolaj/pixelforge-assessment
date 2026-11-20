<script setup lang="ts">
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Submitting',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const tone = computed(() => {
    switch (props.status) {
        case 'succeeded':
            return 'bg-lime text-lime-ink';
        case 'failed':
            return 'bg-red-500/90 text-white';
        default:
            return 'bg-black/60 text-white backdrop-blur';
    }
});

const active = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.status),
);
</script>

<template>
    <span
        class="inline-flex h-5 items-center gap-1.5 rounded-md px-1.5 text-[10px] font-semibold tracking-[0.06em] uppercase"
        :class="tone"
    >
        <span v-if="active" class="relative flex size-1.5">
            <span
                class="absolute inline-flex size-full animate-ping rounded-full bg-lime opacity-75"
            />
            <span class="relative inline-flex size-1.5 rounded-full bg-lime" />
        </span>
        {{ labels[status] }}
    </span>
</template>
