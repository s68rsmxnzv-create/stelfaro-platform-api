<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormAlert from '@/Components/FormAlert.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirmar contraseña" />

        <div class="mb-5">
            <h1 class="text-2xl font-black tracking-tight text-text">Confirma tu identidad</h1>
            <p class="mt-2 text-sm leading-6 text-muted">
                Esta es un área segura. Vuelve a ingresar tu contraseña para continuar.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <FormAlert variant="danger" :message="form.errors.password" />

            <div>
                <InputLabel for="password" value="Contraseña" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
            </div>

            <div class="flex justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Confirmar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
