<script setup lang="ts">
import { computed } from 'vue';
import { exactUsd, formatUsd } from '@/lib/money';

const props = withDefaults(
    defineProps<{
        nanos: number | bigint;
        decimals?: number;
    }>(),
    { decimals: 4 },
);

const exact = computed(() => exactUsd(props.nanos));
</script>

<template>
    <span class="tabular-nums" :title="`Exactly ${exact}`">{{
        formatUsd(nanos, decimals)
    }}</span>
</template>
