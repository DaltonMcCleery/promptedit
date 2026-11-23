<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
</script>

<template>
    <Head title="Welcome" />

    <main
        class="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6 text-center text-foreground"
    >
        <h1><AppLogo size="lg" /></h1>
        <p class="max-w-md text-sm text-muted-foreground">
            Generate images from a prompt. See the price range before you
            submit, pay exactly what each image cost once it's ready, and
            nothing for failures.
        </p>
        <div class="flex gap-3">
            <Button as-child>
                <Link :href="register()">Create an account</Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="login()">Log in</Link>
            </Button>
        </div>
    </main>
</template>
