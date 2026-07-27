<script setup lang="ts">
import { computed, ref } from "vue";

const props = withDefaults(
    defineProps<{ attachments?: any[]; busy?: boolean; error?: string }>(),
    { attachments: () => [], busy: false },
);
const emit = defineEmits<{
    upload: [
        payload: {
            file: File;
            reusable_on_renewal: boolean;
            replaces_id: number | null;
        },
    ];
}>();
const file = ref<File | null>(null);
const reusable = ref(false);
const replacesId = ref<number | null>(null);
const fileLabel = computed(() =>
    file.value
        ? `${file.value.name} · ${(file.value.size / 1024 / 1024).toFixed(2)} Mo`
        : "PDF, image ou document bureautique · 20 Mo max.",
);
const submit = () =>
    file.value &&
    emit("upload", {
        file: file.value,
        reusable_on_renewal: reusable.value,
        replaces_id: replacesId.value,
    });
</script>
<template>
    <div
        class="grid gap-3 rounded-lg border border-dashed border-slate-400 p-4"
    >
        <label
            class="flex min-h-11 cursor-pointer flex-col items-center justify-center text-center text-sm font-medium"
        >
            <input
                type="file"
                class="sr-only"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                @change="(e: any) => (file = e.target.files?.[0] || null)"
            />
            Choisir une pièce privée
            <span class="mt-1 font-normal text-slate-500">{{ fileLabel }}</span>
        </label>
        <label class="flex min-h-11 items-center gap-2 text-sm"
            ><input v-model="reusable" type="checkbox" /> Réutiliser lors d’un
            renouvellement</label
        >
        <label v-if="attachments.length" class="grid gap-1 text-sm"
            >Remplacer une version existante
            <select v-model="replacesId" class="rounded-lg border-slate-300">
                <option :value="null">Nouvelle pièce</option>
                <option
                    v-for="item in attachments"
                    :key="item.id"
                    :value="item.id"
                >
                    v{{ item.version }} · {{ item.name }}
                </option>
            </select>
        </label>
        <p v-if="error" class="text-sm text-rose-700" role="alert">
            {{ error }}
        </p>
        <button
            type="button"
            class="btn-secondary"
            :disabled="!file || busy"
            @click="submit"
        >
            {{ busy ? "Téléversement…" : "Téléverser" }}
        </button>
    </div>
</template>
