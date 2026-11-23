<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import {
    create,
    store,
} from '@/actions/App/Http/Controllers/GenerationController';
import CostEstimate from '@/components/CostEstimate.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';
import { characterCount, estimate } from '@/lib/pricing';
import type { PriceSheet, Quality, Size } from '@/lib/pricing';

type Initial = {
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
};

const props = defineProps<{
    pricing: PriceSheet | null;
    initial: Initial | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const MAX_PROMPT_CHARACTERS = 4000;
const MAX_REFERENCE_IMAGES = 4;

const sizes: { value: Size; label: string }[] = [
    { value: '1024x1024', label: 'Square · 1024×1024' },
    { value: '1536x1024', label: 'Landscape · 1536×1024' },
    { value: '1024x1536', label: 'Portrait · 1024×1536' },
];

const qualities: { value: Quality; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const form = useForm({
    prompt: props.initial?.prompt ?? '',
    size: props.initial?.size ?? ('1024x1024' as Size),
    quality: props.initial?.quality ?? ('medium' as Quality),
    reference_images: props.initial?.reference_images.length
        ? [...props.initial.reference_images]
        : [],
});

const characters = computed(() => characterCount(form.prompt));
const tooLong = computed(() => characters.value > MAX_PROMPT_CHARACTERS);
const referenceCount = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);

const liveEstimate = computed(() =>
    props.pricing === null
        ? null
        : estimate(
              props.pricing,
              form.prompt,
              form.size,
              form.quality,
              referenceCount.value,
          ),
);

const canSubmit = computed(
    () =>
        props.pricing !== null &&
        form.prompt.trim() !== '' &&
        !tooLong.value &&
        !form.processing,
);

function referenceError(index: number): string | undefined {
    return extraErrors.value[`reference_images.${index}`];
}

/** Errors Inertia's form type doesn't know: one per reference URL, and pricing. */
const extraErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const pricingError = computed(() => extraErrors.value.pricing);
const canAddReference = computed(
    () => form.reference_images.length < MAX_REFERENCE_IMAGES,
);

function addReference(): void {
    if (!canAddReference.value) {
        return;
    }

    form.reference_images.push('');
    const index = form.reference_images.length - 1;
    void nextTick(() => document.getElementById(`reference-${index}`)?.focus());
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

const submitLabel = computed(() => {
    if (form.processing) {
        return 'Submitting…';
    }

    return liveEstimate.value === null
        ? 'Generate'
        : `Generate · up to ${formatUsd(liveEstimate.value.maxNanos)}`;
});

function submit(): void {
    form.submit(store());
}
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Generate an image</h1>
            <p class="text-sm text-muted-foreground">
                Describe what you want, choose a size and quality, and see the
                price before you submit.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            id="prompt-count"
                            class="text-xs tabular-nums"
                            :class="
                                tooLong
                                    ? 'text-red-600 dark:text-red-500'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ characters.toLocaleString() }} /
                            {{ MAX_PROMPT_CHARACTERS.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="6"
                        required
                        aria-describedby="prompt-count"
                        :aria-invalid="tooLong || !!form.errors.prompt"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                    ></textarea>
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="option in sizes"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border border-input px-3 py-2 text-sm has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="option.value"
                                class="accent-primary"
                            />
                            {{ option.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="option in qualities"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border border-input px-3 py-2 text-sm has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="option.value"
                                class="accent-primary"
                            />
                            {{ option.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Reference images
                        <span class="font-normal text-muted-foreground"
                            >(optional, up to 4)</span
                        >
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Image URLs that guide the style. Each one adds 765
                        tokens to the cost.
                    </p>
                    <div
                        v-for="(_, index) in form.reference_images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <label :for="`reference-${index}`" class="sr-only">
                                Reference image {{ index + 1 }}
                            </label>
                            <input
                                :id="`reference-${index}`"
                                v-model="form.reference_images[index]"
                                type="url"
                                inputmode="url"
                                placeholder="https://example.com/style.png"
                                :aria-invalid="!!referenceError(index)"
                                class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReference(index)"
                            >
                                <X />
                            </Button>
                        </div>
                        <InputError :message="referenceError(index)" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                    <div>
                        <Button
                            v-if="canAddReference"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addReference"
                        >
                            <Plus /> Add reference image
                        </Button>
                    </div>
                </fieldset>

                <div class="flex flex-col gap-2">
                    <InputError :message="pricingError" />
                    <div>
                        <Button
                            type="submit"
                            :disabled="!canSubmit"
                            data-test="generate-button"
                        >
                            {{ submitLabel }}
                        </Button>
                    </div>
                </div>
            </form>

            <aside class="lg:sticky lg:top-4 lg:self-start">
                <CostEstimate v-if="liveEstimate" :estimate="liveEstimate" />
                <div
                    v-else
                    role="status"
                    class="rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border"
                >
                    Prices are unavailable right now, so we can't show what this
                    would cost. Generating is paused until Pixelforge's prices
                    load again.
                </div>
            </aside>
        </div>
    </div>
</template>
