/**
 * Mirror of App\Support\Pricing for live previews in forms (amounts in cents).
 * The server recalculates on submit; this only shows the numbers as you type.
 */
export function netOf(charge, p) {
    const afterCard = charge - Math.round((charge * p.card_fee_percent) / 100) - p.card_fee_fixed;
    return afterCard - Math.round((afterCard * p.payout_fee_percent) / 100);
}

export function grossUp(net, p) {
    const pct = p.card_fee_percent / 100;
    const q = p.payout_fee_percent / 100;
    let charge = Math.ceil((net / (1 - q) + p.card_fee_fixed) / (1 - pct));
    while (netOf(charge, p) < net) charge++;
    while (charge > net && netOf(charge - 1, p) >= net) charge--;
    return charge;
}

const taxOn = (amount, p) => Math.round((amount * (p.tax_rate || 0)) / 100);

function processingFeeFor(net, p) {
    if (!p.tax_rate) return grossUp(net, p) - net;
    const leaves = (fee) => {
        const tax = taxOn(net, p) + taxOn(fee, p);
        return netOf(net + fee + tax, p) - tax >= net;
    };
    const r = 1 + p.tax_rate / 100;
    let fee = Math.max(0, Math.floor((grossUp(Math.round(net * r), p) - net * r) / r) - 5);
    while (!leaves(fee)) fee++;
    while (fee > 0 && leaves(fee - 1)) fee--;
    return fee;
}

/** @returns {{net:number, charge:number, processing_fee:number, card_fee:number, payout_fee:number, tax:number, tax_rate:number, subtotal:number, commission_rate:number, commission:number, ad_budget:number}} */
export function breakdown(net, p, passFees = true) {
    const fee = passFees ? processingFeeFor(net, p) : 0;
    const tax = taxOn(net, p) + taxOn(fee, p);
    const charge = net + fee + tax;
    const cardFee = Math.round((charge * p.card_fee_percent) / 100) + p.card_fee_fixed;
    const received = passFees ? net : netOf(charge, p) - tax;
    const commission = Math.round((received * p.commission_rate) / 100);

    return {
        net: received,
        charge,
        processing_fee: fee,
        card_fee: cardFee,
        payout_fee: passFees ? charge - cardFee - received - tax : charge - cardFee - netOf(charge, p),
        tax,
        tax_rate: p.tax_rate || 0,
        subtotal: net + fee,
        commission_rate: p.commission_rate,
        commission,
        ad_budget: received - commission,
    };
}

/** "Commission only": the client paid the platform; we bill only our share of that spend. */
export function commissionOnly(spend, p, passFees = true) {
    const commission = Math.round((spend * p.commission_rate) / 100);
    // Commission is always the full share of the spend; card fees we absorb are our own cost.
    return { ...breakdown(commission, p, passFees), commission, ad_budget: 0, client_spend: spend, mode: 'commission' };
}

/** What a form will charge for its billing type (budget, commission or a plain amount). */
export function priced(billing, cents, p, passFees = true) {
    if (!p || !cents) return null;
    // Commission only: the client always pays the card fees, so the full commission reaches us.
    if (billing === 'commission') return commissionOnly(cents, p, true);
    if (billing === 'budget') return breakdown(cents, p, passFees);
    return null;
}
