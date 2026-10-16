/** Amounts from the server are integers in cents. */
export function money(cents, currency = 'sgd', opts = {}) {
    const value = (Number(cents) || 0) / 100;
    const code = (currency || 'sgd').toUpperCase();
    const formatted = new Intl.NumberFormat('en-SG', {
        style: 'currency',
        currency: code,
        minimumFractionDigits: opts.compact ? 0 : 2,
        maximumFractionDigits: opts.compact ? 0 : 2,
        ...(opts.compact && Math.abs(value) >= 10000 ? { notation: 'compact', maximumFractionDigits: 1 } : {}),
    }).format(value);

    // en-SG prints SGD as a bare "$"; make it unambiguous next to USD ad spend.
    return code === 'SGD' ? formatted.replace('$', 'S$') : formatted;
}

export function date(value, withTime = false) {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleDateString('en-SG', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    });
}

export function clientName(client) {
    if (!client) return '—';
    return client.company || client.name;
}

export const STATUS_STYLES = {
    paid: 'bg-emerald-50 text-emerald-700',
    active: 'bg-emerald-50 text-emerald-700',
    available: 'bg-emerald-50 text-emerald-700',
    open: 'bg-sky-50 text-sky-700',
    trialing: 'bg-sky-50 text-sky-700',
    pending: 'bg-amber-50 text-amber-700',
    in_transit: 'bg-amber-50 text-amber-700',
    incomplete: 'bg-amber-50 text-amber-700',
    past_due: 'bg-rose-50 text-rose-700',
    overdue: 'bg-rose-50 text-rose-700',
    uncollectible: 'bg-rose-50 text-rose-700',
    failed: 'bg-rose-50 text-rose-700',
    draft: 'bg-ink-100 text-ink-600',
    void: 'bg-ink-100 text-ink-500',
    canceled: 'bg-ink-100 text-ink-500',
    incomplete_expired: 'bg-ink-100 text-ink-500',
};

export function invoiceStatus(invoice) {
    if (invoice.status === 'open' && invoice.due_at && new Date(invoice.due_at) < new Date()) {
        return 'overdue';
    }
    return invoice.status;
}

export const PERIODS = [
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'last_3_months', label: 'Last 3 months' },
    { value: 'this_year', label: 'This year' },
    { value: 'last_12_months', label: 'Last 12 months' },
    { value: 'all', label: 'All time' },
    { value: 'custom', label: 'Custom…' },
];

export function copy(text) {
    navigator.clipboard?.writeText(text);
}

/** True when the page was opened from the "Create" menu (?new=1). */
export function wantsNew() {
    return typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('new');
}

export function methodLabel(method) {
    if (!method) return 'Other';
    const [type, brand] = method.split(':');
    const names = { card: 'Card', paynow: 'PayNow', grabpay: 'GrabPay', alipay: 'Alipay', wechat_pay: 'WeChat Pay', link: 'Link', customer_balance: 'Bank transfer', us_bank_account: 'US bank', bank: 'Bank transfer', stripe: 'Stripe balance', cash: 'Cash' };
    const brands = { visa: 'Visa', mastercard: 'Mastercard', amex: 'Amex', unionpay: 'UnionPay', jcb: 'JCB', discover: 'Discover' };
    return (names[type] ?? type) + (brand ? ` · ${brands[brand] ?? brand}` : '');
}
