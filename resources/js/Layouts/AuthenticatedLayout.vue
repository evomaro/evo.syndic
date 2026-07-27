<script setup lang="ts">
import { computed, ref } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";

defineProps<{ title?: string; subtitle?: string }>();
const page = usePage<any>();
const { t, dir } = useI18n();
const collapsed = ref(false);
const mobileSidebarOpen = ref(false);
const tenant = computed(() => page.props.tenant ?? {});
const isResident = computed(() => page.props.auth?.role === "coproprietaire");
const contextualArticle = computed(() => {
    const current = route().current();
    return current ? page.props.helpContext?.[current] : null;
});
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
        ...(page.props.auth?.permissions?.includes(
            "view_accounting_configuration",
        )
            ? [
                  {
                      label: t("accounting"),
                      href: route("accounting.index"),
                      icon: "≋",
                      residence: true,
                  },
              ]
            : []),
        ...(page.props.auth?.permissions?.includes("view_compliance_calendar")
            ? [
                  {
                      label: t("compliance"),
                      href: route("compliance.index"),
                      icon: "✓",
                      residence: true,
                  },
              ]
            : []),
        ...(page.props.auth?.permissions?.includes("view_expenses")
            ? [
                  {
                      label: t("expenses"),
                      href: route("expenses.index"),
                      icon: "⇩",
                      residence: true,
                  },
              ]
            : []),
        ...(isResident.value
            ? [
                  {
                      label: t("maintenance"),
                      href: route("portal.maintenance.index"),
                      icon: "⚒",
                      residence: true,
                  },
              ]
            : page.props.auth?.permissions?.includes(
                    "view_maintenance_requests",
                )
              ? [
                    {
                        label: t("maintenance"),
                        href: route("maintenance.dashboard"),
                        icon: "⚒",
                        residence: true,
                    },
                ]
              : []),
        ...(isResident.value
            ? [
                  {
                      label: t("governance"),
                      href: route("owner-governance.index"),
                      icon: "⚖",
                      residence: true,
                  },
              ]
            : page.props.auth?.permissions?.includes(
                    "view_governance_dashboard",
                )
              ? [
                    {
                        label: t("governance"),
                        href: route("governance.dashboard"),
                        icon: "⚖",
                        residence: true,
                    },
                ]
              : []),
        ...(isResident.value
            ? [
                  {
                      label: t("documents"),
                      href: route("portal.documents"),
                      icon: "▤",
                      residence: true,
                  },
              ]
            : page.props.auth?.permissions?.includes("view_documents")
              ? [
                    {
                        label: t("documents"),
                        href: route("documents.index"),
                        icon: "▤",
                        residence: true,
                    },
                ]
              : []),
        ...(isResident.value
            ? [
                  {
                      label: t("announcements"),
                      href: route("portal.announcements"),
                      icon: "◉",
                      residence: true,
                  },
              ]
            : page.props.auth?.permissions?.includes("view_announcements")
              ? [
                    {
                        label: t("announcements"),
                        href: route("announcements.index"),
                        icon: "◉",
                        residence: true,
                    },
                ]
              : []),
        {
            label: t("notifications"),
            href: route("notifications.index"),
            icon: "♢",
            residence: true,
        },
        {
            label: t("residentPortal"),
            href: route("portal.index"),
            icon: "⌂",
            residence: true,
        },
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
        { label: t("helpCenter"), href: route("help.index"), icon: "?" },
    ].filter((item) => !item.residence || tenant.value.residence),
);
const active = (href: string) =>
    page.url === new URL(href, window.location.origin).pathname ||
    page.url.startsWith(new URL(href, window.location.origin).pathname + "/");
const mobileNav = computed<any[]>(() => {
    if (isResident.value) {
        return [
            {
                label: t("residentPortal"),
                href: route("portal.index"),
                icon: "⌂",
            },
            {
                label: t("finance"),
                href: route("owner-finance.index"),
                icon: "₣",
            },
            {
                label: t("maintenance"),
                href: route("portal.maintenance.index"),
                icon: "⚒",
            },
            {
                label: t("governance"),
                href: route("owner-governance.index"),
                icon: "⚖",
            },
            {
                label: t("documents"),
                href: route("portal.documents"),
                icon: "▤",
            },
        ];
    }
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
    if (page.url.startsWith("/accounting")) {
        const permissions = page.props.auth?.permissions ?? [];
        const canReport = permissions.some((permission: string) =>
            [
                "view_journal_reports",
                "view_general_ledger",
                "view_account_ledgers",
                "view_trial_balance",
                "view_accounting_receivables",
                "view_accounting_payables",
                "view_budget_actual",
                "view_accounting_reconciliation",
            ].includes(permission),
        );
        return [
            {
                label: t("accounting"),
                href: route("accounting.index"),
                icon: "⌂",
            },
            {
                label: t("chartOfAccounts"),
                href: route("accounting.index") + "#accounts",
                icon: "≋",
            },
            {
                label: t("periods"),
                href: route("accounting.index") + "#periods",
                icon: "▦",
            },
            {
                label: t("journals"),
                href: route("accounting.index") + "#journals",
                icon: "▤",
            },
            {
                label: t("entries"),
                href: route("accounting.index") + "#entries",
                icon: "↔",
            },
            canReport
                ? {
                      label: t("reports"),
                      href: route("accounting.reports.index"),
                      icon: "▥",
                  }
                : null,
            permissions.includes("view_closing_readiness")
                ? {
                      label: t("closing"),
                      href: route("accounting.closing.index"),
                      icon: "✓",
                  }
                : null,
        ].filter(Boolean);
    }
    if (page.url.startsWith("/governance")) {
        return [
            {
                label: t("dashboard"),
                href: route("governance.dashboard"),
                icon: "⌂",
            },
            {
                label: t("governance"),
                href: route("governance.index"),
                icon: "⚖",
            },
            { label: t("add"), href: route("governance.create"), icon: "+" },
            {
                label: t("members"),
                href: route("governance.mandates.index"),
                icon: "♙",
            },
            {
                label: t("report"),
                href: route("governance.reports"),
                icon: "▤",
            },
        ];
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
const loggingOut = ref(false);
const clearOfflineCaches = async () => {
    const registration = await navigator.serviceWorker?.getRegistration();
    const controller = navigator.serviceWorker?.controller;
    if (!controller) {
        await registration?.unregister();
        return;
    }

    await new Promise<void>((resolve) => {
        const channel = new MessageChannel();
        const timeout = window.setTimeout(resolve, 2000);
        channel.port1.onmessage = (event) => {
            if (event.data?.type !== "EVOSYNDIC_CACHES_CLEARED") return;
            window.clearTimeout(timeout);
            resolve();
        };
        controller.postMessage({ type: "CLEAR_EVOSYNDIC_CACHES" }, [
            channel.port2,
        ]);
    });
    await registration?.unregister();
};
const logout = async () => {
    if (loggingOut.value) return;
    mobileSidebarOpen.value = false;
    loggingOut.value = true;
    await clearOfflineCaches();
    router.post(
        route("logout"),
        {},
        { onFinish: () => (loggingOut.value = false) },
    );
};
</script>

<template>
    <div :dir="dir" class="min-h-screen bg-stone-50 text-slate-800">
        <Head :title="title" />
        <aside
            :class="collapsed ? 'w-[76px]' : 'w-64'"
            class="fixed inset-y-0 z-30 hidden h-screen max-h-full overflow-hidden border-e border-slate-200 bg-slate-950 text-white transition-all supports-[height:100dvh]:h-[100dvh] lg:flex lg:flex-col"
        >
            <div
                :class="collapsed ? 'justify-center px-3' : 'px-5'"
                class="flex h-20 shrink-0 items-center border-b border-white/10"
            >
                <img
                    v-if="collapsed"
                    src="/images/evosyndic-symbol.png"
                    alt="EvoSyndic"
                    width="128"
                    height="128"
                    class="size-11 shrink-0 object-contain"
                />
                <img
                    v-else
                    src="/images/evosyndic-logo.png"
                    alt="EvoSyndic"
                    width="640"
                    height="158"
                    class="h-[54px] w-[216px] shrink-0 object-contain"
                />
            </div>
            <nav
                :aria-label="t('menu')"
                tabindex="0"
                class="sidebar-scroll min-h-0 flex-1 space-y-1 overflow-x-hidden overflow-y-auto overscroll-contain p-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-400"
            >
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    :aria-label="collapsed ? item.label : undefined"
                    :title="collapsed ? item.label : undefined"
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
            <div class="shrink-0 border-t border-white/10">
                <button
                    type="button"
                    :disabled="loggingOut"
                    @click="logout"
                    class="mx-3 mt-3 flex min-h-11 w-[calc(100%-1.5rem)] items-center justify-center rounded-xl border border-white/10 px-3 text-sm text-slate-300 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400 disabled:opacity-60"
                >
                    <span v-if="collapsed" aria-hidden="true">{{
                        loggingOut ? "…" : "↪"
                    }}</span>
                    <span v-else>{{ loggingOut ? "…" : t("logout") }}</span>
                    <span v-if="collapsed" class="sr-only">{{
                        t("logout")
                    }}</span>
                </button>
                <button
                    class="m-3 min-h-11 w-[calc(100%-1.5rem)] rounded-xl border border-white/10 text-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400"
                    @click="collapsed = !collapsed"
                    :aria-label="t('menu')"
                    :aria-expanded="!collapsed"
                >
                    {{ collapsed ? "›" : "‹" }}
                </button>
            </div>
        </aside>

        <div
            v-if="mobileSidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden"
            aria-hidden="true"
            @click="mobileSidebarOpen = false"
        ></div>
        <aside
            v-if="mobileSidebarOpen"
            id="mobile-sidebar"
            role="dialog"
            aria-modal="true"
            :aria-label="t('menu')"
            class="fixed inset-y-0 start-0 z-50 flex h-screen max-h-full w-[min(20rem,calc(100vw-2rem))] flex-col overflow-hidden bg-slate-950 text-white shadow-2xl supports-[height:100dvh]:h-[100dvh] lg:hidden"
            @keydown.esc="mobileSidebarOpen = false"
        >
            <div
                class="flex h-20 shrink-0 items-center justify-between gap-3 border-b border-white/10 px-5"
            >
                <img
                    src="/images/evosyndic-logo.png"
                    alt="EvoSyndic"
                    width="640"
                    height="158"
                    class="h-[54px] w-[216px] max-w-[calc(100%-3.5rem)] object-contain"
                />
                <button
                    type="button"
                    class="grid size-11 shrink-0 place-items-center rounded-xl border border-white/10 text-xl text-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400"
                    :aria-label="t('close')"
                    @click="mobileSidebarOpen = false"
                >
                    ×
                </button>
            </div>
            <nav
                :aria-label="t('menu')"
                tabindex="0"
                class="sidebar-scroll min-h-0 flex-1 space-y-1 overflow-x-hidden overflow-y-auto overscroll-contain p-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-400"
            >
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
                    @click="mobileSidebarOpen = false"
                >
                    <span class="w-6 text-center text-lg" aria-hidden="true">{{
                        item.icon
                    }}</span>
                    <span>{{ item.label }}</span>
                </Link>
            </nav>
            <div class="shrink-0 border-t border-white/10 p-3">
                <button
                    type="button"
                    :disabled="loggingOut"
                    class="flex min-h-11 w-full items-center justify-center rounded-xl border border-white/10 px-3 text-sm text-slate-300 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-400 disabled:opacity-60"
                    @click="logout"
                >
                    {{ loggingOut ? "…" : t("logout") }}
                </button>
            </div>
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
                    <button
                        type="button"
                        class="grid size-11 shrink-0 place-items-center rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 lg:hidden"
                        :aria-label="t('menu')"
                        aria-controls="mobile-sidebar"
                        :aria-expanded="mobileSidebarOpen"
                        @click="mobileSidebarOpen = true"
                    >
                        <img
                            src="/images/evosyndic-symbol.png"
                            alt="EvoSyndic"
                            width="128"
                            height="128"
                            class="size-10 object-contain"
                        />
                    </button>
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
                    <Link
                        v-if="contextualArticle"
                        :href="route('help.index', contextualArticle)"
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-3 text-sm font-semibold text-teal-800 hover:bg-teal-100 print:hidden"
                    >
                        <span aria-hidden="true">?</span>
                        {{ t("contextualHelp") }}
                    </Link>
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
                class="flex min-h-16 min-w-0 flex-col items-center justify-center gap-0.5 overflow-hidden text-[10px] font-semibold"
                ><span class="text-lg">{{ item!.icon }}</span
                ><span class="max-w-full truncate px-1">{{
                    item!.label
                }}</span></Link
            >
        </nav>
    </div>
</template>
