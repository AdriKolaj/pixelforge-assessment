<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { watch } from 'vue';
import Composer from '@/components/studio/Composer.vue';
import GenerationCard from '@/components/studio/GenerationCard.vue';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: { fullBleed: true },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    hasActive: boolean;
    options: {
        sizes: string[];
        qualities: string[];
        max_prompt_length: number;
        max_reference_images: number;
    };
}>();

// Webhooks update the database; the page just needs to look again while
// something is still running.
const { start, stop, polling } = usePoll(
    2500,
    { only: ['generations', 'hasActive', 'credits'] },
    { autoStart: false },
);

watch(
    () => props.hasActive,
    (active) => (active ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="Studio" />

    <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
        <Composer :options="options" />

        <section class="min-w-0 flex-1 lg:overflow-y-auto">
            <div
                class="sticky top-0 z-10 flex h-12 items-center gap-3 border-b border-hairline bg-page/90 px-5 backdrop-blur"
            >
                <h2 class="text-sm font-semibold">History</h2>
                <span class="text-xs text-ink-muted tabular-nums">
                    {{ generations.meta.total }}
                    {{
                        generations.meta.total === 1
                            ? 'generation'
                            : 'generations'
                    }}
                </span>
                <span
                    v-if="polling"
                    class="ml-auto flex items-center gap-1.5 text-[11px] text-ink-muted"
                >
                    <span class="size-1.5 animate-pulse rounded-full bg-lime" />
                    Live
                </span>
            </div>

            <div class="p-5">
                <div
                    v-if="generations.data.length === 0"
                    class="flex flex-col items-center rounded-xl border border-hairline bg-panel px-6 py-12 text-center"
                >
                    <Sparkles class="mb-3 size-5 text-lime" />
                    <p class="mb-1 font-semibold">Nothing here yet</p>
                    <p class="max-w-sm text-sm text-ink-muted">
                        Describe an image on the left and hit Generate. You'll
                        see the estimate first, and the real cost once it's
                        done.
                    </p>
                </div>

                <div
                    v-else
                    class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] items-start gap-4"
                >
                    <GenerationCard
                        v-for="generation in generations.data"
                        :key="generation.id"
                        :generation="generation"
                    />
                </div>

                <nav
                    v-if="generations.meta.last_page > 1"
                    class="mt-6 flex items-center justify-between text-sm"
                >
                    <Link
                        v-if="generations.links.prev"
                        :href="generations.links.prev"
                        preserve-scroll
                        class="rounded-lg border border-subtle bg-field px-3 py-1.5 hover:bg-field-hover"
                    >
                        Newer
                    </Link>
                    <span v-else />
                    <span class="text-xs text-ink-muted tabular-nums">
                        Page {{ generations.meta.current_page }} of
                        {{ generations.meta.last_page }}
                    </span>
                    <Link
                        v-if="generations.links.next"
                        :href="generations.links.next"
                        preserve-scroll
                        class="rounded-lg border border-subtle bg-field px-3 py-1.5 hover:bg-field-hover"
                    >
                        Older
                    </Link>
                    <span v-else />
                </nav>
            </div>
        </section>
    </div>
</template>
