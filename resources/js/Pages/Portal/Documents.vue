<script setup lang="ts">
import { useForm, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
defineProps<{ documents: any; lots: any[] }>();
const form = useForm<any>({
    title: "",
    category: "other",
    audience: "staff",
    document_date: "",
    file: null,
    lot_ids: [],
});
</script>
<template>
    <AuthenticatedLayout
        title="Documents partagés"
        subtitle="Fichiers privés, versionnés et publiés selon leur audience"
        ><div class="grid gap-5 xl:grid-cols-[380px_1fr]">
            <form
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('documents.store'), {
                        forceFormData: true,
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <input
                    v-model="form.title"
                    placeholder="Titre"
                    required
                /><select v-model="form.category">
                    <option value="regulation">Règlement</option>
                    <option value="contract">Contrat</option>
                    <option value="report">Rapport</option>
                    <option value="minutes">Procès-verbal</option>
                    <option value="other">Autre</option></select
                ><select v-model="form.audience">
                    <option value="staff">Gestionnaires seulement</option>
                    <option value="all_residents">
                        Tous les copropriétaires
                    </option>
                    <option value="selected_lots">
                        Lots sélectionnés
                    </option></select
                ><select
                    v-if="form.audience === 'selected_lots'"
                    v-model="form.lot_ids"
                    multiple
                    class="min-h-28"
                >
                    <option v-for="l in lots" :value="l.id">
                        {{ l.reference }}
                    </option></select
                ><input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                    required
                    @change="
                        form.file =
                            ($event.target as HTMLInputElement).files?.[0] ||
                            null
                    "
                /><button class="btn-primary">Téléverser en privé</button>
                <p v-for="e in form.errors" class="text-sm text-red-600">
                    {{ e }}
                </p>
            </form>
            <div class="grid gap-3">
                <article
                    v-for="d in documents.data"
                    class="panel flex items-center justify-between gap-3 p-5"
                >
                    <div>
                        <b>{{ d.title }}</b>
                        <p class="text-sm text-slate-500">
                            {{ d.category }} · {{ d.audience }} · v{{
                                d.latest_version?.version || 0
                            }}
                        </p>
                    </div>
                    <div>
                        <span class="badge">{{ d.status }}</span
                        ><a
                            v-if="d.latest_version"
                            class="ms-3 text-sm text-teal-700"
                            :href="
                                route('documents.download', d.latest_version.id)
                            "
                            >Télécharger</a
                        ><button
                            v-if="d.status === 'draft'"
                            class="ms-3 text-sm text-teal-700"
                            @click="
                                router.post(
                                    route('documents.transition', d.id),
                                    { action: 'publish' },
                                )
                            "
                        >
                            Publier
                        </button>
                    </div>
                </article>
                <p
                    v-if="!documents.data.length"
                    class="panel p-8 text-center text-slate-500"
                >
                    Aucun document
                </p>
            </div>
        </div></AuthenticatedLayout
    >
</template>
