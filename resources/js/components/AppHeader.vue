<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Moon, Plus, Sun } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { formatUsd } from '@/lib/money';
import { dashboard } from '@/routes';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import type { NavItem } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth.user);
const balance = computed(() => page.props.credits?.balance_nano ?? 0);
const { isCurrentUrl } = useCurrentUrl();
const { resolvedAppearance, updateAppearance } = useAppearance();

const navItems = computed<NavItem[]>(() => [
    { title: 'Studio', href: dashboard() },
    { title: 'Credits', href: credits() },
    ...(user.value.is_admin ? [{ title: 'Report', href: report() }] : []),
]);

function toggleTheme(): void {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <header class="border-b border-hairline bg-panel">
        <div class="flex flex-wrap items-center gap-x-3 px-4 md:gap-x-6">
            <Link :href="dashboard()" class="flex h-14 shrink-0 items-center">
                <AppLogo />
            </Link>

            <!-- Second row on phones, inline from md up. -->
            <nav
                class="order-last -mx-3 flex h-10 w-full items-stretch gap-1 md:order-none md:mx-0 md:h-14 md:w-auto"
            >
                <Link
                    v-for="item in navItems"
                    :key="item.title"
                    :href="item.href"
                    class="relative flex items-center px-3 text-sm font-medium transition-colors"
                    :class="
                        isCurrentUrl(item.href)
                            ? 'text-ink'
                            : 'text-ink-muted hover:text-ink'
                    "
                >
                    {{ item.title }}
                    <span
                        v-if="isCurrentUrl(item.href)"
                        class="absolute inset-x-3 -bottom-px h-0.5 rounded-full bg-lime"
                    />
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <BuyCreditsDialog>
                    <button
                        type="button"
                        class="flex h-9 cursor-pointer items-center gap-2 rounded-lg border border-subtle bg-field pr-1.5 pl-3 text-sm font-medium tabular-nums transition-colors hover:bg-field-hover"
                        :class="{ 'text-destructive-foreground': balance < 0 }"
                    >
                        {{ formatUsd(balance) }}
                        <span
                            class="flex size-6 items-center justify-center rounded-md bg-lime text-lime-ink"
                        >
                            <Plus class="size-3.5" :stroke-width="2.5" />
                            <span class="sr-only">Buy credits</span>
                        </span>
                    </button>
                </BuyCreditsDialog>

                <button
                    type="button"
                    class="flex size-9 cursor-pointer items-center justify-center rounded-lg text-ink-muted transition-colors hover:bg-field hover:text-ink"
                    :aria-label="
                        resolvedAppearance === 'dark'
                            ? 'Switch to light mode'
                            : 'Switch to dark mode'
                    "
                    @click="toggleTheme"
                >
                    <Sun v-if="resolvedAppearance === 'dark'" class="size-4" />
                    <Moon v-else class="size-4" />
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="cursor-pointer rounded-full focus-visible:ring-2 focus-visible:ring-lime focus-visible:outline-none"
                        >
                            <Avatar class="size-8">
                                <AvatarFallback
                                    class="bg-field text-xs font-semibold text-ink"
                                >
                                    {{ getInitials(user.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent class="w-56" align="end">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
