<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ExpenseNavigation from "@/Components/Expenses/ExpenseNavigation.vue";
import { useI18n } from "@/i18n";
const props = defineProps<{ serviceCategories: any[]; supplier?: any }>();
const { locale } = useI18n();
const text = (fr: string, ar: string) => (locale.value === "ar" ? ar : fr);
const form = useForm<any>({
    legal_name: props.supplier?.legal_name || "",
    trade_name: props.supplier?.trade_name || "",
    ice: props.supplier?.ice || "",
    email: props.supplier?.email || "",
    phone: props.supplier?.phone || "",
    address: props.supplier?.address || "",
    category_ids:
        props.supplier?.categories?.map((category: any) => category.id) || [],
    duplicate_warning_reason: "",
});
</script>
<template>
    <AuthenticatedLayout
        :title="
            supplier
                ? text('Modifier le fournisseur', 'تعديل المورد')
                : text('Nouveau fournisseur', 'مورد جديد')
        "
        :subtitle="
            text(
                'Les coordonnées bancaires restent privées',
                'تبقى البيانات البنكية خاصة',
            )
        "
        ><ExpenseNavigation />
        <form
            class="panel grid max-w-3xl gap-4 p-5 md:grid-cols-2"
            @submit.prevent="
                supplier
                    ? form.put(route('suppliers.update', supplier.id))
                    : form.post(route('suppliers.store'))
            "
        >
            <label
                >{{ text("Raison sociale", "الاسم القانوني")
                }}<input
                    v-model="form.legal_name"
                    class="mt-1 w-full rounded-lg border-slate-300" /></label
            ><label
                >{{ text("Nom commercial", "الاسم التجاري")
                }}<input
                    v-model="form.trade_name"
                    class="mt-1 w-full rounded-lg border-slate-300" /></label
            ><label
                >ICE<input
                    v-model="form.ice"
                    class="mt-1 w-full rounded-lg border-slate-300" /></label
            ><label
                >{{ text("E-mail", "البريد الإلكتروني")
                }}<input
                    v-model="form.email"
                    type="email"
                    class="mt-1 w-full rounded-lg border-slate-300" /></label
            ><label
                >{{ text("Téléphone", "الهاتف")
                }}<input
                    v-model="form.phone"
                    class="mt-1 w-full rounded-lg border-slate-300" /></label
            ><label
                >{{ text("Catégories", "الفئات")
                }}<select
                    v-model="form.category_ids"
                    multiple
                    class="mt-1 w-full rounded-lg border-slate-300"
                >
                    <option
                        v-for="c in serviceCategories"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.name }}
                    </option>
                </select></label
            >
            <p
                v-if="Object.keys(form.errors).length"
                class="text-sm text-rose-700 md:col-span-2"
            >
                {{
                    text(
                        "Corrigez les champs signalés; vos valeurs sont conservées.",
                        "صحح الحقول المشار إليها؛ تم الاحتفاظ بالقيم المدخلة.",
                    )
                }}
            </p>
            <button
                class="btn-primary md:col-span-2"
                :disabled="form.processing"
            >
                {{ text("Enregistrer", "حفظ") }}
            </button>
        </form></AuthenticatedLayout
    >
</template>
