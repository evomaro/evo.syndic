<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
defineProps<{
    lots: any[];
    documents: any[];
    announcements: any[];
    expenses: any[];
    expenseInvoices: any[];
    unreadNotifications: number;
}>();
const { locale } = useI18n();
const money = (c: number) =>
    new Intl.NumberFormat(locale.value === "ar" ? "ar-MA" : "fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(c / 100);
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const date = (value: string) =>
    new Intl.DateTimeFormat(locale.value === "ar" ? "ar-MA" : "fr-MA").format(
        new Date(`${value.slice(0, 10)}T00:00:00`),
    );
</script>
<template>
    <AuthenticatedLayout
        :title="text('Mon espace résident', 'فضاء المقيم')"
        :subtitle="
            text(
                'Informations de votre copropriété',
                'معلومات ملكيتكم المشتركة',
            )
        "
        ><div class="grid gap-3 sm:grid-cols-3">
            <a :href="route('notifications.index')" class="stat"
                ><small>{{
                    text("Notifications non lues", "إشعارات غير مقروءة")
                }}</small
                ><b class="mt-2 block text-2xl">{{ unreadNotifications }}</b></a
            >
            <article class="stat">
                <small>{{ text("Mes lots", "وحداتي") }}</small
                ><b class="mt-2 block text-2xl">{{ lots.length }}</b>
            </article>
            <a :href="route('owner-finance.index')" class="stat"
                ><small>{{
                    text("Mes charges et paiements", "رسومي ومدفوعاتي")
                }}</small
                ><b class="mt-2 block text-teal-700">{{
                    text("Ouvrir →", "فتح ←")
                }}</b></a
            >
        </div>
        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <section class="panel">
                <div class="panel-head">
                    <a
                        :href="route('portal.announcements')"
                        class="inline-flex min-h-11 items-center font-bold text-teal-700"
                    >
                        {{ text("Dernières annonces", "آخر الإعلانات") }} →
                    </a>
                </div>
                <article v-for="a in announcements" class="border-b p-5">
                    <b>{{ a.title }}</b>
                    <p class="mt-1 text-sm">{{ a.body }}</p>
                </article>
                <p v-if="!announcements.length" class="p-5 text-slate-500">
                    {{
                        text(
                            "Aucune annonce publiée.",
                            "لا توجد إعلانات منشورة.",
                        )
                    }}
                </p>
            </section>
            <section class="panel">
                <div class="panel-head">
                    <a
                        :href="route('portal.documents')"
                        class="inline-flex min-h-11 items-center font-bold text-teal-700"
                    >
                        {{ text("Documents disponibles", "الوثائق المتاحة") }} →
                    </a>
                </div>
                <a
                    v-for="d in documents.filter(
                        (document) => document.published_version,
                    )"
                    :href="route('documents.download', d.published_version.id)"
                    class="flex min-h-14 items-center justify-between border-b px-5"
                    ><span>{{ d.title }}</span
                    ><span>↓</span></a
                >
                <p
                    v-if="
                        !documents.some(
                            (document) => document.published_version,
                        )
                    "
                    class="p-5 text-slate-500"
                >
                    {{
                        text("Aucun document publié.", "لا توجد وثائق منشورة.")
                    }}
                </p>
            </section>
            <section class="panel xl:col-span-2">
                <div class="panel-head">
                    <h2 class="font-bold">
                        {{
                            text("Dépenses par catégorie", "المصاريف حسب الفئة")
                        }}
                    </h2>
                    <small>{{
                        text(
                            "Aucune donnée fournisseur ou bancaire n’est affichée.",
                            "لا يتم عرض أي بيانات خاصة بالمورد أو الحساب البنكي.",
                        )
                    }}</small>
                </div>
                <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="e in expenses"
                        class="rounded-xl bg-slate-50 p-4"
                    >
                        <small>{{ e.category }}</small
                        ><b class="mt-1 block">{{ money(e.total_cents) }}</b>
                    </article>
                    <p v-if="!expenses.length" class="text-slate-500">
                        {{
                            text(
                                "Aucune dépense publiée.",
                                "لا توجد مصاريف منشورة.",
                            )
                        }}
                    </p>
                </div>
            </section>
            <section class="panel xl:col-span-2">
                <div class="panel-head">
                    <h2 class="font-bold">
                        {{
                            text(
                                "Détail des dépenses publiées",
                                "تفاصيل المصاريف المنشورة",
                            )
                        }}
                    </h2>
                    <small>{{
                        text(
                            "Seules les informations approuvées pour les résidents sont affichées.",
                            "يتم عرض المعلومات المعتمدة للمقيمين فقط.",
                        )
                    }}</small>
                </div>
                <div class="divide-y">
                    <article
                        v-for="invoice in expenseInvoices"
                        :key="invoice.id"
                        class="grid gap-2 p-5 sm:grid-cols-[1fr_auto] sm:items-center"
                    >
                        <div>
                            <b class="block">{{
                                invoice.public_description
                            }}</b>
                            <small class="text-slate-500"
                                >{{ invoice.category }} ·
                                {{ date(invoice.invoice_date) }}</small
                            >
                        </div>
                        <b>{{ money(invoice.total_cents) }}</b>
                    </article>
                    <p
                        v-if="!expenseInvoices.length"
                        class="p-5 text-slate-500"
                    >
                        {{
                            text(
                                "Aucun détail de dépense publié.",
                                "لا توجد تفاصيل مصاريف منشورة.",
                            )
                        }}
                    </p>
                </div>
            </section>
        </div></AuthenticatedLayout
    >
</template>
