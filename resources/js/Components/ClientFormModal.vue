<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({ show: Boolean, client: { type: Object, default: null } });
const emit = defineEmits(['close']);

const blank = { name: '', company: '', email: '', phone: '', country: 'SG', notes: '' };
const form = useForm({ ...blank });

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.defaults(props.client ? { ...blank, ...props.client } : { ...blank });
            form.reset();
            form.clearErrors();
        }
    },
    { immediate: true },
);

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.client ? form.put(route('clients.update', props.client.id), opts) : form.post(route('clients.store'), opts);
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="flex items-start justify-between border-b border-line px-6 py-5">
                <div>
                    <h2 class="text-lg font-bold text-ink-900">{{ client ? 'Edit client' : 'New client' }}</h2>
                    <p class="mt-0.5 text-sm text-ink-500">Saved here and as a customer in your Stripe account.</p>
                </div>
                <button type="button" class="icon-btn" @click="emit('close')"><Icon name="close" /></button>
            </div>

            <div class="grid grid-cols-2 gap-4 px-6 py-5">
                <div class="col-span-2 sm:col-span-1">
                    <label class="label">Contact name *</label>
                    <input v-model="form.name" class="input" required />
                    <div v-if="form.errors.name" class="error">{{ form.errors.name }}</div>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label">Company</label>
                    <input v-model="form.company" class="input" placeholder="Lion City Bakery Pte Ltd" />
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label">Billing email *</label>
                    <input v-model="form.email" type="email" class="input" required />
                    <div v-if="form.errors.email" class="error">{{ form.errors.email }}</div>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label">Phone / WhatsApp</label>
                    <input v-model="form.phone" class="input" placeholder="+65 9123 4567" />
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label">Country</label>
                    <input v-model="form.country" maxlength="2" class="input uppercase" />
                    <div class="hint">2-letter code, e.g. SG, MY</div>
                    <div v-if="form.errors.country" class="error">{{ form.errors.country }}</div>
                </div>
                <div class="col-span-2">
                    <label class="label">Notes</label>
                    <textarea v-model="form.notes" rows="2" class="input" placeholder="Ad accounts, contract terms, anything useful…"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
                <button class="btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : client ? 'Save changes' : 'Add client' }}</button>
            </div>
        </form>
    </Modal>
</template>
