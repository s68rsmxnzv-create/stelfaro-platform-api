<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FormAlert from '@/Components/FormAlert.vue';
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
        <Head title="Cambiar contraseña" />

        <div class="mb-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-primary">Primer inicio</p>
            <h1 class="mt-2 text-2xl font-black tracking-tight text-text">Crea tu contraseña personal</h1>
            <p class="mt-2 text-sm leading-6 text-muted">
                Tu usuario se creó con una contraseña temporal. Cámbiala antes de entrar a tus apps.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <FormAlert variant="danger" :message="form.errors.current_password" />

            <div>
                <InputLabel for="current_password" value="Contraseña temporal" />
                <TextInput
                    id="current_password"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="current-password"
                />
            </div>

            <div>
                <InputLabel for="password" value="Nueva contraseña" />
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

            <div>
                <InputLabel for="password_confirmation" value="Confirmar contraseña" />
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

            <div class="flex justify-end">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Guardar y continuar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
