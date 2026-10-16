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

/** @returns {{net:number, charge:number, processing_fee:number, card_fee:number, payout_fee:number, commission_rate:number, commission:number, ad_budget:number}} */
export function breakdown(net, p, passFees = true) {
    const charge = passFees ? grossUp(net, p) : net;
    const cardFee = Math.round((charge * p.card_fee_percent) / 100) + p.card_fee_fixed;
    const received = passFees ? net : netOf(charge, p);
    const commission = Math.round((received * p.commission_rate) / 100);

    return {
        net: received,
        charge,
        processing_fee: charge - net,
        card_fee: cardFee,
        payout_fee: charge - cardFee - received,
        commission_rate: p.commission_rate,
        commission,
        ad_budget: received - commission,
    };
}
