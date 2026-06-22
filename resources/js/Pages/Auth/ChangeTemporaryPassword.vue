<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.temporary.update'), {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Cambiar contrasena" />

        <div class="mb-6">
            <p class="text-sm font-semibold uppercase tracking-wide text-blue-700">Primer inicio</p>
            <h1 class="mt-2 text-2xl font-bold text-[#0d1629]">Crea tu contrasena personal</h1>
            <p class="mt-2 text-sm text-slate-600">
                Tu usuario fue creado con una contrasena temporal. Cambiala antes de entrar a tus apps.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="current_password" value="Contrasena temporal" />
                <TextInput
                    id="current_password"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Nueva contrasena" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirmar contrasena" />
                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="mt-5 flex justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Guardar y continuar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
