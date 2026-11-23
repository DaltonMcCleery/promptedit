<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleX, ImageOff, LoaderCircle, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { create } from '@/actions/App/Http/Controllers/GenerationController';
import Money from '@/components/Money.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { Generation } from '@/types/generation';

const props = defineProps<{
    generation: Generation;
}>();

const sizeLabels: Record<Generation['size'], string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const aspect: Record<Generation['size'], string> = {
    '1024x1024': 'aspect-square',
    '1536x1024': 'aspect-[3/2]',
    '1024x1536': 'aspect-[2/3]',
};

const imageFailed = ref(false);

const linkExpired = computed(
    () =>
        props.generation.image_url_expires_at !== null &&
        new Date(props.generation.image_url_expires_at).getTime() <= Date.now(),
);

const showImage = computed(
    () =>
        props.generation.status === 'succeeded' &&
        props.generation.image_url !== null &&
        !linkExpired.value &&
        !imageFailed.value,
);

const details = computed(() => {
    const references = props.generation.reference_count;
    const parts = [
        sizeLabels[props.generation.size],
        props.generation.quality[0].toUpperCase() +
            props.generation.quality.slice(1),
    ];

    if (references > 0) {
        parts.push(
            `${references} reference image${references === 1 ? '' : 's'}`,
        );
    }

    return parts.join(' · ');
});

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        :data-test="`generation-${generation.id}`"
    >
        <div
            class="flex w-full items-center justify-center bg-muted text-sm text-muted-foreground"
            :class="aspect[generation.size]"
        >
            <img
                v-if="showImage"
                :src="generation.image_url!"
                :alt="generation.prompt"
                class="h-full w-full object-cover"
                loading="lazy"
                @error="imageFailed = true"
            />
            <div
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-2 p-4 text-center"
            >
                <ImageOff class="size-6" aria-hidden="true" />
                The image link expired.
            </div>
            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 p-4 text-center"
            >
                <CircleX class="size-6" aria-hidden="true" />
                No image
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 p-4 text-center"
            >
                <LoaderCircle
                    class="size-6 animate-spin motion-reduce:animate-none"
                    aria-hidden="true"
                />
                Generating…
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-2">
                <StatusBadge
                    :status="generation.status"
                    :pending-reason="generation.pending_reason"
                />
                <time
                    :datetime="generation.created_at"
                    class="text-xs text-muted-foreground"
                    >{{ createdAt }}</time
                >
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>
            <p class="text-xs text-muted-foreground">{{ details }}</p>

            <dl class="grid grid-cols-2 gap-x-2 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Estimated</dt>
                <dd class="text-right">
                    <Money :nanos="generation.estimate_min_nanos" /> –
                    <Money :nanos="generation.estimate_max_nanos" />
                </dd>
                <dt class="text-muted-foreground">Charged</dt>
                <dd class="text-right font-medium" data-test="charge">
                    <Money
                        v-if="
                            generation.status === 'succeeded' &&
                            generation.charge_nanos !== null
                        "
                        :nanos="generation.charge_nanos"
                    />
                    <span v-else-if="generation.status === 'failed'"
                        >$0.00, not charged</span
                    >
                    <span v-else class="font-normal text-muted-foreground"
                        >When the image is ready</span
                    >
                </dd>
            </dl>

            <div
                v-if="generation.status === 'failed'"
                class="mt-auto flex flex-col gap-3"
            >
                <p
                    class="text-sm text-red-700 dark:text-red-400"
                    data-test="failure-reason"
                >
                    {{ generation.failure_reason }}
                </p>
                <Button variant="outline" size="sm" as-child class="w-fit">
                    <Link :href="create({ query: { from: generation.id } })">
                        <RotateCcw aria-hidden="true" /> Try again
                    </Link>
                </Button>
            </div>
        </div>
    </article>
</template>
