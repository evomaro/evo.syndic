<script setup lang="ts">
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import { Link, usePage } from "@inertiajs/vue3";
import { useI18n } from "@/i18n";
const { dir, locale } = useI18n();
const page = usePage();
const localeHref = (value: string) => {
    const url = new URL(page.url, window.location.origin);
    url.searchParams.set("locale", value);
    return url.pathname + url.search;
};
</script>

<template>
    <div
        :dir="dir"
        class="flex min-h-screen flex-col items-center bg-slate-950 px-4 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/" class="flex items-center gap-3 text-white">
                <ApplicationLogo class="h-16 w-16 fill-current text-teal-400" />
                <span class="text-2xl font-black">EvoSyndic</span>
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden rounded-2xl bg-white px-6 py-6 shadow-xl sm:max-w-md"
        >
            <nav
                class="mb-5 flex justify-end gap-2 text-sm"
                aria-label="Langue"
            >
                <Link
                    class="inline-flex min-h-11 items-center"
                    :href="localeHref('fr')"
                    :class="
                        locale === 'fr'
                            ? 'font-bold text-teal-700'
                            : 'text-slate-500'
                    "
                    >Français</Link
                >
                <span aria-hidden="true">·</span>
                <Link
                    class="inline-flex min-h-11 items-center"
                    :href="localeHref('ar')"
                    :class="
                        locale === 'ar'
                            ? 'font-bold text-teal-700'
                            : 'text-slate-500'
                    "
                    >العربية</Link
                >
            </nav>
            <slot />
        </div>
    </div>
</template>
