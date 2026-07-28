<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, nextTick, ref } from "vue";
import { formatMADCents as money } from "@/Support/money";
const props = defineProps<{ entry: any }>();
const { entry } = props;
const permissions = computed(
    () => usePage<any>().props.auth?.permissions ?? [],
);
const isAr = computed(() => usePage<any>().props.locale === "ar");
const l = (fr: string, ar: string) => (isAr.value ? ar : fr);
const entryStatus = computed(() => {
    const labels: Record<string, [string, string]> = {
        draft: ["Brouillon", "مسودة"],
        validated: ["Validée", "مصادق عليها"],
        posted: ["Comptabilisée", "مرحلة"],
        reversed: ["Extournée", "معكوسة"],
    };
    const label = labels[entry.status];
    return label
        ? l(...label)
        : String(entry.status || "—")
              .replaceAll("_", " ")
              .replace(/^./, (letter) => letter.toUpperCase());
});
const postDialog = ref(false);
const postConfirmButton = ref<HTMLButtonElement | null>(null);
const openPostDialog = async () => {
    postDialog.value = true;
    await nextTick();
    postConfirmButton.value?.focus();
};
const closePostDialog = () => {
    postDialog.value = false;
};
const post = () => {
    router.post(route("accounting.entries.post", props.entry.id), undefined, {
        preserveScroll: true,
        onSuccess: closePostDialog,
    });
};
const reversalDialog = ref(false);
const periodInput = ref<HTMLInputElement | null>(null);
const reversal = useForm({
    accounting_period_id: "",
    reason: "",
});
const openReversalDialog = async () => {
    reversalDialog.value = true;
    await nextTick();
    periodInput.value?.focus();
};
const closeReversalDialog = () => {
    if (!reversal.processing) reversalDialog.value = false;
};
const reverse = () => {
    reversal.post(route("accounting.entries.reverse", props.entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            reversalDialog.value = false;
            reversal.reset();
        },
    });
};
</script>
<template>
    <Head :title="entry.entry_number || `Brouillon #${entry.id}`" />
    <AuthenticatedLayout
        :title="entry.entry_number || `Brouillon #${entry.id}`"
        :subtitle="l('Détail de l’écriture comptable', 'تفاصيل القيد المحاسبي')"
    >
        <template #actions
            ><div class="flex gap-2">
                <button
                    v-if="
                        entry.status === 'draft' &&
                        permissions.includes('post_accounting_entries')
                    "
                    class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                    @click="openPostDialog"
                >
                    {{ l("Comptabiliser", "ترحيل") }}</button
                ><button
                    v-if="
                        entry.status === 'posted' &&
                        permissions.includes('reverse_accounting_entries')
                    "
                    class="rounded-xl bg-red-700 px-4 py-2 text-white"
                    @click="openReversalDialog"
                >
                    {{ l("Contre-passer", "عكس القيد") }}
                </button>
            </div></template
        >
        <div class="space-y-5">
            <div
                v-if="entry.status !== 'draft'"
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"
            >
                {{
                    l(
                        "Cette écriture est immuable. Toute correction exige une contre-passation et une nouvelle écriture.",
                        "هذا القيد غير قابل للتعديل. يتطلب أي تصحيح قيدا عكسيا وقيدا جديدا.",
                    )
                }}
            </div>
            <section
                class="grid gap-3 rounded-2xl border bg-white p-5 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div>
                    <span class="text-xs text-slate-500">Statut</span>
                    <p class="font-bold">{{ entryStatus }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-500">Date</span>
                    <p>{{ entry.entry_date }}</p>
                </div>
                <div>
                    <span class="text-xs text-slate-500">Journal</span>
                    <p>
                        {{ entry.journal.code }} — {{ entry.journal.label_fr }}
                    </p>
                </div>
                <div>
                    <span class="text-xs text-slate-500">Référence</span>
                    <p>{{ entry.reference || "—" }}</p>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <span class="text-xs text-slate-500">Description</span>
                    <p>{{ entry.description_fr }}</p>
                </div>
            </section>
            <section class="overflow-x-auto rounded-2xl border bg-white p-5">
                <table class="min-w-[640px] w-full text-sm">
                    <thead>
                        <tr>
                            <th class="p-2 text-start">Compte</th>
                            <th class="p-2 text-start">Libellé</th>
                            <th class="p-2 text-end">Débit</th>
                            <th class="p-2 text-end">Crédit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in entry.lines"
                            :key="line.id"
                            class="border-t"
                        >
                            <td class="p-2 font-mono">
                                {{
                                    line.account_code_snapshot ||
                                    line.account.code
                                }}
                            </td>
                            <td class="p-2">{{ line.label }}</td>
                            <td class="p-2 text-end">
                                {{
                                    line.debit_minor
                                        ? money(line.debit_minor)
                                        : "—"
                                }}
                            </td>
                            <td class="p-2 text-end">
                                {{
                                    line.credit_minor
                                        ? money(line.credit_minor)
                                        : "—"
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
        <div
            v-if="postDialog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
            @keydown.esc="closePostDialog"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="post-title"
                class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl"
            >
                <h2 id="post-title" class="text-lg font-bold">
                    {{ l("Confirmer la comptabilisation", "تأكيد الترحيل") }}
                </h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{
                        l(
                            "Cette écriture deviendra définitive et ne pourra être corrigée que par une contre-passation.",
                            "سيصبح هذا القيد نهائيا ولا يمكن تصحيحه إلا بقيد عكسي.",
                        )
                    }}
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-xl border px-4 py-2"
                        @click="closePostDialog"
                    >
                        {{ l("Annuler", "إلغاء") }}
                    </button>
                    <button
                        ref="postConfirmButton"
                        type="button"
                        class="rounded-xl bg-teal-700 px-4 py-2 text-white"
                        @click="post"
                    >
                        {{ l("Comptabiliser", "ترحيل") }}
                    </button>
                </div>
            </div>
        </div>
        <div
            v-if="reversalDialog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
            @keydown.esc="closeReversalDialog"
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby="reversal-title"
                class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl"
                @submit.prevent="reverse"
            >
                <h2 id="reversal-title" class="text-lg font-bold">
                    {{ l("Confirmer la contre-passation", "تأكيد عكس القيد") }}
                </h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{
                        l(
                            "Le motif et une période ouverte sont obligatoires. L’écriture d’origine restera immuable.",
                            "السبب والفترة المفتوحة إلزاميان. سيظل القيد الأصلي غير قابل للتعديل.",
                        )
                    }}
                </p>
                <label class="mt-4 block text-sm font-medium">
                    {{
                        l(
                            "Identifiant de la période ouverte",
                            "معرف الفترة المفتوحة",
                        )
                    }}
                    <input
                        ref="periodInput"
                        v-model="reversal.accounting_period_id"
                        type="number"
                        min="1"
                        required
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                </label>
                <label class="mt-4 block text-sm font-medium">
                    {{ l("Motif obligatoire", "السبب الإلزامي") }}
                    <textarea
                        v-model="reversal.reason"
                        required
                        rows="3"
                        class="mt-1 w-full rounded-xl border-slate-300"
                    />
                </label>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-xl border px-4 py-2"
                        :disabled="reversal.processing"
                        @click="closeReversalDialog"
                    >
                        {{ l("Annuler", "إلغاء") }}
                    </button>
                    <button
                        type="submit"
                        class="rounded-xl bg-red-700 px-4 py-2 text-white disabled:opacity-50"
                        :disabled="reversal.processing"
                    >
                        {{ l("Contre-passer", "عكس القيد") }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
