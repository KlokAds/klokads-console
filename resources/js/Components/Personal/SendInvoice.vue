<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import { say as toast } from '@/personal';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

/**
 * Send an invoice the proper way: by email (from, to, cc, subject, a written message, the PDF and/or
 * the link) or by WhatsApp (a ready message with the link; in the app, the PDF shared as a file too).
 */
const props = defineProps({ invoice: { type: Object, default: null } });
const emit = defineEmits(['close']);

const via = ref('email');
const c = ref(null); // what the window starts with, from the server
const loading = ref(false);
const mail = useForm({ to: '', cc: '', subject: '', body: '', attach: true, link: true });
const wa = ref({ phone: '', text: '' });
const sharing = ref(false);

watch(() => props.invoice, async (inv) => {
    if (!inv) return;
    loading.value = true;
    c.value = null;
    try {
        const r = await fetch(route('personal.invoices.compose', inv.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        c.value = await r.json();
        mail.defaults({ to: c.value.to ?? '', cc: '', subject: c.value.subject, body: c.value.body, attach: true, link: true });
        mail.reset();
        mail.clearErrors();
        wa.value = { phone: c.value.phone ?? '', text: c.value.whatsapp };
        via.value = c.value.mail_ready && c.value.to ? 'email' : 'whatsapp';
    } finally {
        loading.value = false;
    }
}, { immediate: true });

const sendMail = () => mail.post(route('personal.invoices.send', props.invoice.id), { preserveScroll: true, onSuccess: () => emit('close') });

const noted = () => fetch(route('personal.invoices.whatsapp', props.invoice.id), {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '') },
    body: JSON.stringify({ to: wa.value.phone }),
}).then(() => router.reload());

/** WhatsApp with the message ready (the link opens the invoice and its PDF). */
function openWhatsApp() {
    const number = (wa.value.phone || '').replace(/\D/g, '');
    window.open(`https://wa.me/${number}?text=${encodeURIComponent(wa.value.text)}`, '_blank');
    noted();
}

/** The PDF itself with the message: the app's share sheet (pick WhatsApp), or the browser's. */
async function sharePdf() {
    sharing.value = true;
    try {
        if (window.flutter_inappwebview?.callHandler) {
            const ok = await window.flutter_inappwebview.callHandler('shareFile', { url: c.value.pdf, name: c.value.file, text: wa.value.text });
            if (ok !== false) noted();
            return;
        }
        const blob = await (await fetch(c.value.pdf)).blob();
        const file = new File([blob], c.value.file, { type: 'application/pdf' });
        if (navigator.canShare?.({ files: [file] })) {
            await navigator.share({ files: [file], text: wa.value.text });
            noted();
        } else {
            // No file sharing here: save the PDF, then WhatsApp with the message.
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: c.value.file });
            a.click();
            toast('PDF saved — attach it in WhatsApp.');
            openWhatsApp();
        }
    } catch (e) {
        if (e?.name !== 'AbortError') toast('Could not share the PDF.', 'error');
    } finally {
        sharing.value = false;
    }
}
</script>

<template>
    <Modal :show="!!invoice" max-width="lg" @close="emit('close')">
        <div v-if="invoice" class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-ink-900">Send {{ invoice.number }}</h2>
                    <p v-if="c?.sent" class="mt-0.5 text-xs text-ink-500">Sent before by {{ c.sent.via === 'whatsapp' ? 'WhatsApp' : 'email' }}{{ c.sent.to ? ' to ' + c.sent.to : '' }}</p>
                </div>
                <button class="icon-btn" aria-label="Close" @click="emit('close')"><Icon name="close" /></button>
            </div>

            <div class="mt-4"><Segmented v-model="via" :options="[{ value: 'email', label: '✉️ Email' }, { value: 'whatsapp', label: '💬 WhatsApp' }]" block /></div>
            <p v-if="loading" class="mt-6 text-sm text-ink-500">Getting it ready…</p>

            <!-- Email -->
            <form v-else-if="c && via === 'email'" class="mt-4 space-y-3" @submit.prevent="sendMail">
                <div v-if="!c.mail_ready" class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                    Email isn't set up yet — <Link :href="route('settings.edit', { tab: 'email' })" class="font-semibold underline">Settings → Email</Link>. Until then, use WhatsApp.
                </div>
                <div class="grid grid-cols-[3.5rem_1fr] items-center gap-x-3 gap-y-2 text-sm">
                    <span class="text-ink-500">From</span><span class="truncate text-ink-700">{{ c.from.name }} &lt;{{ c.from.address }}&gt;</span>
                    <label class="text-ink-500" for="s-to">To</label><input id="s-to" v-model="mail.to" type="email" class="input" required />
                    <label class="text-ink-500" for="s-cc">CC</label><input id="s-cc" v-model="mail.cc" class="input" placeholder="Optional — commas between emails" />
                    <label class="text-ink-500" for="s-sub">Subject</label><input id="s-sub" v-model="mail.subject" class="input" required />
                </div>
                <div v-if="mail.errors.to || mail.errors.cc" class="error">{{ mail.errors.to || mail.errors.cc }}</div>
                <textarea v-model="mail.body" rows="9" class="input leading-relaxed"></textarea>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex items-center justify-between gap-3 rounded-2xl bg-ink-50 px-3 py-2.5 text-sm text-ink-700">📎 Attach the PDF <Toggle v-model="mail.attach" label="Attach PDF" /></label>
                    <label class="flex items-center justify-between gap-3 rounded-2xl bg-ink-50 px-3 py-2.5 text-sm text-ink-700">🔗 “View & pay” button <Toggle v-model="mail.link" label="Add link" /></label>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
                    <button class="btn-primary" :disabled="mail.processing || !c.mail_ready || !mail.to"><Icon name="send" :size="16" /> Send email</button>
                </div>
            </form>

            <!-- WhatsApp -->
            <div v-else-if="c && via === 'whatsapp'" class="mt-4 space-y-3">
                <div>
                    <label class="label" for="s-wa">Their WhatsApp number</label>
                    <input id="s-wa" v-model="wa.phone" class="input tabular-nums" inputmode="tel" placeholder="8801XXXXXXXXX" />
                </div>
                <textarea v-model="wa.text" rows="10" class="input leading-relaxed"></textarea>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button class="flex items-center justify-center gap-2 rounded-2xl bg-[#25D366] px-4 py-3 font-bold text-white shadow-sm hover:brightness-95" @click="openWhatsApp">
                        💬 Send on WhatsApp
                    </button>
                    <button class="flex items-center justify-center gap-2 rounded-2xl bg-surface px-4 py-3 font-bold text-ink-900 ring-1 ring-line hover:ring-teal-300" :disabled="sharing" @click="sharePdf">
                        <Icon name="download" :size="17" /> {{ sharing ? 'Getting the PDF…' : 'Share PDF + message' }}
                    </button>
                </div>
                <p class="text-xs text-ink-500">“Send on WhatsApp” opens their chat with the message and link. “Share PDF + message” sends the invoice file too — pick WhatsApp, then the chat.</p>
            </div>
        </div>
    </Modal>
</template>
