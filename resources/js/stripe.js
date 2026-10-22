// Stripe.js helpers — Stripe's secure fields inside our own pages; card numbers never touch our server.

const dark = () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

/** Stripe's fields follow the app theme (client-facing pages are always light). */
export const appearance = {
    get theme() {
        return dark() ? 'night' : 'stripe';
    },
    get variables() {
        return dark()
            ? { colorPrimary: '#8a7dfb', colorBackground: '#121626', colorText: '#eef0f7', borderRadius: '12px', fontFamily: 'Inter, system-ui, sans-serif' }
            : { colorPrimary: '#5b45e8', colorText: '#131729', borderRadius: '12px', fontFamily: 'Inter, system-ui, sans-serif' };
    },
};

// One method per row (no squeezed overflow tab), clean on phones and desktop.
export const paymentLayout = { type: 'accordion', defaultCollapsed: false, radios: true, spacedAccordionItems: false };

export function loadStripeJs() {
    if (window.Stripe) return Promise.resolve(window.Stripe);
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://js.stripe.com/v3/';
        s.onload = () => resolve(window.Stripe);
        s.onerror = () => reject(new Error('Could not load the secure payment form.'));
        document.head.appendChild(s);
    });
}
