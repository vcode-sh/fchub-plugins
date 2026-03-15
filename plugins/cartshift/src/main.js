import { createApp } from 'vue';
import App from './App.vue';
import './styles/app.css';

const config = window.cartshift || {};
const app = createApp(App);
app.provide('config', config);
app.mount('#cartshift-app');
