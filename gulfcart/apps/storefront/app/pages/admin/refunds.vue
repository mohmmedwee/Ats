<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface Refund { id: string, order_id: string, amount: string, currency: string, reason_code: string, status: string, requested_by: string | null }
const { request, csrf } = useApi()
const { locale, t } = useI18n()
const auth = useAuthStore()
const refunds = ref<Refund[]>([])
const error = ref('')

async function load() {
  refunds.value = (await request<{ data: Refund[] }>('/admin/refunds')).data
}

async function act(r: Refund, action: 'approve' | 'reject') {
  error.value = ''
  try {
    await csrf()
    await request(`/admin/refunds/${r.id}/${action}`, { method: 'POST', body: {} })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

onMounted(load)
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.refunds') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <table class="w-full text-sm">
      <thead><tr class="border-b"><th class="p-2 text-start">{{ $t('admin.amount') }}</th><th class="text-start">{{ $t('admin.reason') }}</th><th class="text-start">{{ $t('admin.status') }}</th><th /></tr></thead>
      <tbody>
        <tr v-for="r in refunds" :key="r.id" class="border-b">
          <td class="p-2">{{ formatMoney({ amount: r.amount, currency: r.currency }, locale) }}</td>
          <td>{{ r.reason_code }}</td>
          <td><StatusBadge :status="r.status" /></td>
          <td v-if="r.status === 'requested' && auth.can('refunds.approve') && r.requested_by !== auth.user?.id" class="space-x-2">
            <button class="underline" @click="act(r, 'approve')">{{ $t('admin.actions.approve_refund') }}</button>
            <button class="text-red-700 underline" @click="act(r, 'reject')">{{ $t('admin.actions.reject_refund') }}</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="!refunds.length" class="mt-4 text-gray-500">{{ $t('common.none') }}</p>
  </section>
</template>
