<script setup lang="ts">
import { router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import FinancialStatusBadge from "@/Components/Expenses/FinancialStatusBadge.vue";
import PrivateAttachmentUploader from "@/Components/Expenses/PrivateAttachmentUploader.vue";

const props = defineProps<{ contract: any }>();
const transition = useForm({
    action: "terminate",
    starts_on: "",
    ends_on: "",
    reason: "",
});
const money = (cents: number) =>
    new Intl.NumberFormat("fr-MA", {
        style: "currency",
        currency: "MAD",
    }).format(Number(cents || 0) / 100);
const date = (value?: string) =>
    value
        ? new Intl.DateTimeFormat("fr-MA", { dateStyle: "medium" }).format(
              new Date(value),
          )
        : "Indéterminée";

const upload = (payload: {
    file: File;
    reusable_on_renewal: boolean;
    replaces_id: number | null;
}) => {
    const data = new FormData();
    data.append("file", payload.file);
    data.append("reusable_on_renewal", payload.reusable_on_renewal ? "1" : "0");
    if (payload.replaces_id)
        data.append("replaces_id", String(payload.replaces_id));
    router.post(
        route("supplier-contracts.attachments.store", props.contract.id),
        data,
        { forceFormData: true },
    );
};
const archive = (id: number) =>
    window.confirm(
        "Archiver cette version ? Elle restera accessible dans l’historique.",
    ) && router.delete(route("supplier-contracts.attachments.destroy", id));
</script>

<template>
    <AuthenticatedLayout
        :title="contract.title"
        subtitle="Contrat, renouvellement et versions privées"
    >
        <ExpenseNavigation />
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="panel p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <FinancialStatusBadge :status="contract.status" /><b>{{
                        contract.reference || "Sans référence"
                    }}</b>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Fournisseur</dt>
                        <dd class="font-medium">
                            {{ contract.supplier.legal_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Montant</dt>
                        <dd class="font-medium">
                            {{ money(contract.amount_cents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Période</dt>
                        <dd>
                            {{ date(contract.starts_on) }} →
                            {{ date(contract.ends_on) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Renouvellement</dt>
                        <dd>
                            {{ contract.renewal_type }} · préavis
                            {{ contract.notice_days }} j
                        </dd>
                    </div>
                </dl>
                <form
                    v-if="contract.status === 'active'"
                    class="mt-5 grid gap-3 border-t pt-4"
                    @submit.prevent="
                        transition.post(
                            route('supplier-contracts.transition', contract.id),
                        )
                    "
                >
                    <h2 class="font-bold">Faire évoluer le contrat</h2>
                    <select
                        v-model="transition.action"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="renew">Renouveler</option>
                        <option value="terminate">Résilier</option>
                    </select>
                    <div
                        v-if="transition.action === 'renew'"
                        class="grid grid-cols-2 gap-2"
                    >
                        <input
                            v-model="transition.starts_on"
                            type="date"
                            class="rounded-lg border-slate-300"
                            aria-label="Début de la nouvelle période"
                        /><input
                            v-model="transition.ends_on"
                            type="date"
                            class="rounded-lg border-slate-300"
                            aria-label="Fin de la nouvelle période"
                        />
                    </div>
                    <textarea
                        v-model="transition.reason"
                        class="rounded-lg border-slate-300"
                        minlength="5"
                        required
                        placeholder="Motif auditable"
                    ></textarea>
                    <p
                        v-if="transition.errors.reason"
                        class="text-sm text-rose-700"
                    >
                        {{ transition.errors.reason }}
                    </p>
                    <button
                        class="btn-primary"
                        :disabled="transition.processing"
                    >
                        {{
                            transition.processing
                                ? "Traitement…"
                                : "Confirmer l’action"
                        }}
                    </button>
                </form>
            </section>
            <section class="panel p-5">
                <h2 class="mb-3 font-bold">Pièces privées</h2>
                <p
                    v-if="!contract.attachments.length"
                    class="mb-3 text-sm text-slate-500"
                >
                    Aucune pièce active. Les fichiers sont privés, contrôlés par
                    empreinte et servis sans cache.
                </p>
                <div
                    v-for="a in contract.attachments"
                    :key="a.id"
                    class="mb-2 flex items-center justify-between gap-2 rounded-lg bg-slate-50 p-3"
                >
                    <a
                        :href="
                            route(
                                'supplier-contracts.attachments.download',
                                a.id,
                            )
                        "
                        class="min-h-11 py-2 text-teal-700"
                        ><b>{{ a.name }}</b
                        ><small class="block text-slate-500"
                            >v{{ a.version }} ·
                            {{ (a.size / 1024).toFixed(1) }} Ko<span
                                v-if="a.reusable_on_renewal"
                            >
                                · réutilisable</span
                            ></small
                        ></a
                    >
                    <button
                        v-if="contract.status === 'active'"
                        type="button"
                        class="btn-secondary"
                        @click="archive(a.id)"
                    >
                        Archiver
                    </button>
                </div>
                <PrivateAttachmentUploader
                    v-if="contract.status === 'active'"
                    :attachments="contract.attachments"
                    @upload="upload"
                />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
