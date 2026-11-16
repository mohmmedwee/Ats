<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Row { product_id: string | null, title: { en: string | null, ar: string | null }, image: string | null, units: number, revenue: string, cost: string, profit: string, margin_percent: string | null }
interface Report {
  from: string
  to: string
  currency: string
  totals: { orders: number, units: number, revenue_ex_vat: string, landed_cost: string, payment_fees: string, profit: string, discounts: string, margin_percent: string | null, average_order_profit: string | null }
  daily: { date: string, orders: number, revenue_ex_vat: string, profit: string }[]
  top_products: Row[]
  losing_products: Row[]
}
const { request, run, error, notice } = useAdmin()
const { locale } = useI18n()
const days = (n: number) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10)
const f = reactive({ country: 'AE', from: days(29), to: days(0) })
const data = ref<Report | null>(null)
const hover = ref<number | null>(null)

async function load() {
  await run(async () => {
    data.value = (await request<{ data: Report }>('/admin/finance/profit', { query: { ...f } })).data
  })
}
function preset(n: number) {
  f.from = days(n - 1)
  f.to = days(0)
  load()
}
onMounted(load)

const money = (v: string | number | null) => (v === null ? '—' : formatMoney({ amount: String(v), currency: data.value?.currency ?? 'AED' }, locale.value))
const title = (r: Row) => (locale.value === 'ar' && r.title.ar) || r.title.en || '—'
const shortDate = (d: string) => new Date(d).toLocaleDateString(locale.value === 'ar' ? 'ar-AE' : 'en-GB', { day: 'numeric', month: 'short' })

/** Daily profit bars: one series, zero baseline, losses drawn below it. */
const W = 720
const H = 200
const PAD = { top: 12, bottom: 22 }
const chart = computed(() => {
  const d = data.value?.daily ?? []
  const values = d.map(x => Number(x.profit))
  const max = Math.max(0, ...values)
  const min = Math.min(0, ...values)
  const span = max - min || 1
  const plotH = H - PAD.top - PAD.bottom
  const y = (v: number) => PAD.top + ((max - v) / span) * plotH
  const slot = W / Math.max(1, d.length)
  const bw = Math.max(2, Math.min(28, slot - 2))
  return {
    zero: y(0),
    max,
    bars: d.map((row, i) => {
      const v = values[i]!
      const top = Math.min(y(v), y(0))
      return { row, x: i * slot + (slot - bw) / 2, w: bw, y: top, h: Math.max(v === 0 ? 0 : 1, Math.abs(y(v) - y(0))), neg: v < 0, slotX: i * slot, slotW: slot }
    }),
    labelEvery: Math.ceil(d.length / 8),
  }
})
const tip = computed(() => (hover.value === null ? null : chart.value.bars[hover.value] ?? null))
</script>

<template>
  <section>
    <AdminHeader :title="$t('profit.title')" :subtitle="$t('profit.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />

    <!-- filters: one row above the charts -->
    <div class="card mb-4 flex flex-wrap items-end gap-3 p-3">
      <label><span class="label">{{ $t('profit.country') }}</span>
        <select v-model="f.country" class="input w-28" @change="load"><option value="AE">AE · AED</option><option value="SA">SA · SAR</option></select>
      </label>
      <label><span class="label">{{ $t('admin.common.from') }}</span><input v-model="f.from" type="date" class="input" @change="load"></label>
      <label><span class="label">{{ $t('admin.common.until') }}</span><input v-model="f.to" type="date" class="input" @change="load"></label>
      <div class="flex gap-1">
        <button v-for="n in [7, 30, 90]" :key="n" type="button" class="chip text-xs" @click="preset(n)">{{ $t('profit.last_days', { n }) }}</button>
      </div>
    </div>

    <template v-if="data">
      <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="profit-totals">
        <div class="card p-4">
          <p class="text-xs text-ink-500">{{ $t('profit.profit') }}</p>
          <p class="text-2xl font-black" :class="Number(data.totals.profit) < 0 ? 'text-red-700' : 'text-ink-900'">{{ money(data.totals.profit) }}</p>
          <p class="text-xs text-ink-500">{{ $t('profit.margin', { n: data.totals.margin_percent ?? '—' }) }}</p>
        </div>
        <div class="card p-4">
          <p class="text-xs text-ink-500">{{ $t('profit.revenue') }}</p>
          <p class="text-2xl font-black">{{ money(data.totals.revenue_ex_vat) }}</p>
          <p class="text-xs text-ink-500">{{ $t('profit.orders_units', { o: data.totals.orders, u: data.totals.units }) }}</p>
        </div>
        <div class="card p-4">
          <p class="text-xs text-ink-500">{{ $t('profit.costs') }}</p>
          <p class="text-2xl font-black">{{ money(Number(data.totals.landed_cost) + Number(data.totals.payment_fees)) }}</p>
          <p class="text-xs text-ink-500">{{ $t('profit.cost_split', { goods: money(data.totals.landed_cost), fees: money(data.totals.payment_fees) }) }}</p>
        </div>
        <div class="card p-4">
          <p class="text-xs text-ink-500">{{ $t('profit.per_order') }}</p>
          <p class="text-2xl font-black">{{ money(data.totals.average_order_profit) }}</p>
          <p class="text-xs text-ink-500">{{ $t('profit.discounts', { amount: money(data.totals.discounts) }) }}</p>
        </div>
      </div>

      <div class="card mb-4 p-4" data-test="profit-chart">
        <p class="mb-2 text-sm font-bold">{{ $t('profit.daily') }}</p>
        <div class="relative">
          <svg :viewBox="`0 0 ${W} ${H}`" class="h-52 w-full" preserveAspectRatio="none" role="img" :aria-label="$t('profit.daily')" @mouseleave="hover = null">
            <line :x1="0" :x2="W" :y1="chart.zero" :y2="chart.zero" stroke="#B9B6C2" stroke-width="1" vector-effect="non-scaling-stroke" />
            <g v-for="(b, i) in chart.bars" :key="b.row.date">
              <rect :x="b.slotX" :y="0" :width="b.slotW" :height="H" fill="transparent" @mouseenter="hover = i" />
              <rect :x="b.x" :y="b.y" :width="b.w" :height="b.h" :rx="Math.min(4, b.w / 2)" :fill="b.neg ? '#D7263D' : '#14121A'" :opacity="hover === null || hover === i ? 1 : 0.45" pointer-events="none" />
            </g>
          </svg>
          <div class="flex justify-between text-[10px] text-ink-500" dir="ltr">
            <span v-for="(b, i) in chart.bars.filter((_, k) => k % chart.labelEvery === 0)" :key="i">{{ shortDate(b.row.date) }}</span>
          </div>
          <div v-if="tip" class="pointer-events-none absolute top-0 rounded-md bg-ink-900 px-2.5 py-1.5 text-xs text-white shadow-lg" :style="{ left: `${Math.min(80, (tip.x / W) * 100)}%` }">
            <b>{{ shortDate(tip.row.date) }}</b> · {{ $t('profit.profit') }} {{ money(tip.row.profit) }}<br>{{ $t('profit.revenue') }} {{ money(tip.row.revenue_ex_vat) }} · {{ tip.row.orders }} {{ $t('profit.orders') }}
          </div>
        </div>
        <details class="mt-2 text-xs">
          <summary class="cursor-pointer text-ink-500">{{ $t('profit.table') }}</summary>
          <table class="admin-table mt-2"><thead><tr><th>{{ $t('profit.date') }}</th><th>{{ $t('profit.orders') }}</th><th>{{ $t('profit.revenue') }}</th><th>{{ $t('profit.profit') }}</th></tr></thead>
            <tbody><tr v-for="d in data.daily.filter(x => x.orders)" :key="d.date"><td>{{ d.date }}</td><td>{{ d.orders }}</td><td>{{ money(d.revenue_ex_vat) }}</td><td>{{ money(d.profit) }}</td></tr></tbody>
          </table>
        </details>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <div v-for="list in (['top_products', 'losing_products'] as const)" :key="list" class="card overflow-x-auto" :data-test="list">
          <p class="p-4 pb-2 text-sm font-bold" :class="list === 'losing_products' ? 'text-red-700' : ''">{{ $t(`profit.${list}`) }}</p>
          <table class="admin-table">
            <thead><tr><th>{{ $t('profit.product') }}</th><th>{{ $t('profit.units') }}</th><th>{{ $t('profit.profit') }}</th><th>%</th></tr></thead>
            <tbody>
              <tr v-for="r in data[list]" :key="r.product_id ?? 'x'">
                <td><span class="flex items-center gap-2"><img v-if="r.image" :src="r.image" alt="" class="h-9 w-7 rounded object-cover"><span class="line-clamp-2 max-w-56">{{ title(r) }}</span></span></td>
                <td>{{ r.units }}</td>
                <td class="font-semibold" :class="Number(r.profit) < 0 ? 'text-red-700' : ''">{{ money(r.profit) }}</td>
                <td class="text-xs">{{ r.margin_percent ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!data[list].length" class="p-4 text-center text-sm text-ink-500">{{ list === 'losing_products' ? $t('profit.no_losses') : $t('profit.no_sales') }}</p>
        </div>
      </div>
      <p class="mt-3 text-xs text-ink-500">{{ $t('profit.note') }}</p>
    </template>
  </section>
</template>
