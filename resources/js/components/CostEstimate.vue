<script setup lang="ts">
import Money from '@/components/Money.vue';
import type { Estimate, TokenType } from '@/lib/pricing';

defineProps<{
    estimate: Estimate;
}>();

const labels: Record<TokenType, string> = {
    input_text_tokens: 'Prompt text',
    input_image_tokens: 'Reference images',
    output_tokens: 'Image',
    reasoning_tokens: 'Reasoning',
};

function tokenRange(min: number, max: number): string {
    return min === max
        ? `${min.toLocaleString()} tokens`
        : `${min.toLocaleString()}–${max.toLocaleString()} tokens`;
}
</script>

<template>
    <section
        aria-labelledby="estimate-heading"
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2
            id="estimate-heading"
            class="text-sm font-medium text-muted-foreground"
        >
            Estimated cost
        </h2>
        <p class="mt-1 text-2xl font-semibold" data-test="estimate-range">
            <Money :nanos="estimate.minNanos" />
            <span class="text-muted-foreground"> – </span>
            <Money :nanos="estimate.maxNanos" />
        </p>

        <table class="mt-4 w-full text-sm">
            <caption class="sr-only">
                Cost breakdown by token type
            </caption>
            <tbody>
                <tr
                    v-for="line in estimate.lines"
                    :key="line.type"
                    class="border-b border-sidebar-border/50 last:border-0"
                >
                    <th scope="row" class="py-1.5 text-left font-normal">
                        {{ labels[line.type] }}
                        <span class="block text-xs text-muted-foreground">{{
                            tokenRange(line.minTokens, line.maxTokens)
                        }}</span>
                    </th>
                    <td class="py-1.5 text-right">
                        <template v-if="line.minNanos === line.maxNanos">
                            <Money :nanos="line.maxNanos" />
                        </template>
                        <template v-else>
                            <Money :nanos="line.minNanos" /> –
                            <Money :nanos="line.maxNanos" />
                        </template>
                    </td>
                </tr>
                <tr class="border-t border-sidebar-border">
                    <th scope="row" class="py-1.5 text-left font-normal">
                        Pixelforge's cost
                    </th>
                    <td class="py-1.5 text-right">
                        <Money :nanos="estimate.providerMinNanos" /> –
                        <Money :nanos="estimate.providerMaxNanos" />
                    </td>
                </tr>
                <tr>
                    <th scope="row" class="py-1.5 text-left font-normal">
                        PromptEdit fee (25%)
                    </th>
                    <td class="py-1.5 text-right">
                        <Money
                            :nanos="
                                estimate.minNanos - estimate.providerMinNanos
                            "
                        />
                        –
                        <Money
                            :nanos="
                                estimate.maxNanos - estimate.providerMaxNanos
                            "
                        />
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="mt-4 text-xs text-muted-foreground">
            The prompt's exact token count and the reasoning the model does are
            only known once the image is ready. You'll pay the actual cost then,
            and it won't be more than <Money :nanos="estimate.maxNanos" />. If
            the generation fails, it's free.
        </p>
    </section>
</template>
