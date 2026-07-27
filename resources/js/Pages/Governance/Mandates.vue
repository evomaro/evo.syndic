<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
defineProps<{ mandates: any; users: any[]; contacts: any[] }>();
const ar = usePage<any>().props.locale === "ar";
const f = useForm({
    user_id: "",
    contact_id: "",
    role: "syndic",
    starts_on: "",
    ends_on: "",
    appointing_resolution_id: "",
});
const transition = (id: number, status: string) => {
    const reason = window.prompt(ar ? "سبب مفصل" : "Motif détaillé");
    if (reason)
        router.post(
            route("governance.mandates.transition", id),
            { status, reason },
            { preserveScroll: true },
        );
};
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'ولايات الحكامة' : 'Mandats de gouvernance'"
        ><GovernanceNav />
        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="min-w-0 space-y-3">
                <article
                    v-for="m in mandates.data"
                    :key="m.id"
                    class="rounded-2xl border bg-white p-5"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <b>{{ m.role }} · {{ m.status }}</b
                        ><span class="text-sm text-slate-500"
                            >{{ m.starts_on }} → {{ m.ends_on }}</span
                        >
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button
                            v-if="m.status === 'draft'"
                            @click="transition(m.id, 'active')"
                            class="rounded-lg bg-teal-700 px-3 py-2 text-white"
                        >
                            {{ ar ? "تفعيل" : "Activer" }}</button
                        ><button
                            v-if="m.status === 'active'"
                            @click="transition(m.id, 'revoked')"
                            class="rounded-lg border border-red-300 px-3 py-2 text-red-700"
                        >
                            {{ ar ? "سحب" : "Révoquer" }}
                        </button>
                    </div>
                </article>
                <p
                    v-if="!mandates.data.length"
                    class="rounded-2xl border bg-white p-8 text-center text-slate-500"
                >
                    {{ ar ? "لا توجد ولاية" : "Aucun mandat" }}
                </p>
            </section>
            <form
                @submit.prevent="
                    f.post(route('governance.mandates.store'), {
                        preserveScroll: true,
                        onSuccess: () => f.reset(),
                    })
                "
                class="h-fit min-w-0 rounded-2xl border bg-white p-5"
            >
                <h2 class="font-bold">
                    {{ ar ? "ولاية جديدة" : "Nouveau mandat" }}
                </h2>
                <div class="mt-4 grid gap-3">
                    <select
                        v-model="f.role"
                        class="min-w-0 rounded-xl border-slate-300"
                    >
                        <option value="syndic">Syndic</option>
                        <option value="deputy_syndic">Syndic adjoint</option>
                        <option value="council_member">
                            Conseil syndical
                        </option></select
                    ><select
                        v-model="f.user_id"
                        class="min-w-0 rounded-xl border-slate-300"
                    >
                        <option value="">Utilisateur (facultatif)</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">
                            {{ u.name }}
                        </option></select
                    ><select
                        v-model="f.contact_id"
                        class="min-w-0 rounded-xl border-slate-300"
                    >
                        <option value="">Contact (facultatif)</option>
                        <option v-for="c in contacts" :key="c.id" :value="c.id">
                            {{
                                c.company_name ||
                                `${c.first_name ?? ""} ${c.last_name ?? ""}`
                            }}
                        </option></select
                    ><input
                        v-model="f.starts_on"
                        type="date"
                        required
                        class="rounded-xl border-slate-300"
                    /><input
                        v-model="f.ends_on"
                        type="date"
                        required
                        class="rounded-xl border-slate-300"
                    /><button
                        class="rounded-xl bg-slate-900 px-4 py-2 text-white"
                    >
                        {{ ar ? "إنشاء" : "Créer" }}
                    </button>
                    <p
                        v-if="Object.values(f.errors)[0]"
                        class="break-words text-sm text-red-600"
                    >
                        {{ Object.values(f.errors)[0] }}
                    </p>
                </div>
            </form>
        </div></AuthenticatedLayout
    >
</template>
