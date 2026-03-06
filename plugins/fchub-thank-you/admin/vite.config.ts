import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import { resolve } from "path";

export default defineConfig({
  plugins: [vue()],
  define: {
    "process.env.NODE_ENV": JSON.stringify("production"),
  },
  build: {
    lib: {
      entry: resolve(__dirname, "src/index.ts"),
      name: "FchubThankYouAdmin",
      formats: ["iife"],
      fileName: () => "fchub-thank-you-admin.js",
    },
    outDir: resolve(__dirname, "../assets/dist"),
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      output: {
        assetFileNames: "fchub-thank-you-admin.[ext]",
        inlineDynamicImports: true,
      },
    },
    minify: "esbuild",
  },
});
