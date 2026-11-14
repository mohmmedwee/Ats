<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface AdminProduct {
  id: string
  slug: string
  title: { en?: string | null, ar?: string | null }
  status: string
  availability: { country: string, is_sellable: boolean, unsellable_reason: string | null, from_price: { amount: string, currency: string } | null }[]
}
const { request, csrf } = useApi()
const { locale, t } = useI18n()
const auth = useAuthStore()
const products = ref<AdminProduct[]>([])
const status = ref('')
const error = ref('')

async function load() {
  products.value = (await request<{ data: AdminProduct[] }>('/admin/products', { query: { status: status.value || undefined } })).data
}

async function transition(p: AdminProduct, action: 'submit' | 'publish' | 'unpublish') {
  error.value = ''
  try {
    await csrf()
    await request(`/admin/products/${p.id}/${action}`, { method: 'POST' })
    await load()
  }
  catch (e) {
    const data = (e as { data?: { error?: { details?: { problems?: string[] } } } }).data
    error.value = data?.error?.details?.problems?.join(', ') ?? apiErrorMessage(e, t('errors.generic'))
  }
}

onMounted(load)
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.products') }}</h1>
    <select v-model="status" class="mb-4 rounded border px-2 py-1" @change="load">
      <option value="">all</option>
      <option v-for="s in ['draft', 'pending_review', 'published', 'unpublished', 'archived']" :key="s" :value="s">{{ s }}</option>
    </select>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <table class="w-full text-sm">
      <tbody>
        <tr v-for="p in products" :key="p.id" class="border-b">
          <td class="p-2">{{ (locale === 'ar' ? p.title.ar : p.title.en) ?? p.title.en }}</td>
          <td><StatusBadge :status="p.status" /></td>
          <td>
            <span v-for="a in p.availability" :key="a.country" class="me-2" :title="a.unsellable_reason ?? ''">
              {{ a.country }}: {{ a.is_sellable ? formatMoney(a.from_price, locale) : (a.unsellable_reason ?? '—') }}
            </span>
          </td>
          <td class="space-x-2 whitespace-nowrap">
            <button v-if="p.status === 'draft'" class="underline" @click="transition(p, 'submit')">{{ $t('admin.actions.submit') }}</button>
            <button v-if="p.status === 'pending_review' && auth.can('catalog.publish')" class="underline" @click="transition(p, 'publish')">{{ $t('admin.actions.publish') }}</button>
            <button v-if="p.status === 'published' && auth.can('catalog.publish')" class="underline" @click="transition(p, 'unpublish')">{{ $t('admin.actions.unpublish') }}</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
