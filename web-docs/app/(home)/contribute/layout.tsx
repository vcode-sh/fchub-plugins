import type { Metadata } from "next";

export const metadata: Metadata = {
	title: "Contribute — FCHub",
	description:
		"Submit plugins, contribute code, or help translate. FCHub is open source and built by the community.",
	openGraph: {
		title: "Contribute — FCHub",
		description:
			"Submit plugins, contribute code, or help translate. FCHub is open source and built by the community.",
		url: "https://fchub.co/contribute",
	},
};

export default function ContributeLayout({
	children,
}: {
	children: React.ReactNode;
}) {
	return children;
}
