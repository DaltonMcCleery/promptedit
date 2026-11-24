<script setup lang="ts">
import { ArrowUpRight } from '@lucide/vue';
import { author } from '@/lib/author';

/** A compact "built by" callout with Dalton's photo and a link to his site. */
</script>

<template>
    <a
        :href="author.site"
        target="_blank"
        rel="noopener"
        class="group flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card p-3 text-left transition-colors hover:bg-muted/60 dark:border-sidebar-border"
    >
        <img
            :src="author.photo"
            alt=""
            width="44"
            height="44"
            class="size-11 shrink-0 rounded-full object-cover"
        />
        <span class="min-w-0 flex-1">
            <span class="block text-xs text-muted-foreground">Built by</span>
            <span class="block truncate text-sm font-medium">
                {{ author.name }}
            </span>
            <span class="block truncate text-xs text-muted-foreground">
                {{ author.role }} · {{ author.siteLabel }}
            </span>
        </span>
        <ArrowUpRight
            class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
            aria-hidden="true"
        />
        <span class="sr-only">(opens {{ author.siteLabel }} in a new tab)</span>
    </a>
</template>
