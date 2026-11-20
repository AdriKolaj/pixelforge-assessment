<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, ChevronRight, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import { exactUsd } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';

type UserRow = {
    user_id: number;
    email: string;
    generations: number;
    charged_nano: number;
    provider_cost_nano: number;
    profit_nano: number;
};

type DayRow = {
    date: string;
    generations: number;
    charged_nano: number;
    provider_cost_nano: number;
    profit_nano: number;
    pixelforge_cost_nano: number | null;
    matches_pixelforge: boolean | null;
    users: UserRow[];
};

const props = defineProps<{
    report: {
        start: string;
        end: string;
        days: DayRow[];
        totals: Omit<DayRow, 'date' | 'users'>;
        pixelforge_error: string | null;
    };
}>();

const start = ref(props.report.start);
const end = ref(props.report.end);
const expanded = ref<Set<string>>(new Set());

function apply(): void {
    router.get(
        reportRoute.url({ query: { start: start.value, end: end.value } }),
        {},
        {
            preserveState: true,
        },
    );
}

function toggle(date: string): void {
    const next = new Set(expanded.value);

    if (next.has(date)) {
        next.delete(date);
    } else {
        next.add(date);
    }

    expanded.value = next;
}
</script>

<template>
    <Head title="Report" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl font-extrabold">Report</h1>
                <p class="mt-1 max-w-xl text-sm text-ink-muted">
                    Per UTC day, by the day a generation completed (the same way
                    Pixelforge bills). Provider costs are checked against
                    Pixelforge's usage report for this API key.
                </p>
            </div>

            <form class="flex items-end gap-2" @submit.prevent="apply">
                <label class="space-y-1.5">
                    <span class="studio-label block">From</span>
                    <input
                        v-model="start"
                        type="date"
                        class="h-9 rounded-lg border border-subtle bg-field px-2.5 text-sm"
                    />
                </label>
                <label class="space-y-1.5">
                    <span class="studio-label block">To</span>
                    <input
                        v-model="end"
                        type="date"
                        class="h-9 rounded-lg border border-subtle bg-field px-2.5 text-sm"
                    />
                </label>
                <button
                    type="submit"
                    class="h-9 cursor-pointer rounded-lg bg-lime px-3 text-sm font-semibold text-lime-ink hover:bg-lime-hover"
                >
                    Apply
                </button>
            </form>
        </div>

        <div
            v-if="report.pixelforge_error"
            class="flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm"
        >
            <TriangleAlert class="size-4 shrink-0 text-amber-400" />
            Couldn't load Pixelforge usage: {{ report.pixelforge_error }}
        </div>

        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Credit spend</dt>
                <dd class="mt-2 font-mono text-lg tabular-nums">
                    {{ exactUsd(report.totals.charged_nano) }}
                </dd>
            </div>
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Provider cost</dt>
                <dd class="mt-2 font-mono text-lg tabular-nums">
                    {{ exactUsd(report.totals.provider_cost_nano) }}
                </dd>
            </div>
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Profit</dt>
                <dd class="mt-2 font-mono text-lg text-lime tabular-nums">
                    {{ exactUsd(report.totals.profit_nano) }}
                </dd>
            </div>
            <div class="rounded-xl border border-hairline bg-panel p-4">
                <dt class="studio-label">Pixelforge says</dt>
                <dd
                    class="mt-2 flex items-center gap-2 font-mono text-lg tabular-nums"
                >
                    <template
                        v-if="report.totals.pixelforge_cost_nano !== null"
                    >
                        {{ exactUsd(report.totals.pixelforge_cost_nano) }}
                        <Check
                            v-if="report.totals.matches_pixelforge"
                            class="size-4 text-lime"
                            aria-label="Matches"
                        />
                        <TriangleAlert
                            v-else
                            class="size-4 text-amber-400"
                            aria-label="Does not match"
                        />
                    </template>
                    <span v-else class="text-ink-muted">n/a</span>
                </dd>
            </div>
        </dl>

        <div class="overflow-x-auto rounded-xl border border-hairline bg-panel">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-hairline text-left [&>th]:px-4 [&>th]:py-3 [&>th]:font-normal"
                    >
                        <th class="studio-label">Day</th>
                        <th class="studio-label text-right">Images</th>
                        <th class="studio-label text-right">Credit spend</th>
                        <th class="studio-label text-right">Provider cost</th>
                        <th class="studio-label text-right">Profit</th>
                        <th class="studio-label text-right">
                            Pixelforge usage
                        </th>
                    </tr>
                </thead>
                <tbody class="font-mono text-[13px] tabular-nums">
                    <template v-for="day in report.days" :key="day.date">
                        <tr
                            class="border-b border-hairline [&>td]:px-4 [&>td]:py-2.5"
                            :class="{
                                'text-ink-muted':
                                    day.generations === 0 &&
                                    !day.pixelforge_cost_nano,
                            }"
                        >
                            <td class="font-sans">
                                <button
                                    v-if="day.users.length"
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1 font-medium"
                                    :aria-expanded="expanded.has(day.date)"
                                    @click="toggle(day.date)"
                                >
                                    <ChevronRight
                                        class="size-3.5 transition-transform"
                                        :class="{
                                            'rotate-90': expanded.has(day.date),
                                        }"
                                    />
                                    {{ day.date }}
                                </button>
                                <span v-else class="pl-4.5">{{
                                    day.date
                                }}</span>
                            </td>
                            <td class="text-right">{{ day.generations }}</td>
                            <td class="text-right">
                                {{ exactUsd(day.charged_nano) }}
                            </td>
                            <td class="text-right">
                                {{ exactUsd(day.provider_cost_nano) }}
                            </td>
                            <td class="text-right">
                                {{ exactUsd(day.profit_nano) }}
                            </td>
                            <td class="text-right">
                                <span
                                    v-if="day.pixelforge_cost_nano !== null"
                                    class="inline-flex items-center gap-1.5"
                                    :class="{
                                        'text-amber-400':
                                            day.matches_pixelforge === false,
                                    }"
                                >
                                    {{ exactUsd(day.pixelforge_cost_nano) }}
                                    <Check
                                        v-if="day.matches_pixelforge"
                                        class="size-3.5 text-lime"
                                    />
                                    <TriangleAlert v-else class="size-3.5" />
                                </span>
                                <span v-else class="text-ink-muted">n/a</span>
                            </td>
                        </tr>
                        <tr
                            v-for="row in expanded.has(day.date)
                                ? day.users
                                : []"
                            :key="`${day.date}-${row.user_id}`"
                            class="border-b border-hairline bg-field/40 text-ink-secondary [&>td]:px-4 [&>td]:py-2"
                        >
                            <td class="truncate pl-10 font-sans text-xs">
                                {{ row.email }}
                            </td>
                            <td class="text-right">{{ row.generations }}</td>
                            <td class="text-right">
                                {{ exactUsd(row.charged_nano) }}
                            </td>
                            <td class="text-right">
                                {{ exactUsd(row.provider_cost_nano) }}
                            </td>
                            <td class="text-right">
                                {{ exactUsd(row.profit_nano) }}
                            </td>
                            <td />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
