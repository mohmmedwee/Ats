<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Summary {
  settings: { points_per_unit: number, point_value: string, min_redeem: number, referrer_points: number, friend_percent: number, friend_max: string }
  outstanding_points: number
  outstanding_value: string
  totals: Record<'earn' | 'reversal' | 'redeem' | 'referral_bonus' | 'adjustment', number>
  referrals: number
  top: { user_id: string, name: string | null, contact: string | null, balance: number, referral_code: string }[]
}
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const data = ref<Summary | null>(null)
const adjusting = ref<Summary['top'][number] | null>(null)
const f = reactive({ points: 0, note: '' })

async function load() {
  await run(async () => {
    data.value = (await request<{ data: Summary }>('/admin/loyalty')).data
  })
}
async function save() {
  if (!adjusting.value) return
  const ok = await run(() => send('POST', '/admin/loyalty/adjust', { user_id: adjusting.value!.user_id, points: Number(f.points), note: f.note }), t('admin.common.saved'))
  if (ok !== undefined) {
    adjusting.value = null
    await load()
  }
}
function adjust(row: Summary['top'][number]) {
  adjusting.value = row
  Object.assign(f, { points: 0, note: '' })
}
const n = (v: number) => v.toLocaleString(locale.value)
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('rewards.admin_title')" :subtitle="$t('rewards.admin_subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />
    <template v-if="data">
      <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-ink-500">{{ $t('rewards.outstanding') }}</p><p class="text-2xl font-black">{{ n(data.outstanding_points) }}</p><p class="text-xs text-ink-500">≈ {{ Number(data.outstanding_value).toLocaleString(locale) }} AED/SAR</p></div>
        <div class="card p-4"><p class="text-xs text-ink-500">{{ $t('rewards.types.earn') }}</p><p class="text-2xl font-black text-green-700">+{{ n(data.totals.earn + data.totals.referral_bonus) }}</p></div>
        <div class="card p-4"><p class="text-xs text-ink-500">{{ $t('rewards.types.redeem') }}</p><p class="text-2xl font-black">{{ n(-data.totals.redeem) }}</p></div>
        <div class="card p-4"><p class="text-xs text-ink-500">{{ $t('rewards.referrals') }}</p><p class="text-2xl font-black">{{ n(data.referrals) }}</p></div>
      </div>
      <div class="card mb-4 p-4 text-sm text-ink-700">
        <p class="mb-1 font-semibold text-ink-900">{{ $t('rewards.rules') }}</p>
        <p>{{ $t('rewards.rules_text', { n: data.settings.points_per_unit, min: data.settings.min_redeem, amount: (data.settings.min_redeem * Number(data.settings.point_value)).toFixed(2), bonus: data.settings.referrer_points, percent: data.settings.friend_percent, max: data.settings.friend_max }) }}</p>
        <p class="mt-1 text-xs text-ink-500">{{ $t('rewards.rules_env') }}</p>
      </div>
      <div class="card overflow-x-auto">
        <table class="admin-table">
          <thead><tr><th>{{ $t('rewards.customer') }}</th><th>{{ $t('rewards.balance') }}</th><th>{{ $t('rewards.your_code') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in data.top" :key="r.user_id">
              <td><b>{{ r.name || '—' }}</b><span class="block text-xs text-ink-500" dir="ltr">{{ r.contact }}</span></td>
              <td class="font-bold tabular-nums">{{ n(r.balance) }}</td>
              <td class="font-mono text-xs" dir="ltr">{{ r.referral_code }}</td>
              <td class="text-end"><button class="btn-outline btn-sm" @click="adjust(r)">{{ $t('rewards.adjust') }}</button></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!data.top.length" class="p-6 text-center text-ink-500">{{ $t('rewards.no_points_yet') }}</p>
      </div>
    </template>

    <AdminModal :open="!!adjusting" :title="$t('rewards.adjust')" @close="adjusting = null">
      <form class="space-y-3" @submit.prevent="save">
        <p class="text-sm">{{ adjusting?.name }} · {{ $t('rewards.balance') }}: <b>{{ adjusting?.balance }}</b></p>
        <label class="block"><span class="label">{{ $t('rewards.points_signed') }}</span><input v-model.number="f.points" type="number" class="input" dir="ltr" required></label>
        <label class="block"><span class="label">{{ $t('rewards.note') }}</span><input v-model="f.note" class="input" required maxlength="255"></label>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="adjusting = null">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
