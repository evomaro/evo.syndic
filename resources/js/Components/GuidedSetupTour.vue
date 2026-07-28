<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import { Link, usePage } from "@inertiajs/vue3";

const props = defineProps<{
    organizationId: number | string;
    userId: number | string;
    organizationCreatedAt?: string | null;
}>();
const page = usePage<any>();
const isAr = computed(() => page.props.locale === "ar");
const storageKey = computed(
    () => `evosyndic.setup-tour.${props.userId}.${props.organizationId}`,
);
const steps = computed(() => [
    {
        target: '[data-tour="profile"]',
        title: isAr.value ? "اضبط ملفك الشخصي" : "Réglez votre profil",
        body: isAr.value
            ? "تحققوا من الاسم واللغة وكلمة المرور قبل بدء الإعداد."
            : "Vérifiez votre nom, votre langue et votre mot de passe avant de commencer.",
        href: route("profile.edit"),
    },
    {
        target: '[data-tour="residences"]',
        title: isAr.value ? "أنشئوا الإقامة" : "Créez la résidence",
        body: isAr.value
            ? "الإقامة هي نطاق العمل الذي يجمع المباني والوحدات والسكان."
            : "La résidence est le périmètre qui regroupe bâtiments, lots et résidents.",
        href: route("residences.create"),
    },
    {
        target: '[data-tour="structure"]',
        title: isAr.value
            ? "أضيفوا المباني والوحدات"
            : "Ajoutez bâtiments et lots",
        body: isAr.value
            ? "صفوا الممتلكات المدارة قبل ربط الملاك والسكان."
            : "Décrivez les biens gérés avant d’y rattacher propriétaires et occupants.",
        href: route("structure.index"),
    },
    {
        target: '[data-tour="contacts"]',
        title: isAr.value
            ? "أضيفوا الملاك والسكان"
            : "Ajoutez propriétaires et résidents",
        body: isAr.value
            ? "أنشئوا جهة الاتصال ثم اربطوها بالوحدة المناسبة."
            : "Créez le contact, puis rattachez-le au lot correspondant.",
        href: route("contacts.index"),
    },
]);
const active = ref(false);
const stepIndex = ref(0);
const spotlight = ref<DOMRect | null>(null);
const current = computed(() => steps.value[stepIndex.value]);

const readState = () => {
    try {
        return JSON.parse(
            window.localStorage.getItem(storageKey.value) || "{}",
        );
    } catch {
        return {};
    }
};
const saveState = (status: "active" | "paused" | "done") =>
    window.localStorage.setItem(
        storageKey.value,
        JSON.stringify({ status, step: stepIndex.value }),
    );
const locateTarget = async () => {
    await nextTick();
    const element = document.querySelector(current.value.target);
    const rect = element?.getBoundingClientRect();
    spotlight.value =
        rect && rect.width && rect.height ? rect : (null as DOMRect | null);
};
const start = (reset = false) => {
    const state = readState();
    stepIndex.value = reset ? 0 : Math.min(Number(state.step || 0), 3);
    active.value = true;
    saveState("active");
    locateTarget();
};
const dismiss = () => {
    active.value = false;
    spotlight.value = null;
    saveState("paused");
};
const finish = () => {
    active.value = false;
    spotlight.value = null;
    saveState("done");
};
const previous = () => {
    stepIndex.value = Math.max(0, stepIndex.value - 1);
};
const next = () => {
    if (stepIndex.value >= steps.value.length - 1) {
        finish();
        return;
    }
    stepIndex.value += 1;
};
const restartListener = () => {
    const state = readState();
    start(state.status === "done");
};
const onResize = () => active.value && locateTarget();

watch(stepIndex, () => {
    if (active.value) {
        saveState("active");
        locateTarget();
    }
});
watch(
    () => page.url,
    () => active.value && locateTarget(),
);
onMounted(() => {
    window.addEventListener("evosyndic:tour:start", restartListener);
    window.addEventListener("resize", onResize);
    window.addEventListener("scroll", onResize, true);
    const state = readState();
    const createdAt = props.organizationCreatedAt
        ? new Date(props.organizationCreatedAt).getTime()
        : 0;
    const recentOrganization =
        createdAt > 0 && Date.now() - createdAt < 7 * 24 * 60 * 60 * 1000;
    if (state.status === "active") start();
    else if (!state.status && recentOrganization) start();
});
onBeforeUnmount(() => {
    window.removeEventListener("evosyndic:tour:start", restartListener);
    window.removeEventListener("resize", onResize);
    window.removeEventListener("scroll", onResize, true);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="active" class="fixed inset-0 z-[90] pointer-events-none">
            <div
                v-if="spotlight"
                class="fixed rounded-2xl ring-4 ring-teal-300 transition-all"
                :style="{
                    top: `${spotlight.top - 6}px`,
                    left: `${spotlight.left - 6}px`,
                    width: `${spotlight.width + 12}px`,
                    height: `${spotlight.height + 12}px`,
                    boxShadow: '0 0 0 9999px rgb(2 6 23 / 0.72)',
                }"
                aria-hidden="true"
            ></div>
            <div
                v-else
                class="fixed inset-0 bg-slate-950/70"
                aria-hidden="true"
            ></div>
            <section
                role="dialog"
                aria-modal="true"
                :aria-label="current.title"
                class="pointer-events-auto fixed inset-x-4 bottom-6 mx-auto max-w-lg rounded-3xl bg-white p-5 text-slate-900 shadow-2xl sm:end-8 sm:start-auto sm:mx-0 sm:p-6"
            >
                <div class="flex items-start justify-between gap-4">
                    <p
                        class="text-xs font-bold uppercase tracking-wider text-teal-700"
                    >
                        {{ stepIndex + 1 }}/{{ steps.length }}
                    </p>
                    <button
                        type="button"
                        class="grid size-9 place-items-center rounded-full text-xl text-slate-500 hover:bg-slate-100"
                        :aria-label="
                            isAr ? 'إيقاف الجولة' : 'Mettre la visite en pause'
                        "
                        @click="dismiss"
                    >
                        ×
                    </button>
                </div>
                <h2 class="mt-1 text-xl font-bold">{{ current.title }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ current.body }}
                </p>
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <button
                        v-if="stepIndex"
                        type="button"
                        class="btn-secondary"
                        @click="previous"
                    >
                        {{ isAr ? "السابق" : "Précédent" }}
                    </button>
                    <Link :href="current.href" class="btn-secondary">
                        {{ isAr ? "فتح الصفحة" : "Ouvrir la page" }}
                    </Link>
                    <button
                        type="button"
                        class="btn-primary ms-auto"
                        @click="next"
                    >
                        {{
                            stepIndex === steps.length - 1
                                ? isAr
                                    ? "إنهاء"
                                    : "Terminer"
                                : isAr
                                  ? "التالي"
                                  : "Suivant"
                        }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
