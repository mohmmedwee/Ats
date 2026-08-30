import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import TierBadge from '@/components/TierBadge.vue'

describe('TierBadge', () => {
  it('labels read tools as executed without confirmation', () => {
    const wrapper = mount(TierBadge, { props: { tier: 't0_read' } })
    expect(wrapper.text()).toBe('Read')
  })

  it('labels prepare tools as needing confirmation', () => {
    const wrapper = mount(TierBadge, { props: { tier: 't1_prepare' } })
    expect(wrapper.text()).toBe('Needs confirmation')
  })

  it('labels external actions as UI only', () => {
    const wrapper = mount(TierBadge, { props: { tier: 't2_external' } })
    expect(wrapper.text()).toBe('UI only')
  })
})
