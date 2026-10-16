<script setup>
import Icon from '@/Components/Icon.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    step: { type: String, required: true },
    requirements: { type: Array, required: true },
    database: { type: Object, required: true },
    appUrl: { type: String, default: '' },
});

const steps = [
    { key: 'code', label: 'Setup code' },
    { key: 'database', label: 'Database' },
    { key: 'admin', label: 'Admin account' },
    { key: 'business', label: 'Business & Stripe' },
];
const current = computed(() => steps.findIndex((s) => s.key === props.step));
const allOk = computed(() => props.requirements.every((r) => r.ok));
const flash = computed(() => usePage().props.flash?.success);

const headings = {
    code: ['Set up Klok Ads console', 'Enter the one-time setup code to start. Your developer can get it from the server.'],
    database: ['Connect the database', 'Use the MySQL database you created in hPanel → Databases.'],
    admin: ['Create your admin account', 'This is the login for the whole console. The password is stored as a secure hash.'],
    business: ['Business & Stripe', 'Name your business and optionally connect Stripe now — you can also do it later in Settings.'],
};

const codeForm = useForm({ code: '' });
const dbForm = useForm({ ...props.database, password: '' });
const adminForm = useForm({ name: '', email: '', password: '', password_confirmation: '' });
const businessForm = useForm({ name: 'Klok Ads', currency: 'SGD', stripe_secret: '' });
</script>

<template>
    <GuestLayout :title="headings[step][0]" :subtitle="headings[step][1]">
        <Head title="Install" />

        <!-- Progress -->
        <ol class="mb-8 grid grid-cols-4 gap-2">
            <li v-for="(s, i) in steps" :key="s.key">
                <div class="h-1.5 rounded-full" :class="i < current ? 'bg-emerald-500' : i === current ? 'bg-brand-600' : 'bg-ink-100'"></div>
                <div class="mt-1.5 text-[11px] font-semibold" :class="i === current ? 'text-ink-900' : 'text-ink-400'">{{ i + 1 }}. {{ s.label }}</div>
            </li>
        </ol>

        <div v-if="flash" class="mb-5 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
            <Icon name="check" :size="16" /> {{ flash }}
        </div>

        <!-- 1. Setup code -->
        <form v-if="step === 'code'" class="space-y-5" @submit.prevent="codeForm.post(route('install.code'))">
            <div>
                <label class="label" for="code">Setup code</label>
                <input id="code" v-model="codeForm.code" class="input text-center font-mono text-lg uppercase tracking-[0.3em]" placeholder="XXXX-XXXX" autocomplete="off" autofocus required />
                <div v-if="codeForm.errors.code" class="error">{{ codeForm.errors.code }}</div>
            </div>

            <div class="rounded-xl border border-line p-4">
                <div class="mb-2 text-xs font-semibold text-ink-700">Server check</div>
                <ul class="space-y-1.5">
                    <li v-for="r in requirements" :key="r.label" class="flex items-center gap-2 text-xs" :class="r.ok ? 'text-ink-600' : 'font-semibold text-rose-600'">
                        <Icon :name="r.ok ? 'check' : 'alert'" :size="14" :class="r.ok ? 'text-emerald-500' : 'text-rose-500'" /> {{ r.label }}
                    </li>
                </ul>
            </div>

            <button class="btn-primary w-full py-3" :disabled="codeForm.processing || !allOk">Continue</button>
            <p v-if="!allOk" class="text-center text-xs text-rose-600">Fix the red items on the server first.</p>
        </form>

        <!-- 2. Database -->
        <form v-else-if="step === 'database'" class="space-y-4" @submit.prevent="dbForm.post(route('install.database'))">
            <div v-if="dbForm.errors.database" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ dbForm.errors.database }}</div>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="label">Host</label>
                    <input v-model="dbForm.host" class="input font-mono" required />
                </div>
                <div>
                    <label class="label">Port</label>
                    <input v-model="dbForm.port" class="input font-mono" required />
                </div>
            </div>
            <div>
                <label class="label">Database name</label>
                <input v-model="dbForm.database" class="input font-mono" placeholder="u123456789_name" required />
            </div>
            <div>
                <label class="label">Database user</label>
                <input v-model="dbForm.username" class="input font-mono" placeholder="u123456789_name" required />
            </div>
            <div>
                <label class="label">Database password</label>
                <input v-model="dbForm.password" type="password" class="input font-mono" autocomplete="new-password" />
                <div v-if="dbForm.errors.password" class="error">{{ dbForm.errors.password }}</div>
                <div class="hint">Saved only in the server's private .env file (outside the website folder).</div>
            </div>
            <button class="btn-primary w-full py-3" :disabled="dbForm.processing">{{ dbForm.processing ? 'Connecting & creating tables…' : 'Connect database' }}</button>
        </form>

        <!-- 3. Admin -->
        <form v-else-if="step === 'admin'" class="space-y-4" @submit.prevent="adminForm.post(route('install.admin'))">
            <div>
                <label class="label">Your name</label>
                <input v-model="adminForm.name" class="input" required autofocus />
                <div v-if="adminForm.errors.name" class="error">{{ adminForm.errors.name }}</div>
            </div>
            <div>
                <label class="label">Email (your login)</label>
                <input v-model="adminForm.email" type="email" class="input" required autocomplete="username" />
                <div v-if="adminForm.errors.email" class="error">{{ adminForm.errors.email }}</div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Password</label>
                    <input v-model="adminForm.password" type="password" class="input" required autocomplete="new-password" />
                    <div v-if="adminForm.errors.password" class="error">{{ adminForm.errors.password }}</div>
                </div>
                <div>
                    <label class="label">Confirm password</label>
                    <input v-model="adminForm.password_confirmation" type="password" class="input" required autocomplete="new-password" />
                </div>
            </div>
            <div class="hint">At least 10 characters.</div>
            <button class="btn-primary w-full py-3" :disabled="adminForm.processing">Create admin</button>
        </form>

        <!-- 4. Business -->
        <form v-else class="space-y-4" @submit.prevent="businessForm.post(route('install.business'))">
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="label">Business name</label>
                    <input v-model="businessForm.name" class="input" required />
                </div>
                <div>
                    <label class="label">Currency</label>
                    <input v-model="businessForm.currency" maxlength="3" class="input uppercase" required />
                </div>
            </div>
            <div>
                <label class="label">Stripe secret key <span class="font-normal text-ink-400">(optional)</span></label>
                <input v-model="businessForm.stripe_secret" type="password" class="input font-mono" placeholder="sk_test_… or sk_live_…" autocomplete="off" />
                <div v-if="businessForm.errors.stripe_secret" class="error">{{ businessForm.errors.stripe_secret }}</div>
                <div class="hint">Checked with Stripe, then stored encrypted. Start with a test key.</div>
            </div>
            <button class="btn-primary w-full py-3" :disabled="businessForm.processing">{{ businessForm.processing ? 'Finishing…' : 'Finish setup' }}</button>
        </form>
    </GuestLayout>
</template>
