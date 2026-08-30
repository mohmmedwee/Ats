import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ProvenanceBadge from '@/components/ProvenanceBadge.vue'

describe('ProvenanceBadge', () => {
  it('distinguishes what the user vouched for from what a model wrote', () => {
    expect(mount(ProvenanceBadge, { props: { provenance: 'user_confirmed' } }).text()).toBe(
      'Confirmed',
    )
    expect(mount(ProvenanceBadge, { props: { provenance: 'cv_derived' } }).text()).toBe('From CV')
    expect(mount(ProvenanceBadge, { props: { provenance: 'generated_draft' } }).text()).toBe(
      'Draft',
    )
  })
})
