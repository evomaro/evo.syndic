<script setup lang="ts">
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, usePage } from "@inertiajs/vue3";

defineProps<{ reports: Record<string, any>; notice: string }>();
const ar = usePage<any>().props.locale === "ar";
const labels: Record<string, string> = {
    assemblies: ar ? "الجمعيات" : "Assemblées",
    eligibility: ar ? "الأهلية" : "Éligibilité",
    votes: ar ? "التصويت" : "Votes",
    resolutions: ar ? "القرارات" : "Résolutions",
    evidence: ar ? "الأدلة" : "Preuves",
};
const violationLabels: Record<string, string> = {
    missing_eligibility_snapshot: ar
        ? "لقطة الأهلية مفقودة"
        : "Snapshot d’éligibilité absent",
    stale_eligibility_snapshot: ar
        ? "لقطة الأهلية قديمة"
        : "Snapshot d’éligibilité périmé",
    duplicate_voting_interest: ar
        ? "مصلحة تصويت مكررة"
        : "Intérêt de vote dupliqué",
    invalid_voting_share: ar
        ? "حصة تصويت غير صالحة"
        : "Quote-part de vote invalide",
    voting_opened_without_confirmed_quorum: ar
        ? "فتح التصويت دون نصاب مؤكد"
        : "Vote ouvert sans quorum confirmé",
    active_rule_without_verified_source: ar
        ? "قاعدة نشطة دون مصدر متحقق منه"
        : "Règle active sans source vérifiée",
    overlapping_active_rule_versions: ar
        ? "تداخل نسخ قواعد نشطة"
        : "Chevauchement de versions actives",
    duplicate_ballot: ar ? "ورقة تصويت مكررة" : "Bulletin dupliqué",
    secret_ballot_identity_leakage: ar
        ? "تسريب هوية اقتراع سري"
        : "Identité exposée dans un scrutin secret",
    broken_evidence_checksum: ar
        ? "بصمة دليل غير مطابقة"
        : "Empreinte de preuve incohérente",
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="ar ? 'تدقيق الحوكمة' : 'Audits de gouvernance'" />
        <div class="mx-auto max-w-7xl px-4 py-6">
            <GovernanceNav />
            <h1 class="text-2xl font-bold">
                {{ ar ? "تدقيق الحوكمة" : "Audits de gouvernance" }}
            </h1>
            <p
                class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm"
            >
                {{ notice }}
            </p>
            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <section
                    v-for="(report, kind) in reports"
                    :key="kind"
                    class="rounded-xl border bg-white p-5"
                >
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="font-bold">{{ labels[kind] }}</h2>
                        <span
                            class="rounded-full px-2 py-1 text-xs font-semibold"
                            :class="
                                report.ok
                                    ? 'bg-emerald-100 text-emerald-900'
                                    : 'bg-red-100 text-red-900'
                            "
                        >
                            {{
                                report.ok
                                    ? ar
                                        ? "سليم تقنيا"
                                        : "Conforme techniquement"
                                    : ar
                                      ? "مخالفات"
                                      : "Violations"
                            }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">
                        {{
                            ar
                                ? "السجلات المفحوصة"
                                : "Enregistrements contrôlés"
                        }}: {{ report.checked }}
                    </p>
                    <ul
                        v-if="report.violations.length"
                        class="mt-3 space-y-2 text-sm"
                    >
                        <li
                            v-for="(violation, index) in report.violations"
                            :key="index"
                            class="rounded-lg bg-red-50 p-2"
                        >
                            {{
                                violationLabels[violation.code] ||
                                (ar
                                    ? "مخالفة تقنية تحتاج مراجعة"
                                    : "Violation technique à examiner")
                            }}
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
