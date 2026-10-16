<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

const submit = () => {
    form.post(route('register'), { onFinish: () => form.reset('password', 'password_confirmation') });
};
</script>

<template>
    <GuestLayout title="Create the owner account" subtitle="This is a one-time setup. Registration closes after the first account.">
        <Head title="Set up" />

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Your name" />
                <TextInput id="name" v-model="form.name" type="text" required autofocus autocomplete="name" />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" required autocomplete="username" />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <InputLabel for="password" value="Password" />
                    <TextInput id="password" v-model="form.password" type="password" required autocomplete="new-password" />
                    <InputError :message="form.errors.password" />
                </div>
                <div>
                    <InputLabel for="password_confirmation" value="Confirm password" />
                    <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
                    <InputError :message="form.errors.password_confirmation" />
                </div>
            </div>

            <button class="btn-primary w-full py-3" :disabled="form.processing">
                {{ form.processing ? 'Creating…' : 'Create account' }}
            </button>

            <p class="text-center text-sm text-ink-500">
                Already set up? <Link :href="route('login')" class="font-semibold text-brand-600 hover:text-brand-800">Sign in</Link>
            </p>
        </form>
    </GuestLayout>
</template>
