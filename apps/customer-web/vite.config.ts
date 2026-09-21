import { defineConfig } from 'vite';
const target = process.env.API_PROXY_TARGET || 'http://127.0.0.1:8000';
export default defineConfig({ server: { watch: { usePolling: process.env.VITE_USE_POLLING === '1', interval: 300 }, proxy: { '/health': target, '/api/delivery/v1': target } } });
