<script setup lang="ts">
import { ImageOff, LoaderCircle, TriangleAlert } from '@lucide/vue';
import { useTimeAgo } from '@vueuse/core';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/studio/StatusBadge.vue';
import {
    aspectRatio,
    capitalize,
    isActive,
    sizeLabels,
} from '@/lib/generation';
import { exactUsd, formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const createdAgo = useTimeAgo(() => props.generation.created_at);
const imageBroken = ref(false);

const active = computed(() => isActive(props.generation));
const showImage = computed(
    () => props.generation.image_url !== null && !imageBroken.value,
);

const progressLabel = computed(() => {
    switch (props.generation.status) {
        case 'pending':
            return 'Sending to Pixelforge…';
        case 'queued':
            return 'Waiting in queue…';
        default:
            return 'Generating…';
    }
});
</script>

<template>
    <article class="overflow-hidden rounded-xl border border-hairline bg-panel">
        <div
            class="relative overflow-hidden bg-field"
            :style="{ aspectRatio: aspectRatio(generation.size) }"
        >
            <a
                v-if="showImage"
                :href="generation.image_url!"
                target="_blank"
                rel="noopener"
                class="block size-full"
            >
                <img
                    :src="generation.image_url!"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-cover"
                    @error="imageBroken = true"
                />
            </a>

            <div
                v-else-if="active"
                class="studio-shimmer flex size-full flex-col items-center justify-center gap-2 text-ink-muted"
            >
                <LoaderCircle class="size-5 animate-spin" />
                <span class="text-xs">{{ progressLabel }}</span>
            </div>

            <div
                v-else-if="generation.status === 'failed'"
                class="flex size-full flex-col items-center justify-center gap-2 p-6 text-center"
            >
                <TriangleAlert class="size-5 text-red-400" />
                <p class="text-xs leading-relaxed text-ink-secondary">
                    {{ generation.error_message }}
                </p>
            </div>

            <div
                v-else
                class="flex size-full flex-col items-center justify-center gap-2 p-6 text-center text-ink-muted"
            >
                <ImageOff class="size-5" />
                <p class="text-xs leading-relaxed">
                    The image link has expired.<br />
                    Pixelforge keeps images for 24 hours.
                </p>
            </div>

            <StatusBadge
                :status="generation.status"
                class="absolute top-2 left-2"
            />
        </div>

        <div class="space-y-2.5 p-3">
            <p
                class="line-clamp-2 text-sm leading-snug"
                :title="generation.prompt"
            >
                {{ generation.prompt }}
            </p>

            <div
                class="flex flex-wrap items-center gap-x-1.5 text-[11px] text-ink-muted"
            >
                <span>{{
                    sizeLabels[generation.size] ?? generation.size
                }}</span>
                <span>·</span>
                <span>{{ capitalize(generation.quality) }}</span>
                <template v-if="generation.reference_images.length">
                    <span>·</span>
                    <span> {{ generation.reference_images.length }} ref </span>
                </template>
                <span>·</span>
                <time :datetime="generation.created_at">{{ createdAgo }}</time>
            </div>

            <div
                class="flex items-baseline justify-between gap-2 border-t border-hairline pt-2.5 text-xs tabular-nums"
            >
                <template
                    v-if="
                        generation.status === 'succeeded' &&
                        generation.charge_nano !== null
                    "
                >
                    <span
                        class="font-semibold"
                        :title="exactUsd(generation.charge_nano)"
                    >
                        Charged {{ formatUsd(generation.charge_nano) }}
                    </span>
                    <span
                        class="text-ink-muted"
                        :title="exactUsd(generation.estimated_charge_nano)"
                    >
                        est. {{ formatUsd(generation.estimated_charge_nano) }}
                    </span>
                </template>
                <template v-else-if="generation.status === 'failed'">
                    <span class="font-semibold">Not charged</span>
                    <span class="text-ink-muted line-through">
                        {{ formatUsd(generation.estimated_charge_nano) }}
                    </span>
                </template>
                <template v-else>
                    <span :title="exactUsd(generation.estimated_charge_nano)">
                        ≈ {{ formatUsd(generation.estimated_charge_nano) }}
                    </span>
                    <span class="text-ink-muted">
                        up to {{ formatUsd(generation.max_charge_nano) }}
                    </span>
                </template>
            </div>
        </div>
    </article>
</template>
