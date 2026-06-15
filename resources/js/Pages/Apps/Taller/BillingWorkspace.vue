<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { BillingAppPage } from '@stelfaro/billing';
import { computed, ref } from 'vue';
import TallerNav from '../../../Components/TallerNav.vue';
import PlatformShell from '../../../Layouts/PlatformShell.vue';

const props = defineProps({
    app: {
        type: Object,
        required: true,
    },
    coreBaseUrl: {
        type: String,
        default: '/core-api/v1',
    },
    module: {
        type: String,
        default: 'billing',
    },
    documentSlug: {
        type: String,
        default: 'fe',
    },
    eventSlug: {
        type: String,
        default: 'invalidacion',
    },
    coreSession: {
        type: Object,
        default: null,
    },
    coreSessionError: {
        type: String,
        default: null,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const error = ref(props.coreSessionError || '');
const authToken = ref(props.coreSession?.token || null);

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <PlatformShell active-app="taller" :show-platform-nav="false">
        <template #nav>
            <TallerNav
                :auth-token="authToken"
                :core-base-url="coreBaseUrl"
                :document-slug="documentSlug"
                :event-slug="eventSlug"
                :module="module"
                app-base-url="https://taller.stelfaro.com"
            />
        </template>

        <section v-if="error" class="mx-auto max-w-7xl px-5 py-8">
            <div class="rounded-md border border-red-200 bg-red-50 p-5 text-red-700">
                {{ error }}
            </div>
        </section>

        <BillingAppPage
            v-else
            :app="app"
            :auth-token="authToken"
            :core-base-url="coreBaseUrl"
            :dashboard-url="'https://taller.stelfaro.com'"
            :document-slug="documentSlug"
            :event-slug="eventSlug"
            :module="module"
            :user="user"
            app-base-url="https://taller.stelfaro.com"
            platform-admin-url="https://admin.stelfaro.com"
            @logout="logout"
        />
    </PlatformShell>
</template>
