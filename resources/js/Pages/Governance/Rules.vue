<script setup lang="ts">
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, useForm, usePage } from "@inertiajs/vue3";

defineProps<{ sources: any[]; rules: any[]; legalNotice: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const statusLabels: Record<string, string> = {
    unverified_draft: ar ? "مسودة غير متحقق منها" : "Brouillon non vérifié",
    official_source_located: ar
        ? "تم تحديد مصدر رسمي"
        : "Source officielle localisée",
    source_verified: ar ? "تم التحقق من المصدر" : "Source vérifiée",
    professionally_reviewed: ar ? "مراجعة مهنية" : "Revue professionnelle",
    counsel_reviewed: ar ? "مراجعة قانونية" : "Revue du conseil",
    approved: ar ? "معتمدة تقنيا" : "Approuvée techniquement",
    active: ar ? "نشطة" : "Active",
    superseded: ar ? "مستبدلة" : "Remplacée",
};
const sourceForm = useForm({
    code: "",
    jurisdiction: "MA",
    issuing_authority: "",
    official_title: "",
    official_url: "",
    document_reference: "",
});
const ruleForm = useForm({
    stable_code: "",
    jurisdiction: "MA",
    assembly_type: "ordinary",
    resolution_category: "",
    title_fr: "",
    title_ar: "",
    governance_rule_source_id: null as number | null,
    effective_from: "",
    effective_until: "",
    numerator_definition: "configured_numerator",
    denominator_definition: "configured_denominator",
    threshold_numerator: 0,
    threshold_denominator: 1,
    comparison: "gte",
    rounding_policy: "none",
    abstention_behavior: "configured",
    invalid_ballot_behavior: "configured",
    voting_share_source_type: "unverified",
    effective_date_policy: "meeting_date",
});
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="ar ? 'قواعد الحوكمة' : 'Règles de gouvernance'" />
        <div class="mx-auto max-w-7xl px-4 py-6">
            <GovernanceNav />
            <h1 class="text-2xl font-bold">
                {{ ar ? "قواعد الحوكمة" : "Règles de gouvernance" }}
            </h1>
            <div
                role="alert"
                class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
            >
                {{ ar ? legalNotice.ar : legalNotice.fr }}
            </div>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <form
                    class="space-y-3 rounded-xl border bg-white p-5"
                    @submit.prevent="
                        sourceForm.post(
                            route('governance.rule-sources.store'),
                            {
                                preserveScroll: true,
                                onSuccess: () => sourceForm.reset(),
                            },
                        )
                    "
                >
                    <h2 class="font-bold">
                        {{ ar ? "مصدر رسمي" : "Source officielle" }}
                    </h2>
                    <input
                        v-model="sourceForm.code"
                        required
                        class="w-full rounded-lg border"
                        :placeholder="ar ? 'رمز المصدر' : 'Code source'"
                    />
                    <input
                        v-model="sourceForm.official_title"
                        required
                        class="w-full rounded-lg border"
                        :placeholder="ar ? 'العنوان الرسمي' : 'Titre officiel'"
                    />
                    <input
                        v-model="sourceForm.official_url"
                        class="w-full rounded-lg border"
                        type="url"
                        :placeholder="ar ? 'الرابط الرسمي' : 'URL officielle'"
                    />
                    <input
                        v-model="sourceForm.document_reference"
                        class="w-full rounded-lg border"
                        :placeholder="
                            ar ? 'مرجع الوثيقة' : 'Référence documentaire'
                        "
                    />
                    <button
                        class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white"
                        :disabled="sourceForm.processing"
                    >
                        {{ ar ? "حفظ المسودة" : "Enregistrer le brouillon" }}
                    </button>
                </form>

                <form
                    class="space-y-3 rounded-xl border bg-white p-5"
                    @submit.prevent="
                        ruleForm.post(route('governance.rule-versions.store'), {
                            preserveScroll: true,
                        })
                    "
                >
                    <h2 class="font-bold">
                        {{
                            ar
                                ? "نسخة قاعدة تقنية"
                                : "Version de règle technique"
                        }}
                    </h2>
                    <input
                        v-model="ruleForm.stable_code"
                        required
                        class="w-full rounded-lg border"
                        :placeholder="ar ? 'رمز ثابت' : 'Code stable'"
                    />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <input
                            v-model="ruleForm.title_fr"
                            required
                            class="rounded-lg border"
                            placeholder="Libellé français"
                        />
                        <input
                            v-model="ruleForm.title_ar"
                            required
                            dir="rtl"
                            class="rounded-lg border"
                            placeholder="التسمية العربية"
                        />
                    </div>
                    <input
                        v-model="ruleForm.resolution_category"
                        required
                        class="w-full rounded-lg border"
                        :placeholder="
                            ar ? 'فئة القرار' : 'Catégorie de résolution'
                        "
                    />
                    <select
                        v-model="ruleForm.governance_rule_source_id"
                        class="w-full rounded-lg border"
                    >
                        <option :value="null">
                            {{
                                ar
                                    ? "بدون مصدر — التنشيط محظور"
                                    : "Sans source — activation bloquée"
                            }}
                        </option>
                        <option
                            v-for="source in sources"
                            :key="source.id"
                            :value="source.id"
                        >
                            {{ source.official_title }} ·
                            {{
                                statusLabels[source.confidence] ||
                                (ar ? "حالة تقنية" : "État technique")
                            }}
                        </option>
                    </select>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <input
                            v-model="ruleForm.effective_from"
                            required
                            type="date"
                            class="rounded-lg border"
                        />
                        <input
                            v-model.number="ruleForm.threshold_denominator"
                            required
                            min="1"
                            type="number"
                            class="rounded-lg border"
                        />
                    </div>
                    <button
                        class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white"
                        :disabled="ruleForm.processing"
                    >
                        {{
                            ar
                                ? "إنشاء نسخة غير نشطة"
                                : "Créer la version inactive"
                        }}
                    </button>
                </form>
            </div>

            <section class="mt-8 overflow-hidden rounded-xl border bg-white">
                <h2 class="border-b p-4 font-bold">
                    {{ ar ? "سجل النسخ" : "Registre des versions" }}
                </h2>
                <div class="max-w-full overflow-x-auto">
                    <table class="min-w-[900px] w-full text-sm">
                        <thead class="bg-slate-50 text-start">
                            <tr>
                                <th class="p-3 text-start">
                                    {{ ar ? "القاعدة" : "Règle" }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ ar ? "النسخة" : "Version" }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ ar ? "الحالة" : "État" }}
                                </th>
                                <th class="p-3 text-start">
                                    {{ ar ? "المصدر" : "Source" }}
                                </th>
                                <th class="p-3 text-start">
                                    {{
                                        ar
                                            ? "إجراءات مضبوطة"
                                            : "Actions contrôlées"
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="rule in rules" :key="rule.id">
                                <tr
                                    v-for="version in rule.versions"
                                    :key="version.id"
                                    class="border-t"
                                >
                                    <td class="p-3">
                                        {{ ar ? rule.title_ar : rule.title_fr }}
                                    </td>
                                    <td class="p-3">{{ version.version }}</td>
                                    <td class="p-3">
                                        {{
                                            statusLabels[version.status] ||
                                            (ar
                                                ? "حالة تقنية"
                                                : "État technique")
                                        }}
                                    </td>
                                    <td class="p-3">
                                        {{
                                            version.source?.official_title ||
                                            (ar ? "مفقود" : "Absente")
                                        }}
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-if="
                                                    version.status ===
                                                        'unverified_draft' ||
                                                    version.status ===
                                                        'official_source_located'
                                                "
                                                class="rounded border px-2 py-1"
                                                @click="
                                                    router.post(
                                                        route(
                                                            'governance.rule-versions.review',
                                                            version.id,
                                                        ),
                                                    )
                                                "
                                            >
                                                {{
                                                    ar
                                                        ? "مراجعة مهنية"
                                                        : "Revue professionnelle"
                                                }}
                                            </button>
                                            <button
                                                v-if="
                                                    version.status ===
                                                        'professionally_reviewed' ||
                                                    version.status ===
                                                        'counsel_reviewed'
                                                "
                                                class="rounded border px-2 py-1"
                                                @click="
                                                    router.post(
                                                        route(
                                                            'governance.rule-versions.approve',
                                                            version.id,
                                                        ),
                                                    )
                                                "
                                            >
                                                {{
                                                    ar ? "اعتماد" : "Approuver"
                                                }}
                                            </button>
                                            <button
                                                v-if="
                                                    version.status ===
                                                    'approved'
                                                "
                                                class="rounded border border-amber-500 px-2 py-1"
                                                @click="
                                                    router.post(
                                                        route(
                                                            'governance.rule-versions.activate',
                                                            version.id,
                                                        ),
                                                    )
                                                "
                                            >
                                                {{
                                                    ar
                                                        ? "تنشيط مضبوط"
                                                        : "Activer sous contrôle"
                                                }}
                                            </button>
                                            <button
                                                v-if="
                                                    version.status === 'active'
                                                "
                                                class="rounded border px-2 py-1"
                                                @click="
                                                    router.post(
                                                        route(
                                                            'governance.rule-versions.amend',
                                                            version.id,
                                                        ),
                                                    )
                                                "
                                            >
                                                {{
                                                    ar
                                                        ? "إنشاء تعديل"
                                                        : "Créer un amendement"
                                                }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
