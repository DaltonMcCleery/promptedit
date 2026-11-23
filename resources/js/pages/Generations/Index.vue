<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, watch } from 'vue';
import {
    create,
    index,
} from '@/actions/App/Http/Controllers/GenerationController';
import GenerationCard from '@/components/GenerationCard.vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import type { Generation, Paginated } from '@/types/generation';

const props = defineProps<{
    generations: Paginated<Generation>;
    totals: { charge_nanos: number; succeeded_count: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const hasActive = computed(() =>
    props.generations.data.some((generation) => !generation.is_final),
);

/** Refresh every 3 seconds while anything on this page is still running. */
const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'totals'] },
    { autoStart: false },
);

watch(hasActive, (active) => (active ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="History" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">History</h1>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="total-charged"
                >
                    Total charged:
                    <Money
                        :nanos="totals.charge_nanos"
                        class="font-medium text-foreground"
                    />
                    across {{ totals.succeeded_count }}
                    {{
                        totals.succeeded_count === 1
                            ? 'generation'
                            : 'generations'
                    }}. Failed generations are free.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus aria-hidden="true" /> New generation</Link
                >
            </Button>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="rounded-xl border border-dashed border-sidebar-border p-10 text-center text-sm text-muted-foreground"
        >
            No generations yet.
            <Link
                :href="create()"
                class="font-medium text-foreground underline underline-offset-4"
                >Generate your first image</Link
            >.
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.prev"
                as-child
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    >Newer</Link
                >
                <span v-else>Newer</span>
            </Button>
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.next"
                as-child
            >
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    >Older</Link
                >
                <span v-else>Older</span>
            </Button>
        </nav>
    </div>
</template>
