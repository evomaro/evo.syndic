<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { useI18n } from "@/i18n";
const p = defineProps<{ batch: any; columns: string[]; preview: any[] }>();
const { t } = useI18n();
const targets: { [key: string]: string[] } = {
    lots: ["reference", "lot_number", "type", "surface"],
    contacts: [
        "type",
        "first_name",
        "last_name",
        "company_name",
        "cin",
        "primary_email",
        "primary_phone",
        "preferred_language",
    ],
    ownerships: [
        "lot_reference",
        "contact_identifier",
        "percentage",
        "is_primary",
        "starts_on",
    ],
    occupancies: [
        "lot_reference",
        "contact_identifier",
        "occupancy_type",
        "is_primary",
        "starts_on",
    ],
    allocations: ["lot_reference", "allocation_key_code", "value"],
};
const form = useForm({
    mapping: Object.fromEntries(
        targets[p.batch.type].map((x) => [
            x,
            p.columns.find((c) => c.toLowerCase() === x) || "",
        ]),
    ),
});
</script>
<template>
    <AuthenticatedLayout
        :title="t('mapColumns')"
        :subtitle="batch.original_filename"
        ><form
            class="panel mx-auto max-w-3xl p-5"
            @submit.prevent="form.post(route('imports.confirm', batch.id))"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label
                    v-for="target in targets[batch.type]"
                    :key="target"
                    class="field"
                    ><span class="field-label">{{ target }}</span
                    ><select v-model="form.mapping[target]" required>
                        <option value="">—</option>
                        <option v-for="column in columns" :key="column">
                            {{ column }}
                        </option>
                    </select></label
                >
            </div>
            <div class="mt-6 overflow-x-auto rounded-xl border">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th
                                v-for="c in columns"
                                :key="c"
                                class="p-3 text-start"
                            >
                                {{ c }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in preview" :key="i">
                            <td
                                v-for="c in columns"
                                :key="c"
                                class="border-t p-3"
                            >
                                {{ row[c] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button class="btn-primary mt-5">{{ t("confirm") }}</button>
        </form></AuthenticatedLayout
    >
</template>
