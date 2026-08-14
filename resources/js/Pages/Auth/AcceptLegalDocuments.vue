<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { UiButton, UiPasswordInput } from '@stelfaro/ui';
import { ExternalLink, FileCheck2, ShieldCheck } from 'lucide-vue-next';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    documents: {
        type: Array,
        required: true,
    },
    acceptanceVersion: {
        type: String,
        required: true,
    },
    acceptanceText: {
        type: String,
        required: true,
    },
});

const form = useForm({
    current_password: '',
    accept_terms: false,
    accept_privacy: false,
    authority_confirmed: false,
    document_versions: Object.fromEntries(
        props.documents.map((document) => [document.type, document.version]),
    ),
});

const acceptedField = (type) => type === 'terms' ? 'accept_terms' : 'accept_privacy';

const submit = () => {
    form.post(route('legal.acceptance.store'), {
        preserveScroll: true,
        onFinish: () => form.reset('current_password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Aceptación legal" />

        <div class="mb-5 flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary">
                <ShieldCheck class="h-6 w-6" aria-hidden="true" />
            </span>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Último paso</p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-text">Revisa y acepta</h1>
                <p class="mt-1 text-sm leading-6 text-muted">
                    Para ingresar a <strong class="font-semibold text-text">{{ tenant.name }}</strong> en ambiente de producción.
                </p>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <fieldset class="space-y-3">
                <legend class="sr-only">Documentos legales vigentes</legend>

                <label
                    v-for="document in documents"
                    :key="`${document.type}-${document.version}`"
                    class="flex cursor-pointer gap-3 rounded-xl border border-line bg-surface-raised p-3 transition hover:border-line-strong"
                >
                    <input
                        v-model="form[acceptedField(document.type)]"
                        type="checkbox"
                        class="mt-1 h-5 w-5 shrink-0 rounded border-line-strong bg-surface text-primary focus:ring-primary-soft"
                        required
                    />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start gap-2">
                            <FileCheck2 class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                            <span class="text-sm font-bold text-text">Acepto {{ document.title }}</span>
                        </span>
                        <span class="mt-1 block text-xs leading-5 text-muted">
                            Versión {{ document.version }} · vigente desde {{ document.effective_at }}
                        </span>
                        <a
                            :href="document.url"
                            target="_blank"
                            rel="noopener"
                            class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary-hover"
                            @click.stop
                        >
                            Leer documento completo
                            <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    </span>
                </label>
            </fieldset>

            <InputError :message="form.errors.accept_terms" />
            <InputError :message="form.errors.accept_privacy" />
            <InputError :message="form.errors.document_versions" />

            <label class="flex cursor-pointer gap-3 rounded-xl border border-line p-3">
                <input
                    v-model="form.authority_confirmed"
                    type="checkbox"
                    class="mt-1 h-5 w-5 shrink-0 rounded border-line-strong bg-surface text-primary focus:ring-primary-soft"
                    required
                />
                <span class="text-xs leading-5 text-muted">{{ acceptanceText }}</span>
            </label>
            <InputError :message="form.errors.authority_confirmed" />

            <div class="border-t border-line pt-4">
                <UiPasswordInput
                    id="current_password"
                    v-model="form.current_password"
                    label="Confirma con tu contraseña nueva"
                    autocomplete="current-password"
                    required
                    autofocus
                />
                <p class="mt-1.5 text-xs leading-5 text-soft">
                    Se verificará para confirmar tu identidad; la contraseña no se guarda en esta aceptación.
                </p>
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <UiButton
                type="submit"
                class="w-full"
                :disabled="form.processing || !form.accept_terms || !form.accept_privacy || !form.authority_confirmed || !form.current_password"
            >
                {{ form.processing ? 'Confirmando…' : 'Aceptar y continuar' }}
            </UiButton>

            <p class="text-center text-[11px] leading-4 text-soft">
                Registro de aceptación {{ acceptanceVersion }}
            </p>
        </form>
    </GuestLayout>
</template>
