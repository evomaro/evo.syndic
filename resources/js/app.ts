import "../css/app.css";
import "./bootstrap";

import { createInertiaApp, router } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createApp, DefineComponent, h } from "vue";
import { ZiggyVue } from "../../vendor/tightenco/ziggy";

const appName = import.meta.env.VITE_APP_NAME || "EvoSyndic";
const applyLocale = (value: unknown) => {
    const locale = value === "ar" ? "ar" : "fr";
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === "ar" ? "rtl" : "ltr";
};
const registerServiceWorker = () => {
    if (!("serviceWorker" in navigator) || !window.isSecureContext) return;
    void navigator.serviceWorker.register("/sw.js");
};
const applyPageState = (props: any) => {
    applyLocale(props.locale);
    document.documentElement.dataset.authenticated = props.auth?.user
        ? "true"
        : "false";
    if (props.auth?.user) registerServiceWorker();
};

window.addEventListener("pageshow", (event) => {
    const navigation = performance.getEntriesByType(
        "navigation",
    )[0] as PerformanceNavigationTiming;
    if (
        document.documentElement.dataset.authenticated === "true" &&
        (event.persisted || navigation?.type === "back_forward")
    ) {
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>("./Pages/**/*.vue"),
        ),
    setup({ el, App, props, plugin }) {
        applyPageState(props.initialPage.props);
        router.on("navigate", (event) =>
            applyPageState(event.detail.page.props),
        );
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: "#4B5563",
    },
});
