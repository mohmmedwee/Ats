<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Log { id: string, user_id: string | null, action: string, entity_type: string, entity_id: string | null, old_values: unknown, new_values: unknown, ip_address: string | null, request_id: string | null, created_at: string }
const { request, run, error } = useAdmin()
const { locale } = useI18n()
const logs = ref<Log[]>([])
const filters = reactive({ action: '', entity_type: '', page: 1 })
const pager = ref({ page: 1, last_page: 1, total: 0 })
const open = ref<string | null>(null)
async function load() {
  await run(async () => {
    const res = await request<{ data: Log[], meta: { pagination: typeof pager.value } }>('/admin/audit-logs', { query: { action: filters.action || undefined, entity_type: filters.entity_type || undefined, page: filters.page } })
    logs.value = res.data
    pager.value = res.meta.pagination
  })
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.audit.title')" :subtitle="$t('admin.audit.subtitle')" />
    <AdminAlerts :error="error" />
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="filters.page = 1; load()">
      <label class="flex-1"><span class="label">{{ $t('admin.audit.action') }}</span><input v-model="filters.action" class="input" dir="ltr" placeholder="refund. / product. / user."></label>
      <label class="flex-1"><span class="label">{{ $t('admin.audit.entity') }}</span><input v-model="filters.entity_type" class="input" dir="ltr" placeholder="order, product, supplier…"></label>
      <button class="btn-dark">{{ $t('admin.common.filter') }}</button>
    </form>
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.date') }}</th><th>{{ $t('admin.audit.action') }}</th><th>{{ $t('admin.audit.entity') }}</th><th>{{ $t('admin.audit.actor') }}</th><th>IP</th></tr></thead>
        <tbody>
          <template v-for="l in logs" :key="l.id">
            <tr class="cursor-pointer" @click="open = open === l.id ? null : l.id">
              <td class="whitespace-nowrap text-ink-500">{{ formatDateTime(l.created_at, locale) }}</td>
              <td class="font-mono text-xs" dir="ltr">{{ l.action }}</td>
              <td class="text-xs" dir="ltr">{{ l.entity_type }} <span class="text-ink-500">{{ l.entity_id?.slice(0, 8) }}</span></td>
              <td class="font-mono text-xs text-ink-500" dir="ltr">{{ l.user_id?.slice(0, 8) ?? 'system' }}</td>
              <td class="text-xs text-ink-500" dir="ltr">{{ l.ip_address }}</td>
            </tr>
            <tr v-if="open === l.id">
              <td colspan="5" class="bg-ink-100/50">
                <div class="grid gap-3 text-xs md:grid-cols-2" dir="ltr">
                  <div><p class="mb-1 font-semibold">before</p><pre class="overflow-x-auto whitespace-pre-wrap">{{ JSON.stringify(l.old_values, null, 2) }}</pre></div>
                  <div><p class="mb-1 font-semibold">after</p><pre class="overflow-x-auto whitespace-pre-wrap">{{ JSON.stringify(l.new_values, null, 2) }}</pre></div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p v-if="!logs.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
    <AdminPager :page="pager.page" :last-page="pager.last_page" :total="pager.total" @go="p => { filters.page = p; load() }" />
  </section>
</template>
