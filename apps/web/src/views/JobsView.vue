<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'

import { api, type JobFilters } from '@/api/client'

const search = ref('')
const country = ref('')
const remoteType = ref('')
const includeDuplicates = ref(false)
const offset = ref(0)
const limit = 25

const filters = computed<JobFilters>(() => ({
  q: search.value || undefined,
  country: country.value || undefined,
  remote_type: remoteType.value || undefined,
  include_duplicates: includeDuplicates.value,
  limit,
  offset: offset.value,
}))

const { data, isPending, isError } = useQuery({
  queryKey: ['jobs', filters],
  queryFn: () => api.jobs(filters.value),
})

const resetPaging = () => {
  offset.value = 0
}

const showing = computed(() => {
  const total = data.value?.total ?? 0
  if (!total) return '0'
  return `${offset.value + 1}–${Math.min(offset.value + limit, total)} of ${total}`
})
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">Discovered jobs</h1>
    <p class="mt-1 text-sm text-slate-600">
      Normalised across boards and deduplicated. Scoring against your profile arrives in phase 3.
    </p>

    <div class="mt-6 flex flex-wrap items-end gap-3">
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Search</span>
        <input
          v-model="search"
          placeholder="title or description"
          class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          @input="resetPaging"
        />
      </label>
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Country</span>
        <input
          v-model="country"
          placeholder="Jordan"
          class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          @input="resetPaging"
        />
      </label>
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Location type</span>
        <select
          v-model="remoteType"
          class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
          @change="resetPaging"
        >
          <option value="">Any</option>
          <option value="remote">Remote</option>
          <option value="hybrid">Hybrid</option>
          <option value="onsite">On-site</option>
        </select>
      </label>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="includeDuplicates" type="checkbox" @change="resetPaging" />
        Show linked duplicates
      </label>
    </div>

    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading jobs…</p>
    <p v-else-if="isError" class="mt-6 text-sm text-red-600">Could not reach the API.</p>
    <template v-else-if="data">
      <p class="mt-6 text-xs text-slate-500">Showing {{ showing }}</p>

      <ul class="mt-3 space-y-2">
        <li
          v-for="job in data.items"
          :key="job.id"
          class="rounded-lg border border-slate-200 bg-white p-4"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-medium">
              <a :href="job.application_url" target="_blank" rel="noopener" class="hover:underline">
                {{ job.title }}
              </a>
            </h2>
            <span class="text-xs text-slate-500">{{ job.company }}</span>
          </div>

          <p class="mt-1 text-xs text-slate-500">
            <span v-if="job.location">{{ job.location }}</span>
            <span v-if="job.remote_type !== 'unknown'"> · {{ job.remote_type }}</span>
            <span v-if="job.seniority !== 'unknown'"> · {{ job.seniority }}</span>
            <span v-if="job.employment_type"> · {{ job.employment_type }}</span>
            <span v-if="job.posted_at">
              · posted {{ new Date(job.posted_at).toLocaleDateString() }}
            </span>
          </p>

          <p v-if="job.required_skills.length" class="mt-2 text-xs text-slate-600">
            Requires: {{ job.required_skills.slice(0, 4).join(' · ') }}
          </p>

          <p v-if="job.injection_flagged" class="mt-2 text-xs text-amber-800">
            This posting contains text that tries to give the agent instructions
            ({{ job.injection_signals.join(', ') }}). It is treated as data, never followed.
          </p>

          <p v-if="job.possible_duplicate_of" class="mt-2 text-xs text-slate-500">
            Possible duplicate ({{ job.duplicate_reason }},
            {{ Math.round((job.duplicate_confidence ?? 0) * 100) }}% confidence) — linked rather
            than merged.
          </p>
        </li>
      </ul>

      <p v-if="!data.items.length" class="mt-6 text-sm text-slate-500">
        Nothing matches. Add a source and run discovery.
      </p>

      <div v-if="data.total > limit" class="mt-4 flex gap-2">
        <button
          class="rounded-md border border-slate-300 px-3 py-1 text-sm disabled:opacity-40"
          :disabled="offset === 0"
          @click="offset = Math.max(0, offset - limit)"
        >
          Previous
        </button>
        <button
          class="rounded-md border border-slate-300 px-3 py-1 text-sm disabled:opacity-40"
          :disabled="offset + limit >= data.total"
          @click="offset = offset + limit"
        >
          Next
        </button>
      </div>
    </template>
  </section>
</template>
