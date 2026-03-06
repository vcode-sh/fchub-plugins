import type {
	FchubThankYouData,
	PostType,
	RedirectSettings,
	SearchResult,
} from "./types";

declare const fchubThankYouData: FchubThankYouData;

async function request<T>(path: string, options?: RequestInit): Promise<T> {
	const res = await fetch(fchubThankYouData.restUrl + path, {
		headers: {
			"Content-Type": "application/json",
			"X-WP-Nonce": fchubThankYouData.nonce,
		},
		...options,
	});

	const body: unknown = await res.json();

	if (!res.ok) {
		const message =
			typeof body === "object" && body !== null && "message" in body
				? String((body as { message: unknown }).message)
				: `Request failed: ${res.status}`;
		throw new Error(message);
	}

	return body as T;
}

export function fetchSettings(productId: number): Promise<RedirectSettings> {
	return request<RedirectSettings>(`product/${productId}`);
}

export function saveSettings(
	productId: number,
	settings: Omit<RedirectSettings, "target_label" | "target_permalink">,
): Promise<RedirectSettings> {
	return request<RedirectSettings>(`product/${productId}`, {
		method: "POST",
		body: JSON.stringify(settings),
	});
}

export function search(
	postType: string,
	term: string = "",
): Promise<SearchResult[]> {
	const params = new URLSearchParams({ post_type: postType });
	if (term) {
		params.set("s", term);
	}
	return request<SearchResult[]>(`search?${params.toString()}`);
}

export function postTypes(): Promise<PostType[]> {
	return request<PostType[]>("post-types");
}
