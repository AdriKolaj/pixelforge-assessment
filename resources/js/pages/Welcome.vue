<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { dashboard, login, register } from '@/routes';

const facts = [
    { value: '25%', label: 'flat markup on cost' },
    { value: '$0', label: 'for failed images' },
    { value: '9', label: 'decimal places, no rounding' },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-dvh flex-col bg-page text-ink">
        <header class="flex h-14 items-center justify-between px-4 sm:px-6">
            <AppLogo />
            <nav class="flex items-center gap-2 text-sm font-medium">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-lg bg-lime px-3 py-1.5 text-lime-ink hover:bg-lime-hover"
                >
                    Open studio
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-lg px-3 py-1.5 text-ink-secondary hover:text-ink"
                    >
                        Sign in
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-lg bg-ink px-3 py-1.5 text-page hover:opacity-90"
                    >
                        Sign up
                    </Link>
                </template>
            </nav>
        </header>

        <main
            class="flex flex-1 flex-col items-center justify-center px-4 py-16 text-center"
        >
            <span
                class="mb-6 inline-flex items-center gap-2 rounded-full border border-subtle bg-panel px-3 py-1 text-xs text-ink-secondary"
            >
                <span class="size-1.5 rounded-full bg-lime" />
                Powered by Pixelforge
            </span>

            <h1
                class="max-w-3xl font-display text-5xl leading-[0.95] font-extrabold tracking-tight text-balance sm:text-7xl"
            >
                Images on demand.
                <span class="text-lime">Priced to the token.</span>
            </h1>

            <p class="mt-6 max-w-xl text-base text-ink-muted sm:text-lg">
                See the price before you generate, pay what the image actually
                used once it's done, and nothing at all if it fails.
            </p>

            <Link
                :href="$page.props.auth.user ? dashboard() : register()"
                class="mt-10 inline-flex h-12 items-center gap-2 rounded-2xl bg-lime px-6 font-bold text-lime-ink shadow-[0_8px_22px_-6px_rgb(154_230_0/0.45)] hover:bg-lime-hover"
            >
                Start creating
                <ArrowRight class="size-4" />
            </Link>

            <dl
                class="mt-14 grid w-full max-w-lg grid-cols-3 divide-x divide-hairline rounded-2xl border border-hairline bg-panel py-4"
            >
                <div v-for="fact in facts" :key="fact.label" class="px-2">
                    <dt class="sr-only">{{ fact.label }}</dt>
                    <dd class="font-display text-2xl font-extrabold">
                        {{ fact.value }}
                    </dd>
                    <dd class="mt-1 text-[11px] text-ink-muted">
                        {{ fact.label }}
                    </dd>
                </div>
            </dl>
        </main>
    </div>
</template>
