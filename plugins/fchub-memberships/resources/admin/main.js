import './styles/variables.css'
import './styles/global.css'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'

const app = createApp(App)

app.use(router)
app.mount('#fchub-memberships-app')
