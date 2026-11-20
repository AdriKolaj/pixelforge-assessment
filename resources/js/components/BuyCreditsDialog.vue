<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { formatUsd } from '@/lib/money';
import { store } from '@/routes/credits';

const page = usePage();
const open = ref(false);
const buying = ref<number | null>(null);

function buy(amount: number): void {
    router.post(
        store(),
        { amount },
        {
            preserveScroll: true,
            onStart: () => (buying.value = amount),
            onSuccess: () => (open.value = false),
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>

        <DialogContent class="bg-panel sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="font-display text-xl font-extrabold">
                    Buy credits
                </DialogTitle>
                <DialogDescription>
                    Credits are US dollars. This is a demo checkout, so no
                    payment is taken.
                </DialogDescription>
            </DialogHeader>

            <div v-if="page.props.credits" class="text-sm text-ink-muted">
                Current balance
                <span class="font-medium text-ink tabular-nums">{{
                    formatUsd(page.props.credits.balance_nano)
                }}</span>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="amount in page.props.creditPacks"
                    :key="amount"
                    type="button"
                    class="flex h-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-subtle bg-field transition-colors hover:border-lime hover:bg-field-hover disabled:cursor-wait disabled:opacity-60"
                    :disabled="buying !== null"
                    @click="buy(amount)"
                >
                    <span
                        class="font-display text-2xl font-extrabold tabular-nums"
                    >
                        ${{ amount }}
                    </span>
                    <span class="text-[11px] text-ink-muted">
                        {{ buying === amount ? 'Adding…' : 'Add credits' }}
                    </span>
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
