<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Refund { id: string, order_id: string, amount: string, currency: string, reason_code: string, reason: string | null, status: string, requested_by: string | null, failure_reason: string | null, created_at: string }
const { request, send, run, error, notice } = useAdmin()
const { locale, t } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const refunds = ref<Refund[]>([])
const status = ref('requested')
async function load() {
  await run(async () => {
    refunds.value = (await request<{ data: Refund[] }>('/admin/refunds', { query: { status: status.value || undefined, per_page: 100 } })).data
  })
}
async function act(r: Refund, action: 'approve' | 'reject') {
  if (action === 'reject' && !confirm(t('admin.common.confirm'))) return
  await run(() => send('POST', `/admin/refunds/${r.id}/${action}`, {}), t('admin.common.done'))
  await load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.refunds')" :subtitle="$t('admin.refunds_page.subtitle')">
      <select v-model="status" class="input w-auto" @change="load">
        <option value="requested">{{ $t('admin.refunds_page.status.requested') }}</option>
        <option value="">{{ $t('admin.common.all') }}</option>
        <option v-for="s in ['approved', 'processing', 'succeeded', 'failed', 'rejected']" :key="s" :value="s">{{ $t(`admin.refunds_page.status.${s}`) }}</option>
      </select>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.date') }}</th><th>{{ $t('admin.order') }}</th><th class="text-end">{{ $t('admin.amount') }}</th><th>{{ $t('admin.reason') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in refunds" :key="r.id">
            <td class="whitespace-nowrap text-ink-500">{{ formatDateTime(r.created_at, locale) }}</td>
            <td><NuxtLink :to="localePath(`/admin/orders/${r.order_id}`)" class="link">{{ $t('admin.common.open') }}</NuxtLink></td>
            <td class="whitespace-nowrap text-end font-semibold">{{ formatMoney({ amount: r.amount, currency: r.currency }, locale) }}</td>
            <td>{{ $t(`admin.refunds_page.reasons.${r.reason_code}`) }}<span v-if="r.reason" class="block text-xs text-ink-500">{{ r.reason }}</span><span v-if="r.failure_reason" class="block text-xs text-red-600">{{ r.failure_reason }}</span></td>
            <td><StatusBadge :status="r.status" /></td>
            <td class="whitespace-nowrap text-end">
              <template v-if="r.status === 'requested' && auth.can('refunds.approve')">
                <span v-if="r.requested_by === auth.user?.id" class="text-xs text-ink-500">{{ $t('admin.refunds_page.four_eyes') }}</span>
                <template v-else>
                  <button class="btn-primary btn-sm" @click="act(r, 'approve')">{{ $t('admin.actions.approve_refund') }}</button>
                  <button class="btn-outline btn-sm ms-2" @click="act(r, 'reject')">{{ $t('admin.actions.reject_refund') }}</button>
                </template>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!refunds.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
  </section>
</template>
