<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import { useI18n } from "@/i18n";

const props = withDefaults(
    defineProps<{
        modelValue: string;
        name?: string;
        id?: string;
        ariaLabel?: string;
    }>(),
    { name: undefined, id: undefined, ariaLabel: undefined },
);
const emit = defineEmits<{
    "update:modelValue": [value: string];
    change: [event: Event];
}>();
const { locale, dir } = useI18n();
const root = ref<HTMLElement | null>(null);
const hiddenInput = ref<HTMLInputElement | null>(null);
const open = ref(false);
const internalValue = ref(props.modelValue);
const currentDate = new Date();
const currentValue = `${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, "0")}`;
const visibleYear = ref(
    Number(internalValue.value?.slice(0, 4)) || currentDate.getFullYear(),
);

const dateLocale = computed(() => (locale.value === "ar" ? "ar-MA" : "fr-MA"));
const selectedLabel = computed(() => {
    if (!/^\d{4}-\d{2}$/.test(internalValue.value)) {
        return locale.value === "ar" ? "اختر شهراً" : "Choisir un mois";
    }

    const [year, month] = internalValue.value.split("-").map(Number);
    return new Intl.DateTimeFormat(dateLocale.value, {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
    }).format(new Date(Date.UTC(year, month - 1, 1)));
});
const months = computed(() => {
    const formatter = new Intl.DateTimeFormat(dateLocale.value, {
        month: "short",
        timeZone: "UTC",
    });

    return Array.from({ length: 12 }, (_, index) => ({
        number: index + 1,
        value: `${visibleYear.value}-${String(index + 1).padStart(2, "0")}`,
        label: formatter.format(new Date(Date.UTC(2024, index, 1))),
    }));
});

watch(
    () => props.modelValue,
    (value) => {
        internalValue.value = value;
        if (/^\d{4}-\d{2}$/.test(value)) {
            visibleYear.value = Number(value.slice(0, 4));
        }
    },
);

const notifyChange = async () => {
    await nextTick();
    const event = new Event("change", { bubbles: true });
    hiddenInput.value?.dispatchEvent(event);
    emit("change", event);
};
const select = (value: string) => {
    internalValue.value = value;
    emit("update:modelValue", value);
    open.value = false;
    void notifyChange();
};
const clear = () => select("");
const selectCurrent = () => select(currentValue);
const closeOnOutsideClick = (event: PointerEvent) => {
    if (open.value && !root.value?.contains(event.target as Node)) {
        open.value = false;
    }
};
const closeOnEscape = (event: KeyboardEvent) => {
    if (event.key === "Escape") {
        open.value = false;
    }
};

onMounted(() => {
    document.addEventListener("pointerdown", closeOnOutsideClick);
    document.addEventListener("keydown", closeOnEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", closeOnOutsideClick);
    document.removeEventListener("keydown", closeOnEscape);
});
</script>

<template>
    <div ref="root" class="relative inline-block" :dir="dir">
        <input
            ref="hiddenInput"
            type="hidden"
            :name="name"
            :value="internalValue"
        />
        <button
            :id="id"
            type="button"
            class="flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 text-start text-sm shadow-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/20"
            :aria-label="ariaLabel"
            aria-haspopup="dialog"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="capitalize">{{ selectedLabel }}</span>
            <svg
                class="size-4 shrink-0 text-slate-500"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>

        <div
            v-if="open"
            role="dialog"
            :aria-label="locale === 'ar' ? 'اختيار الشهر' : 'Choisir un mois'"
            class="absolute top-full z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white p-4 shadow-xl"
            :class="dir === 'rtl' ? 'left-0' : 'right-0'"
        >
            <div class="mb-4 flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="flex size-10 items-center justify-center rounded-xl border border-slate-200 text-lg hover:bg-slate-50"
                    :aria-label="
                        locale === 'ar' ? 'السنة السابقة' : 'Année précédente'
                    "
                    @click="visibleYear--"
                >
                    {{ dir === "rtl" ? "→" : "←" }}
                </button>
                <strong class="text-lg tabular-nums">{{ visibleYear }}</strong>
                <button
                    type="button"
                    class="flex size-10 items-center justify-center rounded-xl border border-slate-200 text-lg hover:bg-slate-50"
                    :aria-label="
                        locale === 'ar' ? 'السنة التالية' : 'Année suivante'
                    "
                    @click="visibleYear++"
                >
                    {{ dir === "rtl" ? "←" : "→" }}
                </button>
            </div>

            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="month in months"
                    :key="month.value"
                    type="button"
                    class="min-h-11 rounded-xl px-2 text-sm font-semibold capitalize transition"
                    :class="
                        month.value === internalValue
                            ? 'bg-teal-700 text-white'
                            : month.value === currentValue
                              ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-200'
                              : 'text-slate-700 hover:bg-slate-100'
                    "
                    @click="select(month.value)"
                >
                    {{ month.label }}
                </button>
            </div>

            <div
                class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-sm"
            >
                <button
                    type="button"
                    class="min-h-10 rounded-lg px-2 font-semibold text-slate-600 hover:bg-slate-100"
                    @click="clear"
                >
                    {{ locale === "ar" ? "مسح" : "Effacer" }}
                </button>
                <button
                    type="button"
                    class="min-h-10 rounded-lg px-2 font-semibold text-teal-700 hover:bg-teal-50"
                    @click="selectCurrent"
                >
                    {{ locale === "ar" ? "الشهر الحالي" : "Mois actuel" }}
                </button>
            </div>
        </div>
    </div>
</template>
