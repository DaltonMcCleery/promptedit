<script setup lang="ts">
import { computed } from 'vue';
import { displayUsd, exactUsd } from '@/lib/money';

const props = defineProps<{
    nanos: number | bigint;
}>();

const exact = computed(() => exactUsd(props.nanos));
</script>

<template>
    <span class="tabular-nums" :title="`Exactly ${exact}`">{{
        displayUsd(nanos)
    }}</span>
</template>
