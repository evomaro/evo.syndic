<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import GovernanceNav from "@/Components/Governance/GovernanceNav.vue";
import InputError from "@/Components/InputError.vue";
import { useForm, usePage } from "@inertiajs/vue3";
defineProps<{ exercises: any[]; mandates: any[] }>();
const ar = usePage<any>().props.locale === "ar";
const f = useForm({
    type: "ordinary",
    financial_exercise_id: "",
    governance_mandate_id: "",
    convening_authority: "Syndic de la copropriété",
    meeting_date: "",
    starts_at: "18:00",
    expected_ends_at: "20:00",
    location: "",
});
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'إعداد جمعية' : 'Préparer une assemblée'"
        :subtitle="
            ar
                ? 'المرحلة الأولى: الهوية والموعد'
                : 'Étape 1 · Identité et calendrier'
        "
        ><GovernanceNav />
        <form
            @submit.prevent="f.post(route('governance.store'))"
            class="mx-auto grid max-w-3xl gap-4 rounded-2xl border bg-white p-5 sm:grid-cols-2"
        >
            <label class="text-sm font-semibold"
                >{{ ar ? "النوع" : "Type"
                }}<select
                    v-model="f.type"
                    class="mt-1 w-full rounded-xl border-slate-300"
                >
                    <option value="ordinary">Ordinaire</option>
                    <option value="extraordinary">Extraordinaire</option>
                    <option value="constitutive">Constitutive</option>
                </select></label
            ><label class="text-sm font-semibold"
                >{{ ar ? "الولاية" : "Mandat de convocation"
                }}<select
                    v-model="f.governance_mandate_id"
                    required
                    class="mt-1 w-full rounded-xl border-slate-300"
                >
                    <option value="">—</option>
                    <option v-for="m in mandates" :key="m.id" :value="m.id">
                        {{ m.role }} · {{ m.starts_on }} → {{ m.ends_on }}
                    </option></select
                ><InputError :message="f.errors.governance_mandate_id" /></label
            ><label class="text-sm font-semibold sm:col-span-2"
                >{{ ar ? "الجهة الداعية" : "Autorité de convocation"
                }}<input
                    v-model="f.convening_authority"
                    required
                    class="mt-1 w-full rounded-xl border-slate-300" /></label
            ><label class="text-sm font-semibold"
                >{{ ar ? "التاريخ" : "Date"
                }}<input
                    v-model="f.meeting_date"
                    required
                    type="date"
                    class="mt-1 w-full rounded-xl border-slate-300" /></label
            ><label class="text-sm font-semibold"
                >{{ ar ? "البداية" : "Début"
                }}<input
                    v-model="f.starts_at"
                    required
                    type="time"
                    class="mt-1 w-full rounded-xl border-slate-300" /></label
            ><label class="text-sm font-semibold"
                >{{ ar ? "النهاية المتوقعة" : "Fin prévue"
                }}<input
                    v-model="f.expected_ends_at"
                    type="time"
                    class="mt-1 w-full rounded-xl border-slate-300" /></label
            ><label class="text-sm font-semibold"
                >{{ ar ? "السنة المالية" : "Exercice financier"
                }}<select
                    v-model="f.financial_exercise_id"
                    class="mt-1 w-full rounded-xl border-slate-300"
                >
                    <option value="">—</option>
                    <option v-for="e in exercises" :key="e.id" :value="e.id">
                        {{ e.name }} · {{ e.status }}
                    </option>
                </select></label
            ><label class="text-sm font-semibold sm:col-span-2"
                >{{ ar ? "المكان" : "Lieu"
                }}<input
                    v-model="f.location"
                    required
                    class="mt-1 w-full rounded-xl border-slate-300" /></label
            ><InputError
                class="sm:col-span-2"
                :message="Object.values(f.errors)[0]"
            /><button
                :disabled="f.processing"
                class="rounded-xl bg-teal-700 px-5 py-3 font-bold text-white sm:col-span-2"
            >
                {{
                    ar
                        ? "إنشاء ومتابعة الإعداد"
                        : "Créer et poursuivre la préparation"
                }}
            </button>
        </form></AuthenticatedLayout
    >
</template>
