<script setup lang="ts">
import axios from "axios";
import { ref } from "vue";
import { useI18n } from "@/i18n";

const model = defineModel<number | null>();
const emit = defineEmits<{ select: [contact: any] }>();
const { t } = useI18n();
const search = ref("");
const results = ref<any[]>([]);
const loading = ref(false);
const find = async () => {
    loading.value = true;
    const response = await axios.get(route("search.contacts"), {
        params: { search: search.value },
    });
    results.value = response.data;
    loading.value = false;
};
const choose = (contact: any) => {
    model.value = contact.id;
    search.value = contact.display_name;
    results.value = [];
    emit("select", contact);
};
</script>
<template>
    <div class="relative">
        <div class="flex gap-2">
            <input
                v-model="search"
                class="min-w-0 flex-1"
                :placeholder="t('searchContact')"
                @keyup.enter.prevent="find"
            /><button type="button" class="btn-secondary" @click="find">
                {{ t("search") }}
            </button>
        </div>
        <div
            v-if="results.length"
            class="absolute inset-x-0 top-full z-20 mt-1 max-h-56 overflow-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg"
        >
            <button
                v-for="contact in results"
                :key="contact.id"
                type="button"
                class="flex min-h-11 w-full items-center justify-between rounded-lg px-3 text-start text-sm hover:bg-slate-50"
                @click="choose(contact)"
            >
                <span class="font-semibold">{{ contact.display_name }}</span
                ><span class="text-xs text-slate-500">{{
                    contact.primary_phone
                }}</span>
            </button>
        </div>
        <p v-if="loading" class="mt-1 text-xs text-slate-500">
            {{ t("loading") }}
        </p>
    </div>
</template>
