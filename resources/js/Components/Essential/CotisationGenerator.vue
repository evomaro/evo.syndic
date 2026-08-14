<script setup lang="ts">
import { ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import axios from "axios";
import Modal from "@/Components/Modal.vue";
import PeriodPicker from "@/Components/PeriodPicker.vue";
import InputError from "@/Components/InputError.vue";
import { formatMADCents } from "@/Support/money";
import { useI18n } from "@/i18n";

const props = defineProps<{
    show: boolean;
    period: string;
    activeResidenceId: number | null;
    generation: any;
}>();
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
const preview = ref<any>(null);
const previewing = ref(false);
const previewError = ref("");
const form = useForm({
    residence_id: props.activeResidenceId,
    period: props.period,
    amount:
        props.generation.latest_total_cents > 0
            ? (props.generation.latest_total_cents / 100).toFixed(2)
            : "",
    distribution_method: props.generation.default_allocation_key
        ? "allocation_key"
        : "equal",
});

watch(
    () => props.show,
    (show) => {
        if (show) {
            form.period = props.period;
            preview.value = null;
            previewError.value = "";
        }
    },
);
const invalidate = () => {
    preview.value = null;
    previewError.value = "";
};
const reuseLatest = () => {
    form.amount = (props.generation.latest_total_cents / 100).toFixed(2);
    invalidate();
};
const loadPreview = async () => {
    previewing.value = true;
    previewError.value = "";
    try {
        const response = await axios.post(
            route("essential.cotisations.preview"),
            form.data(),
        );
        preview.value = response.data;
    } catch (error: any) {
        const errors = error.response?.data?.errors;
        previewError.value = errors
            ? Object.values(errors).flat().join(" ")
            : t("essentialDistributionError");
    } finally {
        previewing.value = false;
    }
};
const submit = () =>
    form.post(route("essential.cotisations.generate"), {
        preserveScroll: true,
        onSuccess: () => emit("close"),
    });
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <form
            class="space-y-5 p-6"
            @submit.prevent="preview ? submit() : loadPreview()"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">
                        {{ t("essentialGenerateCotisation") }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ t("essentialGeneratorHelp") }}
                    </p>
                </div>
                <button type="button" class="size-11" @click="emit('close')">
                    ×
                </button>
            </div>

            <template v-if="!preview">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium"
                        >{{ t("essentialMonth")
                        }}<PeriodPicker
                            v-model="form.period"
                            class="mt-1 w-full"
                            @change="invalidate"
                    /></label>
                    <label class="text-sm font-medium"
                        >{{ t("essentialTotalAmountMad")
                        }}<input
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                            @input="invalidate" /><InputError
                            :message="form.errors.amount"
                    /></label>
                    <label class="text-sm font-medium sm:col-span-2"
                        >{{ t("essentialDistribution")
                        }}<select
                            v-model="form.distribution_method"
                            class="mt-1 block min-h-11 w-full rounded-xl border-slate-300"
                            @change="invalidate"
                        >
                            <option
                                value="allocation_key"
                                :disabled="!generation.default_allocation_key"
                            >
                                {{
                                    generation.default_allocation_key
                                        ? `${t("essentialByShares")} — ${generation.default_allocation_key.name}`
                                        : t("essentialByShares")
                                }}
                            </option>
                            <option value="equal">
                                {{ t("essentialEqualShares") }}
                            </option>
                        </select></label
                    >
                </div>
                <button
                    v-if="generation.latest_total_cents > 0"
                    type="button"
                    class="text-sm font-semibold text-teal-700 underline"
                    @click="reuseLatest"
                >
                    {{
                        t("essentialReuseLatest", {
                            amount: formatMADCents(
                                generation.latest_total_cents,
                            ),
                        })
                    }}
                </button>
            </template>

            <template v-else>
                <div class="rounded-xl bg-teal-50 p-4 text-sm text-teal-950">
                    {{
                        t("essentialPreviewSummary", {
                            count: preview.allocations.length,
                            amount: formatMADCents(preview.total_cents),
                            period: preview.period,
                        })
                    }}
                </div>
                <div
                    class="max-h-80 overflow-y-auto rounded-xl border border-slate-200"
                >
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 text-start">
                            <tr>
                                <th class="p-3">{{ t("lots") }}</th>
                                <th class="p-3 text-end">
                                    {{ t("essentialToInvoice") }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in preview.allocations"
                                :key="row.lot_id"
                            >
                                <td class="p-3 font-medium">{{ row.lot }}</td>
                                <td class="p-3 text-end font-semibold">
                                    {{ formatMADCents(row.amount_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <p v-if="previewError" class="text-sm text-rose-700">
                {{ previewError }}
            </p>
            <InputError
                :message="form.errors.period || form.errors.distribution_method"
            />
            <div class="flex justify-end gap-2">
                <button
                    v-if="preview"
                    type="button"
                    class="min-h-11 rounded-xl border px-4"
                    @click="preview = null"
                >
                    {{ t("essentialModify") }}
                </button>
                <button
                    :disabled="previewing || form.processing"
                    class="min-h-11 rounded-xl bg-teal-700 px-4 font-semibold text-white disabled:opacity-60"
                >
                    {{
                        previewing
                            ? t("essentialCalculating")
                            : form.processing
                              ? t("essentialGenerating")
                              : preview
                                ? t("essentialConfirmGenerate")
                                : t("essentialViewDistribution")
                    }}
                </button>
            </div>
        </form>
    </Modal>
</template>
