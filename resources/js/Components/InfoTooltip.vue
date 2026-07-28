<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "@/i18n";
import { glossary, type GlossaryTerm } from "@/Support/glossary";

const props = defineProps<{ term: GlossaryTerm }>();
const { locale } = useI18n();
const open = ref(false);
const language = computed<"fr" | "ar">(() =>
    locale.value === "ar" ? "ar" : "fr",
);
const entry = computed(() => glossary[props.term]);
const label = computed(() => entry.value.label[language.value]);
const definition = computed(() => entry.value.definition[language.value]);
</script>

<template>
    <span
        class="group relative inline-flex align-middle"
        @keydown.esc="open = false"
    >
        <button
            type="button"
            class="ms-1 inline-grid size-5 place-items-center rounded-full border border-teal-300 bg-teal-50 text-[11px] font-black text-teal-800 hover:bg-teal-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
            :aria-label="`${label} : ${definition}`"
            :aria-expanded="open"
            @click="open = !open"
            @blur="open = false"
        >
            ?
        </button>
        <span
            role="tooltip"
            class="absolute bottom-full start-0 z-30 mb-2 w-72 max-w-[80vw] rounded-xl bg-slate-950 px-3 py-2 text-start text-xs font-normal leading-5 text-white shadow-xl group-hover:block group-focus-within:block"
            :class="open ? 'block' : 'hidden'"
        >
            <strong class="block">{{ label }}</strong>
            {{ definition }}
        </span>
    </span>
</template>
