<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";

type Section = { id: string; heading: string; body: string; items: string[] };
type Article = {
    id: string;
    category: string;
    order: number;
    title: string;
    summary: string;
    sections: Section[];
    keywords: string[];
    related: string[];
    reading_minutes: number;
    updated_at: string;
};
type ChecklistStep = {
    id: string;
    article_id: string;
    title: string;
    purpose: string;
    who: string;
    prerequisites: string;
    path: string;
    fields: string;
    actions: string[];
    result: string;
    mistakes: string;
    unlocks: string;
    automatic: boolean;
    complete: boolean;
};

const props = defineProps<{
    categories: { id: string; label: string; order: number }[];
    articles: Article[];
    selectedArticleId?: string | null;
    checklist: ChecklistStep[];
    updatedAt: string;
}>();
const { locale } = useI18n();
const ui = computed(() =>
    locale.value === "ar"
        ? {
              title: "مركز المساعدة",
              intro: "دليل عملي مطابق لوحدات EvoSyndic وصلاحياتك.",
              search: "ابحث عن إجراء أو وحدة أو مصطلح…",
              category: "اختر فئة",
              results: "نتائج البحث",
              noResults: "لم نعثر على نتيجة. جرّب كلمة مرادفة.",
              minutes: "دقائق قراءة",
              updated: "آخر تحديث",
              contents: "في هذا المقال",
              related: "مقالات مرتبطة",
              previous: "السابق",
              next: "التالي",
              copy: "نسخ الرابط",
              copied: "تم النسخ",
              print: "طباعة",
              checklist: "قائمة الاستعمال الأول",
              progress: "منجزة",
              automatic: "تحقق تلقائي",
              mark: "تعليم كمقروء",
              reset: "إعادة ضبط العلامات اليدوية",
              purpose: "الغرض",
              who: "من يمكنه التنفيذ",
              prerequisites: "المتطلبات",
              path: "مسار الوصول",
              fields: "البيانات المهمة",
              actions: "الخطوات",
              result: "النتيجة المتوقعة",
              mistakes: "أخطاء شائعة",
              unlocks: "ما يصبح متاحا",
              tour: "إعادة الجولة الإرشادية",
          }
        : {
              title: "Centre d’aide",
              intro: "Le guide pratique aligné sur les modules EvoSyndic et vos permissions.",
              search: "Rechercher une procédure, un module ou un terme…",
              category: "Choisir une catégorie",
              results: "Résultats de recherche",
              noResults: "Aucun résultat. Essayez un synonyme.",
              minutes: "min de lecture",
              updated: "Mis à jour",
              contents: "Dans cet article",
              related: "Articles associés",
              previous: "Précédent",
              next: "Suivant",
              copy: "Copier le lien",
              copied: "Lien copié",
              print: "Imprimer",
              checklist: "Liste de première utilisation",
              progress: "terminées",
              automatic: "Vérification automatique",
              mark: "Marquer comme lu",
              reset: "Réinitialiser les coches manuelles",
              purpose: "Objectif",
              who: "Qui peut le faire",
              prerequisites: "Prérequis",
              path: "Chemin d’accès",
              fields: "Données importantes",
              actions: "Étapes",
              result: "Résultat attendu",
              mistakes: "Erreurs fréquentes",
              unlocks: "Disponible ensuite",
              tour: "Revoir la visite guidée",
          },
);

const query = ref("");
const selectedCategory = ref("");
const copied = ref("");
const normalize = (value: string) =>
    value
        .normalize("NFD")
        .replace(/[\u0300-\u036f\u064B-\u065F\u0670]/g, "")
        .replace(/[أإآٱ]/g, "ا")
        .replace(/ى/g, "ي")
        .replace(/ؤ/g, "و")
        .replace(/ئ/g, "ي")
        .replace(/ة/g, "ه")
        .toLocaleLowerCase()
        .trim();
const searchResults = computed(() => {
    const needle = normalize(query.value);
    if (!needle) return [];
    return props.articles
        .filter((article) => {
            const haystack = [
                article.title,
                article.summary,
                ...article.keywords,
                ...article.sections.flatMap((section) => [
                    section.heading,
                    section.body,
                    ...section.items,
                ]),
            ].join(" ");
            return normalize(haystack).includes(needle);
        })
        .map((article) => {
            const matched =
                article.sections.find((section) =>
                    normalize(
                        [section.heading, section.body, ...section.items].join(
                            " ",
                        ),
                    ).includes(needle),
                )?.body || article.summary;
            return {
                ...article,
                excerpt:
                    matched.length > 170
                        ? `${matched.slice(0, 170)}…`
                        : matched,
                categoryLabel:
                    props.categories.find(
                        (category) => category.id === article.category,
                    )?.label || "",
            };
        });
});
const categoryArticles = (category: string) =>
    props.articles
        .filter((article) => article.category === category)
        .sort((a, b) => a.order - b.order);
const selected = computed(
    () =>
        props.articles.find(
            (article) => article.id === props.selectedArticleId,
        ) || props.articles[0],
);
const position = computed(() =>
    props.articles.findIndex((article) => article.id === selected.value?.id),
);
const previous = computed(() =>
    position.value > 0 ? props.articles[position.value - 1] : null,
);
const next = computed(() =>
    position.value >= 0 && position.value < props.articles.length - 1
        ? props.articles[position.value + 1]
        : null,
);
const completedCount = computed(
    () => props.checklist.filter((step) => step.complete).length,
);
const completionPercent = computed(() =>
    props.checklist.length
        ? Math.round((completedCount.value / props.checklist.length) * 100)
        : 0,
);
const relatedArticles = computed(
    () =>
        (selected.value?.related || [])
            .map((id) => props.articles.find((article) => article.id === id))
            .filter(Boolean) as Article[],
);
const openArticle = (id: string, section?: string) => {
    router.get(
        route("help.index", id),
        {},
        {
            preserveState: true,
            onSuccess: () =>
                nextTick(() => {
                    if (section)
                        document.getElementById(section)?.scrollIntoView();
                    else window.scrollTo({ top: 0, behavior: "smooth" });
                }),
        },
    );
};
const copySection = async (section: string) => {
    const url = `${window.location.origin}${route("help.index", selected.value.id)}#${section}`;
    await navigator.clipboard.writeText(url);
    copied.value = section;
    window.setTimeout(() => (copied.value = ""), 1800);
};
const toggleStep = (step: ChecklistStep) => {
    if (step.automatic) return;
    router.put(
        route("help.progress", step.id),
        { complete: !step.complete },
        { preserveScroll: true },
    );
};
const resetProgress = () =>
    router.delete(route("help.progress.reset"), { preserveScroll: true });
const printPage = () => window.print();
const restartTour = () =>
    window.dispatchEvent(new CustomEvent("evosyndic:tour:start"));

watch(
    () => props.selectedArticleId,
    () => (query.value = ""),
);
</script>

<template>
    <AuthenticatedLayout :title="ui.title" :subtitle="ui.intro">
        <template #actions>
            <button
                type="button"
                class="print:hidden inline-flex min-h-10 items-center rounded-xl border border-teal-200 bg-teal-50 px-4 text-sm font-semibold text-teal-800 hover:bg-teal-100"
                @click="restartTour"
            >
                {{ ui.tour }}
            </button>
            <button
                type="button"
                class="print:hidden inline-flex min-h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold hover:bg-slate-50"
                @click="printPage"
            >
                {{ ui.print }}
            </button>
        </template>

        <section
            class="print:hidden mb-6 rounded-3xl bg-slate-950 p-5 text-white shadow-sm sm:p-8"
        >
            <label for="help-search" class="mb-3 block text-sm font-semibold">
                {{ ui.search }}
            </label>
            <div class="relative">
                <span
                    class="pointer-events-none absolute inset-y-0 start-4 grid place-items-center text-xl text-slate-400"
                    aria-hidden="true"
                    >⌕</span
                >
                <input
                    id="help-search"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    class="min-h-14 w-full rounded-2xl border-0 bg-white ps-12 pe-4 text-base text-slate-950 shadow-lg outline-none ring-teal-400 placeholder:text-slate-400 focus:ring-4"
                    :placeholder="ui.search"
                />
            </div>
        </section>

        <section
            v-if="query"
            class="mb-8 rounded-2xl border border-slate-200 bg-white p-5"
        >
            <h2 class="text-lg font-bold">
                {{ ui.results }} ({{ searchResults.length }})
            </h2>
            <div
                v-if="searchResults.length"
                class="mt-4 grid gap-3 md:grid-cols-2"
            >
                <button
                    v-for="result in searchResults"
                    :key="result.id"
                    type="button"
                    class="rounded-xl border border-slate-200 p-4 text-start hover:border-teal-400 hover:bg-teal-50"
                    @click="openArticle(result.id)"
                >
                    <span
                        class="text-xs font-bold uppercase tracking-wide text-teal-700"
                        >{{ result.categoryLabel }}</span
                    >
                    <strong class="mt-1 block text-slate-950">{{
                        result.title
                    }}</strong>
                    <span class="mt-1 block text-sm leading-6 text-slate-600">{{
                        result.excerpt
                    }}</span>
                </button>
            </div>
            <p v-else class="mt-4 text-sm text-slate-500">{{ ui.noResults }}</p>
        </section>

        <div class="print:hidden mb-5 lg:hidden">
            <select
                v-model="selectedCategory"
                class="min-h-12 w-full rounded-xl border-slate-300 bg-white"
                :aria-label="ui.category"
            >
                <option value="">{{ ui.category }}</option>
                <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="category.id"
                >
                    {{ category.label }}
                </option>
            </select>
            <div
                v-if="selectedCategory"
                class="mt-2 grid rounded-xl border border-slate-200 bg-white p-2"
            >
                <Link
                    v-for="article in categoryArticles(selectedCategory)"
                    :key="article.id"
                    :href="route('help.index', article.id)"
                    class="rounded-lg px-3 py-2 text-sm hover:bg-teal-50"
                >
                    {{ article.title }}
                </Link>
            </div>
        </div>

        <div
            class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] xl:grid-cols-[16rem_minmax(0,1fr)_14rem]"
        >
            <nav
                class="print:hidden hidden self-start lg:block"
                :aria-label="ui.category"
            >
                <div
                    class="sticky top-24 max-h-[calc(100vh-7rem)] space-y-5 overflow-y-auto pe-2"
                >
                    <section v-for="category in categories" :key="category.id">
                        <h2
                            class="mb-2 px-2 text-xs font-bold uppercase tracking-wider text-slate-500"
                        >
                            {{ category.label }}
                        </h2>
                        <div class="grid gap-1">
                            <Link
                                v-for="article in categoryArticles(category.id)"
                                :key="article.id"
                                :href="route('help.index', article.id)"
                                :class="
                                    selected?.id === article.id
                                        ? 'bg-teal-100 text-teal-900'
                                        : 'text-slate-700 hover:bg-white'
                                "
                                class="rounded-xl px-3 py-2 text-sm font-medium"
                            >
                                {{ article.title }}
                            </Link>
                        </div>
                    </section>
                </div>
            </nav>

            <article
                v-if="selected"
                class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8"
            >
                <nav
                    class="print:hidden mb-5 flex flex-wrap items-center gap-2 text-xs text-slate-500"
                    aria-label="Breadcrumb"
                >
                    <Link
                        :href="route('help.index')"
                        class="hover:text-teal-700"
                        >{{ ui.title }}</Link
                    >
                    <span aria-hidden="true">›</span>
                    <span>{{
                        categories.find(
                            (category) => category.id === selected.category,
                        )?.label
                    }}</span>
                    <span aria-hidden="true">›</span>
                    <span class="text-slate-800">{{ selected.title }}</span>
                </nav>
                <h2 class="text-3xl font-black tracking-tight text-slate-950">
                    {{ selected.title }}
                </h2>
                <p class="mt-3 text-lg leading-8 text-slate-600">
                    {{ selected.summary }}
                </p>
                <div
                    class="mt-4 flex flex-wrap gap-3 text-xs font-medium text-slate-500"
                >
                    <span>{{ selected.reading_minutes }} {{ ui.minutes }}</span>
                    <span aria-hidden="true">•</span>
                    <time :datetime="selected.updated_at"
                        >{{ ui.updated }} {{ selected.updated_at }}</time
                    >
                </div>

                <section
                    v-if="selected.id === 'first-use-checklist'"
                    class="mt-8 rounded-2xl border border-teal-200 bg-teal-50/50 p-4 sm:p-6"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <h3 class="text-xl font-bold text-slate-950">
                                {{ ui.checklist }}
                            </h3>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ completedCount }}/{{ checklist.length }}
                                {{ ui.progress }}
                            </p>
                        </div>
                        <strong class="text-2xl text-teal-800"
                            >{{ completionPercent }}%</strong
                        >
                    </div>
                    <div
                        class="mt-4 h-2 overflow-hidden rounded-full bg-teal-100"
                        role="progressbar"
                        :aria-valuenow="completionPercent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-teal-600 transition-all"
                            :style="{ width: `${completionPercent}%` }"
                        ></div>
                    </div>
                    <ol class="mt-6 grid gap-4">
                        <li
                            v-for="(step, index) in checklist"
                            :key="step.id"
                            class="rounded-xl border border-slate-200 bg-white p-4"
                        >
                            <div class="flex items-start gap-3">
                                <button
                                    type="button"
                                    :disabled="step.automatic"
                                    :aria-pressed="step.complete"
                                    class="grid size-8 shrink-0 place-items-center rounded-full border text-sm font-bold disabled:cursor-default"
                                    :class="
                                        step.complete
                                            ? 'border-emerald-500 bg-emerald-500 text-white'
                                            : 'border-slate-300 bg-white text-slate-500'
                                    "
                                    @click="toggleStep(step)"
                                >
                                    {{ step.complete ? "✓" : index + 1 }}
                                </button>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h4 class="font-bold text-slate-950">
                                            {{ step.title }}
                                        </h4>
                                        <span
                                            v-if="step.automatic"
                                            class="rounded-full bg-slate-100 px-2 py-1 text-[11px] text-slate-600"
                                            >{{ ui.automatic }}</span
                                        >
                                    </div>
                                    <dl
                                        class="mt-3 grid gap-3 text-sm sm:grid-cols-2"
                                    >
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.purpose }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.purpose }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.who }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.who }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.prerequisites }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.prerequisites }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.path }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.path }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.fields }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.fields }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt
                                                class="font-bold text-slate-800"
                                            >
                                                {{ ui.result }}
                                            </dt>
                                            <dd class="mt-1 text-slate-600">
                                                {{ step.result }}
                                            </dd>
                                        </div>
                                    </dl>
                                    <div class="mt-3">
                                        <strong
                                            class="text-sm text-slate-800"
                                            >{{ ui.actions }}</strong
                                        >
                                        <ol
                                            class="mt-1 list-decimal space-y-1 ps-5 text-sm text-slate-600"
                                        >
                                            <li
                                                v-for="action in step.actions"
                                                :key="action"
                                            >
                                                {{ action }}
                                            </li>
                                        </ol>
                                    </div>
                                    <div
                                        class="mt-3 grid gap-2 text-sm sm:grid-cols-2"
                                    >
                                        <p
                                            class="rounded-lg bg-amber-50 p-3 text-amber-900"
                                        >
                                            <strong>{{ ui.mistakes }}:</strong>
                                            {{ step.mistakes }}
                                        </p>
                                        <p
                                            class="rounded-lg bg-emerald-50 p-3 text-emerald-900"
                                        >
                                            <strong>{{ ui.unlocks }}:</strong>
                                            {{ step.unlocks }}
                                        </p>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <Link
                                            :href="
                                                route(
                                                    'help.index',
                                                    step.article_id,
                                                )
                                            "
                                            class="text-sm font-bold text-teal-700 hover:underline"
                                            >{{
                                                articles.find(
                                                    (article) =>
                                                        article.id ===
                                                        step.article_id,
                                                )?.title
                                            }}</Link
                                        >
                                        <button
                                            v-if="!step.automatic"
                                            type="button"
                                            class="text-sm font-semibold text-slate-600 hover:text-teal-700"
                                            @click="toggleStep(step)"
                                        >
                                            {{ ui.mark }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ol>
                    <button
                        type="button"
                        class="mt-5 text-sm font-semibold text-slate-600 underline hover:text-rose-700"
                        @click="resetProgress"
                    >
                        {{ ui.reset }}
                    </button>
                </section>

                <div class="mt-9 space-y-10">
                    <section
                        v-for="section in selected.sections"
                        :id="section.id"
                        :key="section.id"
                        class="scroll-mt-24"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <h3 class="text-xl font-bold text-slate-950">
                                {{ section.heading }}
                            </h3>
                            <button
                                type="button"
                                class="print:hidden shrink-0 text-xs font-semibold text-teal-700 hover:underline"
                                @click="copySection(section.id)"
                            >
                                {{
                                    copied === section.id ? ui.copied : ui.copy
                                }}
                            </button>
                        </div>
                        <p
                            class="mt-3 whitespace-pre-line leading-7 text-slate-700"
                        >
                            {{ section.body }}
                        </p>
                        <ol
                            v-if="section.items?.length"
                            class="mt-3 list-decimal space-y-2 ps-6 leading-7 text-slate-700"
                        >
                            <li v-for="item in section.items" :key="item">
                                {{ item }}
                            </li>
                        </ol>
                    </section>
                </div>

                <section
                    v-if="relatedArticles.length"
                    class="print:hidden mt-10 border-t border-slate-200 pt-6"
                >
                    <h3 class="font-bold">{{ ui.related }}</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Link
                            v-for="article in relatedArticles"
                            :key="article.id"
                            :href="route('help.index', article.id)"
                            class="rounded-full bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-100"
                            >{{ article.title }}</Link
                        >
                    </div>
                </section>
                <nav
                    class="print:hidden mt-10 grid gap-3 border-t border-slate-200 pt-6 sm:grid-cols-2"
                >
                    <Link
                        v-if="previous"
                        :href="route('help.index', previous.id)"
                        class="rounded-xl border border-slate-200 p-4 hover:border-teal-400"
                    >
                        <span class="text-xs text-slate-500">{{
                            ui.previous
                        }}</span>
                        <strong class="mt-1 block">{{ previous.title }}</strong>
                    </Link>
                    <span v-else></span>
                    <Link
                        v-if="next"
                        :href="route('help.index', next.id)"
                        class="rounded-xl border border-slate-200 p-4 text-end hover:border-teal-400"
                    >
                        <span class="text-xs text-slate-500">{{
                            ui.next
                        }}</span>
                        <strong class="mt-1 block">{{ next.title }}</strong>
                    </Link>
                </nav>
            </article>

            <aside class="print:hidden hidden self-start xl:block">
                <div
                    class="sticky top-24 rounded-2xl border border-slate-200 bg-white p-4"
                >
                    <h2 class="text-sm font-bold">{{ ui.contents }}</h2>
                    <nav class="mt-3 grid gap-2">
                        <a
                            v-for="section in selected?.sections || []"
                            :key="section.id"
                            :href="`#${section.id}`"
                            class="text-sm leading-5 text-slate-600 hover:text-teal-700"
                            >{{ section.heading }}</a
                        >
                    </nav>
                </div>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>

<style>
@media print {
    aside,
    header,
    nav,
    button,
    [class*="print:hidden"] {
        display: none !important;
    }
    main {
        max-width: none !important;
        padding: 0 !important;
    }
    article {
        border: 0 !important;
        box-shadow: none !important;
    }
}
</style>
