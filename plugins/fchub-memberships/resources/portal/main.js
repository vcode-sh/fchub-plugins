import { createApp } from 'vue'
import App from './App.vue'
import './styles/variables.css'
import './styles/base.css'
import './styles/animations.css'

const el = document.getElementById('fchub-membership-portal')

if (el) {
  const app = createApp(App)
  app.mount(el)
}
