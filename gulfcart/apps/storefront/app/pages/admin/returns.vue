<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface ReturnRow { id: string, rma_number: string, order_id: string, status: string, reason_code: string, customer_note: string | null, items: { quantity: number }[], refund: { amount: string, currency: string } | null }
const { request, csrf, idempotencyKey } = useApi()
const { t, locale } = useI18n()
const auth = useAuthStore()
const rows = ref<ReturnRow[]>([])
const error = ref('')

async function load() {
  rows.value = (await request<{ data: ReturnRow[] }>('/admin/returns')).data
}

async function act(r: ReturnRow, action: 'approve' | 'reject' | 'receive' | 'refund') {
  error.value = ''
  try {
    await csrf()
    await request(`/admin/returns/${r.id}/${action}`, { method: 'POST', body: {}, headers: { 'Idempotency-Key': idempotencyKey(`ret-${action}`) } })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

const next: Record<string, ('approve' | 'reject' | 'receive' | 'refund')[]> = { requested: ['approve', 'reject'], approved: ['receive'], received: ['refund', 'reject'] }
onMounted(load)
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.returns') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <table class="w-full text-sm">
      <tbody>
        <tr v-for="r in rows" :key="r.id" class="border-b">
          <td class="p-2" dir="ltr">{{ r.rma_number }}</td>
          <td><StatusBadge :status="r.status" /></td>
          <td>{{ $t(`orders.reasons.${r.reason_code}`) }} · {{ r.items.reduce((n, i) => n + i.quantity, 0) }}</td>
          <td>{{ r.refund ? formatMoney(r.refund, locale) : '' }}</td>
          <td v-if="auth.can('refunds.request')" class="space-x-2">
            <button v-for="a in next[r.status] ?? []" :key="a" class="underline" @click="act(r, a)">
              {{ a === 'approve' ? $t('admin.actions.approve_refund') : a === 'reject' ? $t('admin.actions.reject_refund') : $t(`admin.${a}`) }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!rows.length" class="mt-4 text-gray-500">{{ $t('common.none') }}</p>
  </section>
</template>
