<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    heading: { type: String, required: true },
    subheading: { type: String, default: null },
});
</script>

<template>
    <div class="mx-auto max-w-5xl px-4 py-10">
        <header class="mb-8">
            <div class="flex items-center justify-between">
                <Link
                    href="/"
                    class="text-sm font-medium text-slate-500 transition hover:text-slate-900"
                >
                    Street Listings
                </Link>
                <nav class="flex gap-4 text-sm font-medium text-slate-500">
                    <Link href="/saved-searches" class="transition hover:text-slate-900">Saved searches</Link>
                    <Link href="/alerts" class="transition hover:text-slate-900">Alerts</Link>
                </nav>
            </div>
            <h1 class="mt-2 text-3xl font-bold tracking-tight">{{ heading }}</h1>
            <p v-if="subheading" class="mt-1 text-slate-500">{{ subheading }}</p>
        </header>

        <main>
            <slot />
        </main>
    </div>
</template>
