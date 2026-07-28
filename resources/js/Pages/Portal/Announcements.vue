<script setup lang="ts">
import { useForm, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import EmptyState from "@/Components/EmptyState.vue";
defineProps<{ announcements: any; lots: any[]; documents: any[] }>();
const form = useForm<any>({
    title_fr: "",
    title_ar: "",
    body_fr: "",
    body_ar: "",
    priority: "normal",
    audience: "all_residents",
    scheduled_for: "",
    lot_ids: [],
    document_ids: [],
});
</script>
<template>
    <AuthenticatedLayout
        title="Annonces"
        subtitle="Communications ciblées aux copropriétaires"
        ><div class="grid gap-5 xl:grid-cols-[400px_1fr]">
            <form
                id="announcement-create"
                class="panel grid h-fit gap-3 p-5"
                @submit.prevent="
                    form.post(route('announcements.store'), {
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <input v-model="form.title_fr" placeholder="Titre français" />
                <textarea
                    v-model="form.body_fr"
                    rows="5"
                    placeholder="Message français"
                ></textarea>
                <input
                    v-model="form.title_ar"
                    dir="rtl"
                    placeholder="العنوان بالعربية"
                />
                <textarea
                    v-model="form.body_ar"
                    dir="rtl"
                    rows="5"
                    placeholder="الرسالة بالعربية"
                ></textarea>
                <select v-model="form.priority">
                    <option value="normal">Normale</option>
                    <option value="important">Importante</option>
                    <option value="urgent">Urgente</option></select
                ><select v-model="form.audience">
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
                >
                    <option v-for="l in lots" :value="l.id">
                        {{ l.reference }}
                    </option></select
                ><label class="field"
                    ><span class="field-label"
                        >Publication planifiée (facultatif)</span
                    ><input
                        v-model="form.scheduled_for"
                        type="datetime-local" /></label
                ><select v-model="form.document_ids" multiple>
                    <option v-for="d in documents" :value="d.id">
                        {{ d.title }}
                    </option></select
                ><button class="btn-primary">Créer l’annonce</button>
                <p v-for="e in form.errors" class="text-sm text-red-600">
                    {{ e }}
                </p>
            </form>
            <div class="grid gap-3">
                <article v-for="a in announcements.data" class="panel p-5">
                    <div class="flex justify-between">
                        <h2 class="font-bold">{{ a.title }}</h2>
                        <span class="badge"
                            >{{ a.priority }} · {{ a.status }}</span
                        >
                    </div>
                    <p class="my-3 whitespace-pre-wrap text-sm">{{ a.body }}</p>
                    <p class="text-xs text-slate-500">
                        {{ a.audience }} · {{ a.lots.length }} lot(s) ciblé(s)
                    </p>
                    <button
                        v-if="a.status === 'draft'"
                        class="mt-3 text-sm text-teal-700"
                        @click="
                            router.post(route('announcements.publish', a.id))
                        "
                    >
                        Publier maintenant
                    </button>
                </article>
                <EmptyState
                    v-if="!announcements.data.length"
                    title="Aucune annonce"
                    message="Créez une annonce pour informer tous les résidents ou seulement certains lots."
                    primary-label="Créer une annonce"
                    primary-href="#announcement-create"
                >
                    <template #icon>◉</template>
                </EmptyState>
            </div>
        </div></AuthenticatedLayout
    >
</template>
