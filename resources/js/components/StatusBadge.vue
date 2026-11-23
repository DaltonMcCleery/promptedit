<script setup lang="ts">
import {
    CircleCheck,
    CircleX,
    Clock,
    Hourglass,
    LoaderCircle,
    WifiOff,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import type { GenerationStatus, PendingReason } from '@/types/generation';

const props = defineProps<{
    status: GenerationStatus;
    pendingReason: PendingReason | null;
}>();

type Look = { label: string; icon: Component; spin?: boolean; class: string };

const neutral = 'border-border bg-muted text-foreground';

const look = computed<Look>(() => {
    if (
        props.status === 'submitting' &&
        props.pendingReason === 'waiting_for_slot'
    ) {
        return {
            label: 'Waiting for a free slot',
            icon: Hourglass,
            class: neutral,
        };
    }

    if (
        props.status === 'submitting' &&
        props.pendingReason === 'unreachable'
    ) {
        return {
            label: "Can't reach Pixelforge (retrying)",
            icon: WifiOff,
            class: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
        };
    }

    switch (props.status) {
        case 'submitting':
            return {
                label: 'Submitting',
                icon: LoaderCircle,
                spin: true,
                class: neutral,
            };
        case 'queued':
            return { label: 'Queued', icon: Clock, class: neutral };
        case 'processing':
            return {
                label: 'Processing',
                icon: LoaderCircle,
                spin: true,
                class: 'border-blue-300 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                icon: CircleCheck,
                class: 'border-green-300 bg-green-50 text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200',
            };
        default:
            return {
                label: 'Failed',
                icon: CircleX,
                class: 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
            };
    }
});
</script>

<template>
    <span
        aria-live="polite"
        data-test="status-badge"
        class="inline-flex w-fit items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="look.class"
    >
        <component
            :is="look.icon"
            class="size-3.5"
            :class="{ 'animate-spin motion-reduce:animate-none': look.spin }"
            aria-hidden="true"
        />
        {{ look.label }}
    </span>
</template>
