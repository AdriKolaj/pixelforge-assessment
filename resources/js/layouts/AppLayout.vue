<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import { Toaster } from '@/components/ui/sonner';

// Studio pages fill the viewport edge to edge; everything else gets a column.
const { fullBleed = false } = defineProps<{
    fullBleed?: boolean;
}>();
</script>

<template>
    <div
        class="flex min-h-dvh flex-col bg-page text-ink"
        :class="{ 'lg:h-dvh': fullBleed }"
    >
        <AppHeader />
        <main v-if="fullBleed" class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
        <main v-else class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
