<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import MaintenanceNav from "@/Components/Maintenance/MaintenanceNav.vue";
import Pagination from "@/Components/Pagination.vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import { reactive } from "vue";
const props = defineProps<{ requests: any; filters: any; options: any }>();
const page = usePage<any>();
const ar = page.props.locale === "ar";
const filters = reactive({
    q: props.filters.q ?? "",
    status: props.filters.status ?? "",
    priority: props.filters.priority ?? "",
    maintenance_category_id: props.filters.maintenance_category_id ?? "",
});
const search = () =>
    router.get(route("maintenance.requests.index"), filters, {
        preserveState: true,
        replace: true,
    });
</script>
<template>
    <AuthenticatedLayout
        :title="ar ? 'طلبات الصيانة' : 'Demandes de maintenance'"
        ><MaintenanceNav />
        <form
            class="mb-5 grid gap-3 rounded-2xl border bg-white p-4 sm:grid-cols-4"
            @submit.prevent="search"
        >
            <input
                v-model="filters.q"
                :placeholder="ar ? 'بحث' : 'Recherche'"
                class="rounded-xl border-slate-300"
            /><select
                v-model="filters.status"
                class="rounded-xl border-slate-300"
            >
                <option value="">
                    {{ ar ? "كل الحالات" : "Tous les statuts" }}
                </option>
                <option
                    v-for="s in [
                        'draft',
                        'submitted',
                        'under_review',
                        'approved',
                        'in_progress',
                        'resolved',
                        'closed',
                        'rejected',
                        'cancelled',
                    ]"
                    :key="s"
                >
                    {{ s }}
                </option></select
            ><select
                v-model="filters.priority"
                class="rounded-xl border-slate-300"
            >
                <option value="">
                    {{ ar ? "كل الأولويات" : "Toutes priorités" }}
                </option>
                <option
                    v-for="s in ['low', 'normal', 'high', 'urgent']"
                    :key="s"
                >
                    {{ s }}
                </option></select
            ><button class="rounded-xl bg-slate-950 px-4 py-2 text-white">
                {{ ar ? "تصفية" : "Filtrer" }}
            </button>
        </form>
        <div class="overflow-hidden rounded-2xl border bg-white">
            <div
                v-if="!requests.data.length"
                class="p-10 text-center text-slate-500"
            >
                {{ ar ? "لا توجد طلبات" : "Aucune demande" }}
            </div>
            <Link
                v-for="item in requests.data"
                :key="item.id"
                :href="route('maintenance.requests.show', item.id)"
                class="grid gap-2 border-b p-4 hover:bg-slate-50 sm:grid-cols-[9rem_1fr_9rem_8rem]"
                ><span class="font-mono text-sm font-bold text-teal-800">{{
                    item.reference
                }}</span
                ><span
                    ><b>{{ item.title }}</b
                    ><small class="block text-slate-500">{{
                        ar ? item.category.name_ar : item.category.name_fr
                    }}</small></span
                ><span class="text-sm">{{ item.status }}</span
                ><span class="text-sm font-semibold">{{
                    item.priority
                }}</span></Link
            >
        </div>
        <Pagination class="mt-5" :links="requests.links" />
    </AuthenticatedLayout>
</template>
