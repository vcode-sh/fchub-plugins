import ThankYouPageSettings from "./components/ThankYouPageSettings.vue";

declare global {
	interface Window {
		fluent_cart_admin: {
			hooks: {
				addFilter: (
					hookName: string,
					namespace: string,
					callback: (value: unknown) => unknown,
					priority?: number,
				) => void;
			};
		};
		ElMessage?: (options: { type: string; message: string }) => void;
	}
}

interface SidebarWidget {
	type: string;
	use_card: boolean;
	title: string;
	component: typeof ThankYouPageSettings;
}

window.fluent_cart_admin.hooks.addFilter(
	"single_product_page",
	"fchub-thank-you",
	(widgets: unknown): unknown => {
		const list = widgets as SidebarWidget[];
		list.push({
			type: "vue-component",
			use_card: true,
			title: "Custom Thank You Page",
			component: ThankYouPageSettings,
		});
		return list;
	},
);
