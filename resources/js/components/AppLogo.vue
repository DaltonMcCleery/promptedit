<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * The PromptEdit wordmark. Text only, so it follows the theme's text colour.
 * In the collapsed sidebar it shrinks to a single "P".
 */
const props = withDefaults(
    defineProps<{
        size?: 'sm' | 'lg';
        class?: HTMLAttributes['class'];
    }>(),
    { size: 'sm' },
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-baseline font-semibold tracking-tight whitespace-nowrap text-foreground',
                props.size === 'lg' ? 'text-3xl' : 'text-lg',
                props.class,
            )
        "
    >
        <span class="group-data-[collapsible=icon]:hidden" aria-hidden="true">
            Prompt<span class="text-muted-foreground">Edit</span>
        </span>
        <span
            class="hidden group-data-[collapsible=icon]:inline"
            aria-hidden="true"
            >P</span
        >
        <span class="sr-only">PromptEdit</span>
    </span>
</template>
