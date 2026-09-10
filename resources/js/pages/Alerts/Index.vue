<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import ListingCard from '../../components/ListingCard.vue';
import Pagination from '../../components/Pagination.vue';

defineProps({
    alerts: { type: Object, required: true },
});
</script>

<template>
    <Head title="Alerts" />

    <AppLayout heading="Alerts" subheading="New listings matching your saved searches.">
        <div v-if="alerts.data.length" class="grid gap-6">
            <div v-for="alert in alerts.data" :key="alert.id">
                <p class="mb-2 text-xs font-medium text-slate-500">
                    Matched: {{ alert.matched_saved_searches.map((s) => s.name).join(', ') }}
                </p>
                <ListingCard :listing="alert.listing" />
            </div>
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No alerts yet.
        </p>

        <Pagination :meta="alerts.meta" :links="alerts.links" />
    </AppLayout>
</template>
