export type RedirectType = "page" | "post" | "cpt" | "url";

export interface RedirectSettings {
	enabled: boolean;
	type: RedirectType;
	target_id: number | null;
	url: string;
	post_type: string;
	target_label?: string;
	target_permalink?: string;
}

export interface SearchResult {
	id: number;
	title: string;
	permalink: string;
	post_type: string;
}

export interface PostType {
	slug: string;
	label: string;
}

export interface FchubThankYouData {
	restUrl: string;
	nonce: string;
}

// Subset of FC's product object passed via data.editableProduct
export interface FcProduct {
	ID: number;
	post_title: string;
	post_status: string;
}

export interface WidgetData {
	editableProduct: FcProduct;
}
