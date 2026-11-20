<script setup lang="ts">
import { useForm, useHttp, usePage } from '@inertiajs/vue3';
import { ImagePlus, Info, Square, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import InputError from '@/components/InputError.vue';
import SegmentedControl from '@/components/studio/SegmentedControl.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { capitalize, sizeLabels } from '@/lib/generation';
import { exactUsd, formatUsd } from '@/lib/money';
import { quote as quoteRoute, store } from '@/routes/generations';
import type { Quote } from '@/types';

const props = defineProps<{
    options: {
        sizes: string[];
        qualities: string[];
        max_prompt_length: number;
        max_reference_images: number;
    };
}>();

const page = usePage();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const sizeOptions = props.options.sizes.map((size) => ({
    value: size,
    label: sizeLabels[size] ?? size,
    hint: size.replace('x', ' × '),
}));

const qualityOptions = props.options.qualities.map((quality) => ({
    value: quality,
    label: capitalize(quality),
}));

// Blank URL rows are just UI; they never leave the browser.
const referenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);

const quoteRequest = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_count: number;
    },
    Quote
>({ prompt: '', size: '', quality: '', reference_image_count: 0 });
const quote = ref<Quote | null>(null);
const quoteFailed = ref(false);
let latestQuote = 0;

watchDebounced(
    () => [form.prompt, form.size, form.quality, referenceImages.value.length],
    async () => {
        const id = ++latestQuote;

        quoteRequest.cancel();
        quoteRequest.prompt = form.prompt;
        quoteRequest.size = form.size;
        quoteRequest.quality = form.quality;
        quoteRequest.reference_image_count = referenceImages.value.length;

        try {
            const result = await quoteRequest.post(quoteRoute().url);

            if (id === latestQuote) {
                quote.value = result;
                quoteFailed.value = false;
            }
        } catch {
            // Ignore requests we cancelled ourselves for a newer one.
            if (id === latestQuote) {
                quoteFailed.value = true;
            }
        }
    },
    { debounce: 250, immediate: true },
);

// The balance updates while generations finish, so don't trust the
// available amount from the last quote.
const available = computed(() => page.props.credits?.available_nano ?? 0);
const canAfford = computed(
    () =>
        quote.value === null || available.value >= quote.value.max_charge_nano,
);
const promptLength = computed(() => [...form.prompt].length);

// Returned by the server when the balance changed between quote and submit.
const creditsError = computed(
    () => (form.errors as Record<string, string | undefined>).credits,
);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        promptLength.value <= props.options.max_prompt_length &&
        canAfford.value &&
        !form.processing,
);

function addReferenceImage(): void {
    if (form.reference_images.length < props.options.max_reference_images) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_images: referenceImages.value,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.clearErrors(),
    });
}
</script>

<template>
    <aside
        class="flex shrink-0 flex-col border-hairline bg-panel bg-linear-to-t from-lime/4 to-panel to-40% lg:w-84 lg:border-r"
    >
        <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
            <div class="flex-1 space-y-5 overflow-y-auto p-4">
                <div
                    class="flex items-center gap-3 rounded-lg border border-subtle bg-field px-3 py-2.5"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-md bg-lime/15 text-lime"
                    >
                        <Square class="size-4" :stroke-width="2.5" />
                    </span>
                    <span class="leading-tight">
                        <span class="block text-[11px] text-ink-muted">
                            Pixelforge
                        </span>
                        <span class="block text-sm font-semibold">
                            Image generation
                        </span>
                    </span>
                </div>

                <section class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="studio-label">Reference images</span>
                        <span class="studio-label text-ink-muted tabular-nums">
                            {{ form.reference_images.length }}/{{
                                options.max_reference_images
                            }}
                        </span>
                    </div>

                    <div
                        v-for="(_, index) in form.reference_images"
                        :key="index"
                        class="space-y-1"
                    >
                        <div class="flex items-center gap-1.5">
                            <input
                                v-model="form.reference_images[index]"
                                type="url"
                                inputmode="url"
                                placeholder="https://…/style.png"
                                :aria-label="`Reference image ${index + 1}`"
                                class="h-9 min-w-0 flex-1 rounded-lg border border-subtle bg-field px-3 text-sm placeholder:text-ink-muted focus:border-lime/60 focus:outline-none"
                            />
                            <button
                                type="button"
                                class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-ink-muted hover:bg-field hover:text-ink"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReferenceImage(index)"
                            >
                                <X class="size-4" />
                            </button>
                        </div>
                        <InputError
                            :message="form.errors[`reference_images.${index}`]"
                        />
                    </div>

                    <button
                        v-if="
                            form.reference_images.length <
                            options.max_reference_images
                        "
                        type="button"
                        class="flex h-9 w-full cursor-pointer items-center gap-2 rounded-lg border border-subtle bg-field px-3 text-sm font-medium transition-colors hover:bg-field-hover"
                        @click="addReferenceImage"
                    >
                        <ImagePlus class="size-4 text-ink-muted" />
                        Add image URL
                    </button>
                    <InputError :message="form.errors.reference_images" />
                </section>

                <section class="space-y-2">
                    <label for="prompt" class="studio-label block">
                        Prompt
                    </label>
                    <div
                        class="rounded-lg border border-subtle bg-field focus-within:border-lime/60"
                    >
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="6"
                            placeholder="Describe the image: subject, setting, light, style."
                            class="block w-full resize-none bg-transparent px-3 pt-2.5 text-sm leading-relaxed placeholder:text-ink-muted focus:outline-none"
                            @keydown.meta.enter.prevent="submit"
                            @keydown.ctrl.enter.prevent="submit"
                        />
                        <div
                            class="flex justify-end px-3 pb-2 text-[11px] text-ink-muted tabular-nums"
                            :class="{
                                'text-red-400':
                                    promptLength > options.max_prompt_length,
                            }"
                        >
                            {{ promptLength.toLocaleString() }} /
                            {{ options.max_prompt_length.toLocaleString() }}
                        </div>
                    </div>
                    <InputError :message="form.errors.prompt" />
                </section>

                <section class="space-y-2">
                    <span class="studio-label block">Aspect ratio</span>
                    <SegmentedControl
                        v-model="form.size"
                        :options="sizeOptions"
                        label="Aspect ratio"
                    >
                        <template #icon="{ option }">
                            <span
                                class="inline-block rounded-[2px] border-[1.5px] border-current opacity-70"
                                :class="{
                                    'h-2.5 w-2.5': option.value === '1024x1024',
                                    'h-2 w-3': option.value === '1536x1024',
                                    'h-3 w-2': option.value === '1024x1536',
                                }"
                            />
                        </template>
                    </SegmentedControl>
                    <InputError :message="form.errors.size" />
                </section>

                <section class="space-y-2">
                    <span class="studio-label block">Quality</span>
                    <SegmentedControl
                        v-model="form.quality"
                        :options="qualityOptions"
                        label="Quality"
                    />
                    <InputError :message="form.errors.quality" />
                </section>
            </div>

            <footer class="space-y-3 border-t border-hairline p-4">
                <div
                    v-if="creditsError || !canAfford"
                    class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs leading-relaxed"
                >
                    <template v-if="creditsError">
                        {{ creditsError }}
                    </template>
                    <template v-else-if="quote">
                        This can cost up to
                        {{ formatUsd(quote.max_charge_nano) }} and you have
                        {{ formatUsd(available) }} available.
                    </template>
                    <BuyCreditsDialog>
                        <button
                            type="button"
                            class="mt-1 block cursor-pointer font-semibold text-lime underline-offset-2 hover:underline"
                        >
                            Buy credits
                        </button>
                    </BuyCreditsDialog>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <template v-if="quote">
                            <div
                                class="flex items-center gap-1 text-sm font-medium tabular-nums"
                            >
                                ≈ {{ formatUsd(quote.estimated_charge_nano) }}
                                <TooltipProvider :delay-duration="100">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="cursor-help text-ink-muted hover:text-ink"
                                                aria-label="How this price is worked out"
                                            >
                                                <Info class="size-3.5" />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            class="max-w-64 space-y-1.5 p-3 text-xs leading-relaxed"
                                        >
                                            <p>
                                                Estimated
                                                {{
                                                    exactUsd(
                                                        quote.estimated_charge_nano,
                                                    )
                                                }}, at most
                                                {{
                                                    exactUsd(
                                                        quote.max_charge_nano,
                                                    )
                                                }}.
                                            </p>
                                            <p class="opacity-80">
                                                Output
                                                {{
                                                    quote.tokens.output.toLocaleString()
                                                }}
                                                tokens · reasoning ~{{
                                                    quote.tokens.reasoning.estimated.toLocaleString()
                                                }}
                                                (up to
                                                {{
                                                    quote.tokens.reasoning.max.toLocaleString()
                                                }}) · prompt ~{{
                                                    quote.tokens.input_text
                                                        .estimated
                                                }}
                                                · images
                                                {{ quote.tokens.input_image }}
                                            </p>
                                            <p class="opacity-80">
                                                You pay the actual token usage
                                                once the image is ready. Failed
                                                generations are free.
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                            <div
                                class="text-[11px] text-ink-muted tabular-nums"
                            >
                                up to {{ formatUsd(quote.max_charge_nano) }}
                            </div>
                        </template>
                        <div
                            v-else-if="quoteFailed"
                            class="text-xs text-ink-muted"
                        >
                            Price unavailable
                        </div>
                        <div
                            v-else
                            class="h-4 w-16 animate-pulse rounded bg-field"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="inline-flex h-11 min-w-40 shrink-0 cursor-pointer items-center justify-center gap-3 rounded-xl border border-black/10 bg-lime px-4.5 text-sm font-bold tracking-wide text-lime-ink uppercase shadow-[inset_0_1px_0_rgb(255_255_255/0.45),inset_0_-2px_0_rgb(38_38_38/0.14),0_1px_2px_rgb(0_0_0/0.5),0_8px_22px_-6px_rgb(154_230_0/0.35)] transition-all hover:bg-lime-hover active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-40 disabled:shadow-none"
                    >
                        {{ form.processing ? 'Sending…' : 'Generate' }}
                        <kbd
                            class="hidden rounded border border-black/15 px-1 font-sans text-[10px] font-semibold opacity-70 sm:inline"
                        >
                            ⌘↵
                        </kbd>
                    </button>
                </div>
            </footer>
        </form>
    </aside>
</template>
