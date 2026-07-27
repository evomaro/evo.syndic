<script setup lang="ts">
import axios from "axios";
import { ref, watch } from "vue";
import { useI18n } from "@/i18n";
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const value = defineModel<number | string | null>();
const query = ref("");
const results = ref<any[]>([]);
let timer: ReturnType<typeof setTimeout>;
watch(query, (term) => {
    clearTimeout(timer);
    if (term.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        results.value = (
            await axios.get(route("suppliers.search"), { params: { q: term } })
        ).data.data;
    }, 250);
});
</script>
<template>
    <div class="relative">
        <input
            v-model="query"
            class="w-full rounded-lg border-slate-300"
            :placeholder="text('Rechercher un fournisseur…', 'البحث عن مورد…')"
            autocomplete="off"
        />
        <div
            v-if="results.length"
            class="absolute z-20 mt-1 w-full rounded-lg border bg-white p-1 shadow-xl"
        >
            <button
                v-for="supplier in results"
                :key="supplier.id"
                type="button"
                class="min-h-11 w-full rounded px-3 text-start hover:bg-slate-50"
                @click="
                    value = supplier.id;
                    query = supplier.legal_name;
                    results = [];
                "
            >
                {{ supplier.legal_name }}
            </button>
        </div>
    </div>
</template>
