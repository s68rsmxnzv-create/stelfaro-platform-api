<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormAlert from '@/Components/FormAlert.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Verificar correo electrónico" />

        <div class="mb-4 text-sm leading-6 text-muted">
            ¡Gracias por registrarte! Antes de empezar, verifica tu correo
            electrónico con el enlace que acabamos de enviarte. Si no lo
            recibiste, con gusto te enviamos otro.
        </div>

        <FormAlert
            variant="success"
            :message="verificationLinkSent ? 'Te enviamos un nuevo enlace de verificación al correo que indicaste durante el registro.' : ''"
            class="mb-4"
        />

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Reenviar correo de verificación
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-muted underline transition hover:text-text focus:outline-none focus:ring-2 focus:ring-primary-soft focus:ring-offset-2"
                    >Cerrar sesión</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
