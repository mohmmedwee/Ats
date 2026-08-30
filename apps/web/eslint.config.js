import js from '@eslint/js'
import vueTsConfig from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'

export default [
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  // 'essential' rather than 'recommended': Prettier owns formatting, so the
  // stylistic template rules would only fight it.
  ...pluginVue.configs['flat/essential'],
  ...vueTsConfig(),
  {
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },
]
