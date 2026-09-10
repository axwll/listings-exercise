<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import ListingCard from '../../components/ListingCard.vue';
import Pagination from '../../components/Pagination.vue';

defineProps({
    savedSearch: { type: Object, required: true },
    matches: { type: Object, required: true },
});
</script>

<template>
    <Head :title="savedSearch.name" />

    <AppLayout :heading="savedSearch.name" subheading="Live listings matching this search right now.">
        <div class="mb-6 flex flex-wrap gap-2 text-sm text-slate-600">
            <span v-if="savedSearch.min_bedrooms || savedSearch.max_bedrooms" class="rounded-full bg-slate-100 px-3 py-1">
                {{ savedSearch.min_bedrooms ?? 'any' }}–{{ savedSearch.max_bedrooms ?? 'any' }} bed
            </span>
            <span v-if="savedSearch.property_type_label" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.property_type_label }}</span>
            <span v-if="savedSearch.tenure_label" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.tenure_label }}</span>
            <span v-if="savedSearch.region" class="rounded-full bg-slate-100 px-3 py-1">{{ savedSearch.region }}</span>
            <span v-if="savedSearch.max_price" class="rounded-full bg-slate-100 px-3 py-1">under £{{ savedSearch.max_price.toLocaleString() }}</span>
        </div>

        <div v-if="matches.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <ListingCard v-for="listing in matches.data" :key="listing.id" :listing="listing" />
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            Nothing matches this search yet.
        </p>

        <Pagination :meta="matches.meta" :links="matches.links" />

        <Link href="/saved-searches" class="mt-6 inline-block text-sm text-slate-500 hover:text-slate-900">
            &larr; Back to saved searches
        </Link>
    </AppLayout>
</template>
