<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../components/AppLayout.vue';
import { formatPrice } from '../../format';

const props = defineProps({
    savedSearches: { type: Array, required: true },
    branches: { type: Array, required: true },
    propertyTypes: { type: Array, required: true },
    tenures: { type: Array, required: true },
});

const regions = computed(() => [...new Set(props.branches.map((b) => b.region))].sort());

const processing = ref(false);
const form = ref({
    name: '',
    min_price: '',
    max_price: '',
    min_bedrooms: '',
    max_bedrooms: '',
    min_bathrooms: '',
    max_bathrooms: '',
    property_type: '',
    region: '',
    tenure: '',
});

function create() {
    const payload = Object.fromEntries(
        Object.entries(form.value).filter(([, value]) => value !== ''),
    );

    router.post('/saved-searches', payload, {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onSuccess: () => {
            form.value = {
                name: '', min_price: '', max_price: '', min_bedrooms: '', max_bedrooms: '',
                min_bathrooms: '', max_bathrooms: '', property_type: '', region: '', tenure: '',
            };
        },
    });
}

function destroy(savedSearch) {
    router.delete(`/saved-searches/${savedSearch.id}`);
}

function summarize(savedSearch) {
    const parts = [];
    if (savedSearch.min_bedrooms || savedSearch.max_bedrooms) {
        parts.push(`${savedSearch.min_bedrooms ?? 'any'}–${savedSearch.max_bedrooms ?? 'any'} bed`);
    }
    if (savedSearch.property_type_label) parts.push(savedSearch.property_type_label);
    if (savedSearch.tenure_label) parts.push(savedSearch.tenure_label);
    if (savedSearch.region) parts.push(savedSearch.region);
    if (savedSearch.max_price) parts.push(`under ${formatPrice(savedSearch.max_price)}`);
    return parts.length ? parts.join(' · ') : 'Any listing';
}

const fieldClasses =
    'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900';
</script>

<template>
    <Head title="Saved searches" />

    <AppLayout heading="Saved searches" subheading="Get alerted when a new listing matches.">
        <form class="mb-8 grid gap-3 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-3" @submit.prevent="create">
            <div class="flex flex-col gap-1 sm:col-span-3">
                <label for="name" class="text-xs font-medium text-slate-600">Name</label>
                <input id="name" v-model="form.name" type="text" required :class="fieldClasses" placeholder="e.g. 2-bed Chester under 300k" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="max_price" class="text-xs font-medium text-slate-600">Max price (£)</label>
                <input id="max_price" v-model="form.max_price" type="number" min="0" max="20000000" :class="fieldClasses" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="min_bedrooms" class="text-xs font-medium text-slate-600">Min beds</label>
                <input id="min_bedrooms" v-model="form.min_bedrooms" type="number" min="0" max="10" :class="fieldClasses" />
            </div>

            <div class="flex flex-col gap-1">
                <label for="property_type" class="text-xs font-medium text-slate-600">Type</label>
                <select id="property_type" v-model="form.property_type" :class="fieldClasses">
                    <option value="">Any type</option>
                    <option v-for="type in propertyTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="tenure" class="text-xs font-medium text-slate-600">Tenure</label>
                <select id="tenure" v-model="form.tenure" :class="fieldClasses">
                    <option value="">Any tenure</option>
                    <option v-for="tenure in tenures" :key="tenure.value" :value="tenure.value">{{ tenure.label }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="region" class="text-xs font-medium text-slate-600">Area</label>
                <select id="region" v-model="form.region" :class="fieldClasses">
                    <option value="">Any area</option>
                    <option v-for="region in regions" :key="region" :value="region">{{ region }}</option>
                </select>
            </div>

            <button type="submit" :disabled="processing" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50 sm:col-span-3 sm:w-fit">
                Save search
            </button>
        </form>

        <div v-if="savedSearches.length" class="grid gap-3">
            <div v-for="savedSearch in savedSearches" :key="savedSearch.id" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                <div>
                    <Link :href="`/saved-searches/${savedSearch.id}`" class="font-medium text-slate-900 hover:underline">
                        {{ savedSearch.name }}
                    </Link>
                    <p class="mt-1 text-sm text-slate-500">{{ summarize(savedSearch) }}</p>
                </div>
                <button type="button" class="text-sm text-slate-500 underline-offset-2 hover:underline" @click="destroy(savedSearch)">
                    Delete
                </button>
            </div>
        </div>
        <p v-else class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No saved searches yet.
        </p>
    </AppLayout>
</template>
