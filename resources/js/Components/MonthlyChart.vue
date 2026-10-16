<script setup>
import { money } from '@/money';
import { BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend);
Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';

const props = defineProps({
    months: { type: Array, required: true },
    currency: { type: String, default: 'sgd' },
});

const canvas = ref(null);
let chart = null;

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const label = (ym) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-SG', { month: 'short', year: '2-digit' });

function render() {
    chart?.destroy();
    const ink = css('--ink-muted');
    chart = new Chart(canvas.value, {
        data: {
            labels: props.months.map((m) => label(m.month)),
            datasets: [
                {
                    type: 'line',
                    label: 'Profit',
                    data: props.months.map((m) => m.profit / 100),
                    borderColor: css('--series-3'),
                    backgroundColor: css('--series-3'),
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    tension: 0.25,
                    order: 0,
                },
                {
                    type: 'bar',
                    label: 'Net received (after Stripe fees)',
                    data: props.months.map((m) => m.net / 100),
                    backgroundColor: css('--series-1'),
                    borderRadius: 4,
                    maxBarThickness: 22,
                    order: 1,
                },
                {
                    type: 'bar',
                    label: 'Expenses',
                    data: props.months.map((m) => m.expenses / 100),
                    backgroundColor: css('--series-2'),
                    borderRadius: 4,
                    maxBarThickness: 22,
                    order: 1,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            datasets: { bar: { categoryPercentage: 0.7, barPercentage: 0.9 } },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 8, color: ink, font: { size: 12 } } },
                tooltip: {
                    backgroundColor: '#0b1020',
                    padding: 12,
                    cornerRadius: 10,
                    boxPadding: 4,
                    usePointStyle: true,
                    titleFont: { family: 'Inter', weight: '600', size: 12 },
                    bodyFont: { family: 'Inter', size: 12 },
                    footerFont: { family: 'Inter', size: 11 },
                    callbacks: {
                        label: (ctx) => ` ${ctx.dataset.label}: ${money(Math.round(ctx.parsed.y * 100), props.currency)}`,
                        afterBody: (items) => {
                            const m = props.months[items[0].dataIndex];
                            return [`Gross: ${money(m.gross, props.currency)}`, `Stripe fees: ${money(m.fees, props.currency)}`];
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: ink, font: { size: 11 } }, border: { color: css('--grid') } },
                y: {
                    grid: { color: css('--grid') },
                    border: { display: false },
                    ticks: { color: ink, font: { size: 11 }, callback: (v) => money(v * 100, props.currency, { compact: true }) },
                },
            },
        },
    });
}

onMounted(render);
watch(() => props.months, render);
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div class="h-72"><canvas ref="canvas" role="img" aria-label="Monthly net received, expenses and profit"></canvas></div>
</template>
