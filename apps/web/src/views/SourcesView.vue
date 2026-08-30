<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'

import { ApiError, api, type DiscoveryReport } from '@/api/client'

const queryClient = useQueryClient()
const report = ref<DiscoveryReport | null>(null)
const formError = ref<string | null>(null)

const kind = ref('greenhouse')
const name = ref('')
const configValue = ref('')

const { data: sources, isPending } = useQuery({ queryKey: ['sources'], queryFn: api.sources })
const { data: kinds } = useQuery({ queryKey: ['source-kinds'], queryFn: api.sourceKinds })

const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sources'] })

/** The single config key each board needs, e.g. board_token for Greenhouse. */
const configKey = computed(
  () => kinds.value?.kinds.find((k) => k.kind === kind.value)?.required_config[0] ?? 'board_token',
)

const create = useMutation({
  mutationFn: () => api.createSource(kind.value, name.value, { [configKey.value]: configValue.value }),
  onMutate: () => {
    formError.value = null
  },
  onSuccess: () => {
    name.value = ''
    configValue.value = ''
    void invalidate()
  },
  onError: (error) => {
    formError.value = error instanceof ApiError ? error.message : 'Could not add the source'
  },
})

const toggle = useMutation({
  mutationFn: (source: { id: string; enabled: boolean }) =>
    api.updateSource(source.id, { enabled: !source.enabled }),
  onSuccess: () => void invalidate(),
})

const resetFailures = useMutation({
  mutationFn: (id: string) => api.updateSource(id, { reset_failures: true }),
  onSuccess: () => void invalidate(),
})

const remove = useMutation({
  mutationFn: (id: string) => api.deleteSource(id),
  onSuccess: () => void invalidate(),
})

const run = useMutation({
  mutationFn: (sourceId?: string) => api.runDiscovery(sourceId),
  onSuccess: (result) => {
    report.value = result
    void invalidate()
    void queryClient.invalidateQueries({ queryKey: ['jobs'] })
  },
})
</script>

<template>
  <section>
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold">Sources</h1>
        <p class="mt-1 text-sm text-slate-600">
          Public job-board APIs. A source that keeps failing backs off on its own rather than
          being retried every run.
        </p>
      </div>
      <button
        class="shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
        :disabled="run.isPending.value"
        @click="run.mutate(undefined)"
      >
        {{ run.isPending.value ? 'Running…' : 'Run discovery' }}
      </button>
    </div>

    <!-- add a source ------------------------------------------------------->
    <form
      class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4"
      @submit.prevent="create.mutate()"
    >
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Board</span>
        <select v-model="kind" class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
          <option v-for="option in kinds?.supported ?? []" :key="option" :value="option">
            {{ option }}
          </option>
        </select>
      </label>
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Name</span>
        <input
          v-model="name"
          required
          placeholder="northwind"
          class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">{{ configKey }}</span>
        <input
          v-model="configValue"
          required
          placeholder="northwind"
          class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
        />
      </label>
      <button
        type="submit"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50"
        :disabled="create.isPending.value"
      >
        Add source
      </button>
      <p v-if="formError" class="w-full text-sm text-red-600">{{ formError }}</p>
    </form>

    <!-- run report ---------------------------------------------------------->
    <div v-if="report" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
      <h2 class="text-sm font-semibold">Last run</h2>
      <ul class="mt-2 space-y-1 text-sm">
        <li v-for="result in report.results" :key="result.source_id">
          <span class="font-medium">{{ result.source_name }}</span>
          <span v-if="result.error" class="text-red-600"> — {{ result.error }}</span>
          <span v-else-if="result.skipped" class="text-slate-500"> — skipped</span>
          <span v-else class="text-slate-600">
            — {{ result.created }} new, {{ result.updated }} updated,
            {{ result.duplicates_linked }} linked as possible duplicates
            <span v-if="result.injection_flagged" class="text-amber-700">
              , {{ result.injection_flagged }} flagged
            </span>
          </span>
        </li>
      </ul>
    </div>

    <!-- sources ------------------------------------------------------------->
    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading sources…</p>
    <p v-else-if="!sources?.length" class="mt-6 text-sm text-slate-500">
      No sources yet. Add a board above to start discovering roles.
    </p>
    <ul v-else class="mt-6 space-y-2">
      <li
        v-for="source in sources"
        :key="source.id"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3"
      >
        <div>
          <p class="text-sm font-medium">
            {{ source.name }}
            <span class="ml-2 font-mono text-xs text-slate-500">{{ source.kind }}</span>
            <span v-if="!source.enabled" class="ml-2 text-xs text-slate-500">disabled</span>
          </p>
          <p class="text-xs text-slate-500">
            <span v-if="source.last_success_at">
              Last success {{ new Date(source.last_success_at).toLocaleString() }}
            </span>
            <span v-else>Never run</span>
            <span v-if="source.consecutive_failures" class="text-red-600">
              · {{ source.consecutive_failures }} consecutive failures
            </span>
          </p>
          <p v-if="source.last_error" class="mt-1 text-xs text-red-600">{{ source.last_error }}</p>
          <p v-if="source.paused_until" class="text-xs text-amber-700">
            Backing off until {{ new Date(source.paused_until).toLocaleString() }}
          </p>
        </div>
        <div class="flex shrink-0 gap-2">
          <button
            class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
            @click="run.mutate(source.id)"
          >
            Run
          </button>
          <button
            v-if="source.consecutive_failures"
            class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
            @click="resetFailures.mutate(source.id)"
          >
            Clear backoff
          </button>
          <button
            class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
            @click="toggle.mutate(source)"
          >
            {{ source.enabled ? 'Disable' : 'Enable' }}
          </button>
          <button
            class="rounded-md border border-slate-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50"
            @click="remove.mutate(source.id)"
          >
            Remove
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>
