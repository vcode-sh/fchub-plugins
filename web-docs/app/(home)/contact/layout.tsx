import type { Metadata } from "next";

export const metadata: Metadata = {
	title: "Contact — FCHub",
	description:
		"Get in touch with FCHub — report bugs on GitHub, chat on Telegram, or follow updates on X.",
	openGraph: {
		title: "Contact — FCHub",
		description:
			"Get in touch with FCHub — report bugs on GitHub, chat on Telegram, or follow updates on X.",
		url: "https://fchub.co/contact",
	},
};

export default function ContactLayout({
	children,
}: {
	children: React.ReactNode;
}) {
	return children;
}
