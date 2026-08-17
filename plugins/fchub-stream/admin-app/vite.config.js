import vue from "@vitejs/plugin-vue";
import { resolve } from "path";
import { defineConfig } from "vite";

export default defineConfig({
	plugins: [vue()],
	build: {
		outDir: "../admin/dist",
		emptyOutDir: true,
		rollupOptions: {
			input: {
				main: resolve(import.meta.dirname, "index.html"),
			},
			output: {
				entryFileNames: "assets/[name].js",
				chunkFileNames: "assets/[name].js",
				assetFileNames: "assets/[name].[ext]",
			},
		},
	},
	resolve: {
		alias: {
			"@": resolve(import.meta.dirname, "src"),
		},
	},
});
