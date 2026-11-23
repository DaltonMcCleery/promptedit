<script setup lang="ts">
import { computed } from 'vue';
import { displayUsd, exactUsd } from '@/lib/money';

const props = defineProps<{
    nanos: number | bigint;
    /** Show all 9 decimals, for reconciling with Pixelforge. */
    exact?: boolean;
}>();

const exactAmount = computed(() => exactUsd(props.nanos));
</script>

<template>
    <span class="tabular-nums" :title="`Exactly ${exactAmount}`">{{
        exact ? exactAmount : displayUsd(nanos)
    }}</span>
</template>
