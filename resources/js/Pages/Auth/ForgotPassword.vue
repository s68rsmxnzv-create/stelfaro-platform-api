<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormAlert from '@/Components/FormAlert.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Recuperar contraseña" />

        <div class="mb-5">
            <h1 class="text-2xl font-black tracking-tight text-text">¿Olvidaste tu contraseña?</h1>
            <p class="mt-2 text-sm leading-6 text-muted">
                No hay problema. Indícanos tu correo electrónico y te enviaremos un
                enlace para elegir una nueva.
            </p>
        </div>

        <FormAlert variant="success" :message="status" class="mb-4" />

        <form class="space-y-4" @submit.prevent="submit">
            <FormAlert variant="danger" :message="form.errors.email" />

            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />
            </div>

            <div class="flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Enviar enlace para restablecer contraseña
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
