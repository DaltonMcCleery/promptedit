<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    Globe,
    Mail,
    Receipt,
    ShieldCheck,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { author } from '@/lib/author';
import { login, register } from '@/routes';

const features = [
    {
        icon: Receipt,
        title: 'Know the price first',
        body: 'See the lowest and highest a generation can cost before you submit, broken down by token type. You never pay more than the maximum shown.',
    },
    {
        icon: Activity,
        title: 'Watch it happen',
        body: 'History updates live as each image moves from queued to processing to done, with no refreshing.',
    },
    {
        icon: ShieldCheck,
        title: 'Failures are free',
        body: 'Blocked prompts, overloads and failed images cost nothing. Each image is charged once, exactly, even when the provider repeats itself.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-5"
        >
            <AppLogo />
            <nav class="flex items-center gap-2" aria-label="Account">
                <Button variant="ghost" as-child>
                    <Link :href="login()">Log in</Link>
                </Button>
                <Button as-child>
                    <Link :href="register()">Create an account</Link>
                </Button>
            </nav>
        </header>

        <main class="flex-1">
            <section
                class="mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 lg:grid-cols-[1.1fr_1fr] lg:py-24"
            >
                <div class="flex flex-col gap-6">
                    <p
                        class="w-fit rounded-full border border-sidebar-border px-3 py-1 text-xs text-muted-foreground"
                    >
                        AI image generation, priced honestly
                    </p>
                    <h1
                        class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Generate images. Pay exactly what they cost.
                    </h1>
                    <p class="max-w-xl text-lg text-muted-foreground">
                        Write a prompt, pick a size and quality, and see the
                        price range up front. Once your image is ready you pay
                        the provider's actual cost plus 25%, to the billionth of
                        a dollar, and nothing if it fails.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button size="lg" as-child>
                            <Link :href="register()">
                                Start generating
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="login()">I have an account</Link>
                        </Button>
                    </div>
                </div>

                <figure
                    class="rounded-2xl border border-sidebar-border/70 bg-card p-6 shadow-sm dark:border-sidebar-border"
                    aria-label="Example of a generation's price"
                >
                    <p class="text-sm text-muted-foreground">
                        A lighthouse on a cliff at dawn, oil painting
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Landscape · High · 1 reference image
                    </p>
                    <dl class="mt-6 grid gap-3 text-sm">
                        <div class="flex items-baseline justify-between">
                            <dt class="text-muted-foreground">
                                Estimate before submitting
                            </dt>
                            <dd
                                class="font-medium whitespace-nowrap tabular-nums"
                            >
                                $0.3478 – $0.4572
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between">
                            <dt class="text-muted-foreground">
                                Provider's actual cost
                            </dt>
                            <dd class="tabular-nums">$0.364077500</dd>
                        </div>
                        <div class="flex items-baseline justify-between">
                            <dt class="text-muted-foreground">
                                PromptEdit fee (25%)
                            </dt>
                            <dd class="tabular-nums">$0.091019375</dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between border-t border-sidebar-border pt-3"
                        >
                            <dt class="font-medium">You pay</dt>
                            <dd class="text-2xl font-semibold tabular-nums">
                                $0.455096875
                            </dd>
                        </div>
                    </dl>
                    <figcaption class="mt-4 text-xs text-muted-foreground">
                        A real generation from this app, recorded to the
                        nanodollar.
                    </figcaption>
                </figure>
            </section>

            <section
                class="mx-auto grid max-w-6xl gap-4 px-6 pb-20 sm:grid-cols-3"
                aria-label="Features"
            >
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
                >
                    <component
                        :is="feature.icon"
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h2 class="mt-3 font-medium">{{ feature.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.body }}
                    </p>
                </article>
            </section>

            <section class="border-t border-sidebar-border/70 bg-muted/40">
                <div
                    class="mx-auto flex max-w-6xl flex-col gap-6 px-6 py-16 md:flex-row md:items-center md:justify-between"
                >
                    <div class="max-w-xl">
                        <p class="text-sm text-muted-foreground">Built by</p>
                        <div class="mt-2 flex items-center gap-4">
                            <img
                                :src="author.photo"
                                :alt="`Photo of ${author.name}`"
                                width="72"
                                height="72"
                                class="size-18 rounded-full object-cover ring-4 ring-background"
                            />
                            <div>
                                <h2
                                    class="text-2xl font-semibold tracking-tight"
                                >
                                    {{ author.name }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    {{ author.role }}
                                </p>
                            </div>
                        </div>
                        <p class="mt-4 text-muted-foreground">
                            I build Laravel applications with a focus on solid
                            backends: integrations, money, and the edge cases in
                            between. PromptEdit is built with Laravel, Vue and
                            Inertia on the Pixelforge API. See more of my work,
                            open-source packages and experience on my site.
                        </p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row md:flex-col">
                        <Button size="lg" as-child>
                            <a
                                :href="author.site"
                                target="_blank"
                                rel="noopener"
                            >
                                <Globe aria-hidden="true" /> daltonmccleery.com
                            </a>
                        </Button>
                        <div class="flex gap-2">
                            <Button variant="outline" class="flex-1" as-child>
                                <a
                                    :href="author.github"
                                    target="_blank"
                                    rel="noopener"
                                    >GitHub</a
                                >
                            </Button>
                            <Button variant="outline" class="flex-1" as-child>
                                <a
                                    :href="author.x"
                                    target="_blank"
                                    rel="noopener"
                                    >X</a
                                >
                            </Button>
                            <Button variant="outline" class="flex-1" as-child>
                                <a :href="`mailto:${author.email}`">
                                    <Mail aria-hidden="true" /> Email
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-6 py-6 text-xs text-muted-foreground sm:flex-row sm:justify-between"
        >
            <p>
                © {{ new Date().getFullYear() }}
                <a
                    :href="author.site"
                    class="underline-offset-4 hover:underline"
                    target="_blank"
                    rel="noopener"
                    >{{ author.name }}</a
                >
            </p>
            <p>
                Images are generated by Pixelforge, a simulated provider. No
                real money is charged.
            </p>
        </footer>
    </div>
</template>
