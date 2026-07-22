import './styles/variables.css'
import './styles/global.css'
import 'element-plus/theme-chalk/el-overlay.css'
import 'element-plus/es/components/message/style/css'
import 'element-plus/es/components/message-box/style/css'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'

const app = createApp(App)

app.use(router)
app.mount('#fchub-memberships-app')
