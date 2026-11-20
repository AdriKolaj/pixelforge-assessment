<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import { exactUsd, formatUsd } from '@/lib/money';

type Transaction = {
    id: number;
    type: 'purchase' | 'generation';
    amount_nano: number;
    description: string;
    generation: {
        id: number;
        prompt: string;
        size: string;
        quality: string;
    } | null;
    created_at: string;
};

defineProps<{
    transactions: {
        data: Transaction[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const page = usePage();
const balance = computed(() => page.props.credits?.balance_nano ?? 0);
const available = computed(() => page.props.credits?.available_nano ?? 0);
const reserved = computed(() => balance.value - available.value);

const dateFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Credits" />

    <div class="space-y-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl font-extrabold">Credits</h1>
                <p class="mt-1 text-sm text-ink-muted">
                    One credit is one US dollar. Amounts are kept to the
                    nanodollar; hover any figure for the exact value.
                </p>
            </div>
            <BuyCreditsDialog>
                <button
                    type="button"
                    class="h-10 cursor-pointer rounded-xl bg-lime px-4 text-sm font-bold tracking-wide text-lime-ink uppercase hover:bg-lime-hover"
                >
                    Buy credits
                </button>
            </BuyCreditsDialog>
        </div>

        <dl class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Balance</dt>
                <dd
                    class="mt-2 font-display text-2xl font-extrabold tabular-nums"
                    :title="exactUsd(balance)"
                >
                    {{ formatUsd(balance) }}
                </dd>
            </div>
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Held for running generations</dt>
                <dd
                    class="mt-2 font-display text-2xl font-extrabold tabular-nums"
                    :title="exactUsd(reserved)"
                >
                    {{ formatUsd(reserved) }}
                </dd>
            </div>
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Available</dt>
                <dd
                    class="mt-2 font-display text-2xl font-extrabold tabular-nums"
                    :title="exactUsd(available)"
                >
                    {{ formatUsd(available) }}
                </dd>
            </div>
        </dl>

        <section
            class="overflow-hidden rounded-xl border border-hairline bg-panel"
        >
            <h2
                class="border-b border-hairline px-4 py-3 text-sm font-semibold"
            >
                Activity
            </h2>

            <p
                v-if="transactions.data.length === 0"
                class="px-4 py-10 text-center text-sm text-ink-muted"
            >
                No activity yet. Buy some credits to get started.
            </p>

            <ul v-else class="divide-y divide-hairline">
                <li
                    v-for="transaction in transactions.data"
                    :key="transaction.id"
                    class="flex items-center gap-4 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm">
                            {{
                                transaction.generation?.prompt ??
                                transaction.description
                            }}
                        </p>
                        <p class="text-[11px] text-ink-muted">
                            {{
                                transaction.type === 'purchase'
                                    ? 'Credit purchase'
                                    : `Generation #${transaction.generation?.id}`
                            }}
                            ·
                            {{
                                dateFormat.format(
                                    new Date(transaction.created_at),
                                )
                            }}
                        </p>
                    </div>
                    <span
                        class="text-sm font-semibold tabular-nums"
                        :class="{ 'text-lime': transaction.amount_nano > 0 }"
                        :title="exactUsd(transaction.amount_nano)"
                    >
                        {{ transaction.amount_nano > 0 ? '+' : ''
                        }}{{ formatUsd(transaction.amount_nano) }}
                    </span>
                </li>
            </ul>

            <nav
                v-if="transactions.prev_page_url || transactions.next_page_url"
                class="flex justify-between border-t border-hairline px-4 py-3 text-sm"
            >
                <Link
                    v-if="transactions.prev_page_url"
                    :href="transactions.prev_page_url"
                    class="text-ink-muted hover:text-ink"
                >
                    Newer
                </Link>
                <span v-else />
                <Link
                    v-if="transactions.next_page_url"
                    :href="transactions.next_page_url"
                    class="text-ink-muted hover:text-ink"
                >
                    Older
                </Link>
            </nav>
        </section>
    </div>
</template>
