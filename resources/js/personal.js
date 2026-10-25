// Shared look for the personal side: category emoji + colours, money helpers, greeting.
import { money } from '@/money';
import { ref, watch } from 'vue';

/** Spending / income categories → emoji and a soft colour (Tailwind classes, dark-mode aware). */
export const CATEGORY = {
    food: ['🍛', 'bg-orange-100 text-orange-700'],
    home: ['🏠', 'bg-amber-100 text-amber-700'],
    bills: ['💡', 'bg-yellow-100 text-yellow-700'],
    transport: ['🚕', 'bg-sky-100 text-sky-700'],
    family: ['👨‍👩‍👧', 'bg-rose-100 text-rose-700'],
    staff_salary: ['🧑‍🔧', 'bg-indigo-100 text-indigo-700'],
    shopping: ['🛍️', 'bg-fuchsia-100 text-fuchsia-700'],
    health: ['💊', 'bg-emerald-100 text-emerald-700'],
    education: ['📚', 'bg-indigo-100 text-indigo-700'],
    entertainment: ['🎬', 'bg-violet-100 text-violet-700'],
    travel: ['✈️', 'bg-cyan-100 text-cyan-700'],
    savings: ['🐷', 'bg-teal-100 text-teal-700'],
    loan: ['🏦', 'bg-slate-100 text-slate-700'],
    charity: ['💝', 'bg-lime-100 text-lime-700'],
    sadaqah: ['🤲', 'bg-emerald-100 text-emerald-700'],
    bank_fees: ['🧾', 'bg-slate-100 text-slate-600'],
    other: ['✨', 'bg-slate-100 text-slate-600'],
    // money in
    salary: ['💼', 'bg-emerald-100 text-emerald-700'],
    work: ['🧑‍💻', 'bg-emerald-100 text-emerald-700'],
    gift: ['🎁', 'bg-pink-100 text-pink-700'],
    investment: ['📈', 'bg-teal-100 text-teal-700'],
    other_income: ['💰', 'bg-emerald-100 text-emerald-700'],
    // dena-paona
    loan_given: ['🤝', 'bg-amber-100 text-amber-700'],
    loan_taken: ['🤝', 'bg-amber-100 text-amber-700'],
    loan_repaid_to_me: ['🤝', 'bg-emerald-100 text-emerald-700'],
    loan_repaid_by_me: ['🤝', 'bg-rose-100 text-rose-700'],
    transfer: ['🔁', 'bg-brand-100 text-brand-700'],
    interest: ['🏦', 'bg-teal-100 text-teal-700'],
    // business
    agro: ['🌾', 'bg-lime-100 text-lime-700'],
    meta_ads: ['📣', 'bg-sky-100 text-sky-700'],
    google_ads: ['🔎', 'bg-amber-100 text-amber-700'],
    tiktok_ads: ['🎵', 'bg-fuchsia-100 text-fuchsia-700'],
    seo: ['🔗', 'bg-indigo-100 text-indigo-700'],
    software: ['💻', 'bg-slate-100 text-slate-700'],
    office: ['🏢', 'bg-slate-100 text-slate-700'],
    tax: ['🧾', 'bg-rose-100 text-rose-700'],
    client_payment: ['🤝', 'bg-emerald-100 text-emerald-700'],
    stripe_payout: ['💳', 'bg-indigo-100 text-indigo-700'],
    owner_capital: ['🏦', 'bg-teal-100 text-teal-700'],
};

export const cat = (key) => CATEGORY[key] ?? CATEGORY.other;

/** "৳1,250.50" for BDT (lakh grouping, with poisha), "S$12.50" for SGD. */
export function cash(cents, currency = 'BDT') {
    const c = (currency || 'BDT').toUpperCase();
    if (c === 'BDT') return (cents < 0 ? '−' : '') + '৳' + (Math.abs(cents) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (cents < 0 ? '−' : '') + money(Math.abs(cents), c);
}

export function greeting(date = new Date()) {
    const h = date.getHours();
    return h < 5 ? 'Good night' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

/** "Today", "Yesterday", or "Mon, 21 Oct". */
export function dayLabel(iso) {
    const d = new Date(iso + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diff = Math.round((today - d) / 864e5);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
}

/** A toast right away, without waiting for the server (works offline too). */
export function say(text, type = 'success') {
    window.dispatchEvent(new CustomEvent('klok-toast', { detail: { text, type } }));
}

/** Opens the quick-add sheet from anywhere on the personal side. */
export function quickAdd(detail = {}) {
    window.dispatchEvent(new CustomEvent('klok-quick-add', { detail }));
}

/** The SGD / BDT switch, remembered on this device across personal pages. */
export function currencyChoice(list, fallback) {
    let saved = null;
    try {
        saved = localStorage.getItem('klok-personal-currency');
    } catch {
        /* private mode */
    }
    const pick = ref(list.includes(saved) ? saved : fallback ?? list[0]);
    watch(pick, (c) => {
        try {
            localStorage.setItem('klok-personal-currency', c);
        } catch {
            /* private mode */
        }
    });
    return pick;
}

/** How an entry reads in a list: title, signed amount and its tone. */
export function describe(t, label) {
    const title = t.kind === 'transfer'
        ? (t.direction === 'in' ? `From ${t.account?.name}` : t.direction === 'move' ? `${t.account?.name} → ${t.to_account?.name}` : `To ${t.to_account?.name}`)
        : t.counterparty || label(t.category);
    let amount;
    if (t.kind === 'transfer' && t.direction === 'in') amount = { cents: t.to_amount, currency: t.to_account?.currency, sign: '+', tone: 'text-emerald-600' };
    else if (t.direction === 'in') amount = { cents: t.amount - t.fee, currency: t.account?.currency, sign: '+', tone: 'text-emerald-600' };
    else if (t.direction === 'move') amount = { cents: t.amount, currency: t.account?.currency, sign: '', tone: 'text-ink-500' };
    else amount = { cents: t.amount + (t.kind === 'expense' ? t.fee : 0), currency: t.account?.currency, sign: '−', tone: 'text-ink-900' };
    return { title, amount: { ...amount, text: amount.sign + cash(amount.cents, amount.currency) } };
}
