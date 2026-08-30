import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import ScoreBar from '@/components/ScoreBar.vue'

describe('ScoreBar', () => {
  it('shows the routing decision, not just the number', () => {
    const wrapper = mount(ScoreBar, { props: { score: 88.4, routing: 'high_priority' } })
    expect(wrapper.text()).toContain('88')
    expect(wrapper.text()).toContain('high priority')
  })

  it('colours a rejected match differently from a good one', () => {
    const rejected = mount(ScoreBar, { props: { score: 85, routing: 'rejected' } })
    const high = mount(ScoreBar, { props: { score: 85, routing: 'high_priority' } })
    // The same score can mean opposite things; the colour has to follow the
    // decision rather than the number.
    expect(rejected.html()).toContain('red')
    expect(high.html()).toContain('emerald')
  })

  it('widths the bar to the score', () => {
    const wrapper = mount(ScoreBar, { props: { score: 42, routing: 'possible_match' } })
    expect(wrapper.html()).toContain('width: 42%')
  })
})
