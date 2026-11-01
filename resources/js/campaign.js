import { date } from '@/money';

const TONES = {
    good: ['bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'bg-emerald-500'],
    info: ['bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', 'bg-sky-500'],
    warn: ['bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'bg-amber-500'],
    bad: ['bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'bg-rose-500'],
    off: ['bg-ink-100 text-ink-600', 'bg-ink-400'],
};

const isoDay = (daysAgo = 0) => new Date(Date.now() - daysAgo * 86400000).toISOString().slice(0, 10);

/**
 * What a campaign is really doing (Meta keeps old ones "ACTIVE" for ever), in words, with what to do
 * when it needs a hand. `group` sorts the Campaigns page: running, look (needs a look), off.
 *
 * @returns {{label: string, tone: string, pill: string, dot: string, note: ?string, group: string}}
 */
export function campaignState(c) {
    const today = isoDay();
    const make = (label, tone, group, note = null) => ({ label, tone, pill: TONES[tone][0], dot: TONES[tone][1], note, group });
    const s = c.status;
    if (s === 'ACTIVE') {
        if (c.end && c.end < today) return make('Ended', 'off', 'off', `Its last day was ${date(c.end)}. Set a later end date to run it again.`);
        if (c.spend > 0) return make('Running', 'good', 'running');
        if (c.start && c.start >= isoDay(2)) return make('Just started', 'info', 'running', 'It started lately — spend and results show within a few hours.');
        // Spent before, but not in this period: an old campaign Meta still calls ACTIVE.
        if (c.last_spend && c.last_spend < isoDay(30)) return make('Not delivering', 'off', 'off', `Last spent ${date(c.last_spend)}. Pause it to tidy up, or check it in Ads Manager to run it again.`);
        return make('On · not spending', 'warn', 'look', 'It is on but spent nothing lately. Usually its ad set or ads are off, an ad is in review or rejected, or the payment method failed — check it in Ads Manager.');
    }
    if (s === 'DISAPPROVED') return make('Rejected', 'bad', 'look', 'Meta rejected an ad in it. Open Ads Manager to see why, fix the ad and send it for review again.');
    if (s === 'WITH_ISSUES') return make('Has issues', 'warn', 'look', 'Meta flags a problem with it. Open Ads Manager to see what to fix.');
    if (s === 'PENDING_REVIEW' || s === 'IN_PROCESS') return make('In review', 'info', 'running', 'Meta is reviewing its ads — usually done within 24 hours.');
    if (s === 'ADSET_PAUSED') return make('Ad set paused', 'off', 'off');
    if (s === 'ARCHIVED') return make('Archived', 'off', 'off');
    return make(s ? 'Paused' : '—', 'off', 'off');
}

/** Opens the campaign (or the account) in Meta's Ads Manager. */
export function adsManagerUrl(account, campaignId = null) {
    if (!account) return null;
    return `https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${String(account).replace(/^act_/, '')}${campaignId ? `&selected_campaign_ids=${campaignId}` : ''}`;
}
