<script setup lang="ts">
import { computed, ref } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";

defineProps<{ title?: string; subtitle?: string }>();
const page = usePage<any>();
const { t, dir } = useI18n();
const collapsed = ref(false);
const tenant = computed(() => page.props.tenant ?? {});
const nav = computed(() =>
    [
        { label: t("dashboard"), href: route("dashboard"), icon: "⌂" },
        ...(page.props.auth?.permissions?.includes("view_finance")
            ? [
                  {
                      label: t("finance"),
                      href: route("finance.index"),
                      icon: "₣",
                      residence: true,
                  },
              ]
            : []),
        { label: t("residences"), href: route("residences.index"), icon: "◇" },
        {
            label: t("structure"),
            href: route("structure.index"),
            icon: "▦",
            residence: true,
        },
        { label: t("contacts"), href: route("contacts.index"), icon: "◎" },
        {
            label: t("allocations"),
            href: route("allocations.index"),
            icon: "∑",
            residence: true,
        },
        {
            label: t("imports"),
            href: route("imports.index"),
            icon: "⇧",
            residence: true,
        },
        { label: t("activity"), href: route("activity.index"), icon: "◷" },
        { label: t("team"), href: route("team.index"), icon: "♙" },
    ].filter((item) => !item.residence || tenant.value.residence),
);
const active = (href: string) =>
    page.url === new URL(href, window.location.origin).pathname ||
    page.url.startsWith(new URL(href, window.location.origin).pathname + "/");
const mobileNav = computed<any[]>(() => {
    if (page.url.startsWith("/finance")) {
        const permissions = page.props.auth?.permissions ?? [];
        return [
            {
                label: t("financeOverview"),
                href: route("finance.index"),
                icon: "⌂",
            },
            permissions.includes("create_payments")
                ? {
                      label: t("recordPayment"),
                      href: route("payments.create"),
                      icon: "+",
                  }
                : null,
            permissions.includes("view_outstanding")
                ? {
                      label: t("outstanding"),
                      href: route("finance.outstanding"),
                      icon: "!",
                  }
                : null,
            {
                label: t("fundCalls"),
                href: route("fund-calls.index"),
                icon: "≡",
            },
            permissions.includes("view_statements")
                ? {
                      label: t("more"),
                      href: route("finance.statements"),
                      icon: "•••",
                  }
                : null,
        ].filter(Boolean);
    }
    return [
        nav.value[0],
        nav.value.find((x) => x.label === t("finance")) ||
            nav.value.find((x) => x.label === t("structure")) ||
            nav.value[1],
        nav.value.find((x) => x.label === t("contacts")),
        nav.value.find((x) => x.label === t("activity")),
        nav.value.find((x) => x.label === t("team")),
    ].filter(Boolean);
});
const switchOrganization = (event: Event) =>
    router.put(
        route(
            "context.organization",
            (event.target as HTMLSelectElement).value,
        ),
    );
const switchResidence = (event: Event) =>
    router.put(
        route("context.residence", (event.target as HTMLSelectElement).value),
    );
</script>

<template>
    <div :dir="dir" class="min-h-screen bg-stone-50 text-slate-800">
        <Head :title="title" />
        <aside
            :class="collapsed ? 'w-[76px]' : 'w-64'"
            class="fixed inset-y-0 z-30 hidden border-e border-slate-200 bg-slate-950 text-white transition-all lg:flex lg:flex-col"
        >
            <div
                class="flex h-20 items-center gap-3 border-b border-white/10 px-5"
            >
                <div
                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-teal-400 font-black text-slate-950"
                >
                    ES
                </div>
                <div v-if="!collapsed">
                    <p class="font-bold tracking-tight">{{ t("app") }}</p>
                    <p class="text-xs text-slate-400">Gestion de copropriété</p>
                </div>
            </div>
            <nav class="flex-1 space-y-1 p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    :class="
                        active(item.href)
                            ? 'bg-white text-slate-950'
                            : 'text-slate-300 hover:bg-white/10 hover:text-white'
                    "
                    class="flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition"
                >
                    <span class="w-6 text-center text-lg" aria-hidden="true">{{
                        item.icon
                    }}</span
                    ><span v-if="!collapsed">{{ item.label }}</span>
                </Link>
            </nav>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="mx-3 flex min-h-11 items-center justify-center rounded-xl border border-white/10 px-3 text-sm text-slate-300 hover:bg-white/10"
            >
                {{ t("logout") }}
            </Link>
            <button
                class="m-3 min-h-11 rounded-xl border border-white/10 text-slate-300"
                @click="collapsed = !collapsed"
                :aria-label="t('menu')"
            >
                {{ collapsed ? "›" : "‹" }}
            </button>
        </aside>

        <div
            :class="collapsed ? 'lg:ps-[76px]' : 'lg:ps-64'"
            class="transition-all"
        >
            <header
                class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur"
            >
                <div
                    class="flex min-h-16 items-center gap-3 px-4 sm:px-6 lg:px-8"
                >
                    <div
                        class="grid size-9 place-items-center rounded-lg bg-slate-950 text-xs font-black text-white lg:hidden"
                    >
                        ES
                    </div>
                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <select
                            v-if="tenant.organizations?.length"
                            :value="tenant.organization?.id"
                            class="selector"
                            :aria-label="t('organization')"
                            @change="switchOrganization"
                        >
                            <option
                                v-for="org in tenant.organizations"
                                :key="org.id"
                                :value="org.id"
                            >
                                {{ org.name }}
                            </option>
                        </select>
                        <span class="text-slate-300">/</span>
                        <img
                            v-if="tenant.residence?.logo_url"
                            :src="tenant.residence.logo_url"
                            class="size-8 rounded-lg object-cover"
                            alt=""
                        />
                        <span
                            v-else-if="tenant.residence"
                            class="grid size-8 place-items-center rounded-lg bg-teal-100 text-xs font-black text-teal-800"
                            >{{ tenant.residence.initials }}</span
                        >
                        <select
                            v-if="tenant.residences?.length"
                            :value="tenant.residence?.id"
                            class="selector"
                            :aria-label="t('residence')"
                            @change="switchResidence"
                        >
                            <option value="">{{ t("residence") }}</option>
                            <option
                                v-for="res in tenant.residences"
                                :key="res.id"
                                :value="res.id"
                            >
                                {{ res.name }}
                            </option>
                        </select>
                    </div>
                    <Link
                        :href="route('profile.edit')"
                        class="grid size-11 place-items-center rounded-full bg-teal-50 font-semibold text-teal-800"
                        >{{
                            page.props.auth.user.name.slice(0, 2).toUpperCase()
                        }}</Link
                    >
                </div>
            </header>
            <main
                class="mx-auto max-w-[1500px] px-4 pb-28 pt-6 sm:px-6 lg:px-8 lg:pb-10"
            >
                <div
                    v-if="page.props.flash?.success"
                    class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
                    role="status"
                >
                    ✓ {{ page.props.flash.success }}
                </div>
                <div
                    class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p
                            class="mb-1 text-xs font-semibold uppercase tracking-[.15em] text-teal-700"
                        >
                            {{
                                tenant.residence?.name ||
                                tenant.organization?.name
                            }}
                        </p>
                        <h1
                            class="text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl"
                        >
                            {{ title }}
                        </h1>
                        <p v-if="subtitle" class="mt-1 text-sm text-slate-500">
                            {{ subtitle }}
                        </p>
                    </div>
                    <slot name="actions" />
                </div>
                <slot />
            </main>
        </div>

        <nav
            class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <Link
                v-for="item in mobileNav"
                :key="item!.href"
                :href="item!.href"
                :class="active(item!.href) ? 'text-teal-700' : 'text-slate-500'"
                class="flex min-h-16 flex-col items-center justify-center gap-0.5 text-[10px] font-semibold"
                ><span class="text-lg">{{ item!.icon }}</span
                >{{ item!.label }}</Link
            >
        </nav>
    </div>
</template>
