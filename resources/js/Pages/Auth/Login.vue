<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({ email: '', password: '', remember: false });

const submit = () => {
    form.post(route('login'), { onFinish: () => form.reset('password') });
};
</script>

<template>
    <GuestLayout title="Welcome back" subtitle="Sign in to your Klok Ads cashflow dashboard.">
        <Head title="Log in" />

        <div v-if="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ status }}</div>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" required autofocus autocomplete="username" placeholder="you@klokads.com" />
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <InputLabel for="password" value="Password" />
                    <Link v-if="canResetPassword" :href="route('password.request')" class="mb-1.5 text-xs font-semibold text-brand-600 hover:text-brand-800">Forgot password?</Link>
                </div>
                <TextInput id="password" v-model="form.password" type="password" required autocomplete="current-password" placeholder="••••••••" />
                <InputError :message="form.errors.password" />
            </div>

            <label class="flex items-center gap-2.5 text-sm text-ink-600">
                <Checkbox v-model:checked="form.remember" name="remember" />
                Keep me signed in
            </label>

            <button class="btn-primary w-full py-3" :disabled="form.processing">
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>
    </GuestLayout>
</template>
