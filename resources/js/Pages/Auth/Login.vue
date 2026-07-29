<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { UiButton, UiEmailInput, UiPasswordInput } from '@stelfaro/ui';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Iniciar sesión" />

        <div v-if="status" class="mb-5 rounded-xl bg-success-soft px-4 py-3 text-sm font-medium text-success">
            {{ status }}
        </div>

        <div class="mb-7">
            <h1 class="text-3xl font-black tracking-tight text-text">Inicia sesión</h1>
            <p class="mt-2 text-sm leading-6 text-muted">
                Accede a la operación de tu negocio con tu cuenta StelFaro.
            </p>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <UiEmailInput
                    id="email"
                    v-model="form.email"
                    label="Correo electrónico"
                    placeholder="nombre@empresa.com"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <UiPasswordInput
                    id="password"
                    v-model="form.password"
                    label="Contraseña"
                    required
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between gap-4">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-muted">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-5 w-5 rounded border-line-strong bg-surface-raised text-sky-600 focus:ring-sky-200 dark:focus:ring-primary-soft"
                    />
                    Recordar sesión
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-right text-sm font-semibold text-primary transition hover:text-primary-hover"
                >
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <UiButton
                type="submit"
                class="w-full"
                :disabled="form.processing"
            >
                <svg v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                    <path class="opacity-75" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z" />
                </svg>
                {{ form.processing ? 'Ingresando…' : 'Ingresar a StelFaro' }}
            </UiButton>
        </form>

        <p class="mt-7 border-t border-line pt-5 text-center text-xs leading-5 text-soft">
            ¿Necesitas ayuda para ingresar?
            <a href="mailto:soporte@stelfaro.com" class="font-semibold text-primary hover:text-primary-hover">Contacta a soporte</a>
        </p>
    </GuestLayout>
</template>
