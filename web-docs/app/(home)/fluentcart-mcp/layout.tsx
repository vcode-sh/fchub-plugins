import type { Metadata } from "next";

export const metadata: Metadata = {
	title: "FluentCart MCP — AI Tools for Your Store",
	description:
		"Open-source MCP server that gives AI assistants direct access to your FluentCart store. 200+ tools for orders, products, customers, subscriptions, and reports.",
	openGraph: {
		title: "FluentCart MCP — AI Tools for Your Store",
		description:
			"Open-source MCP server that gives AI assistants direct access to your FluentCart store. 200+ tools for orders, products, customers, subscriptions, and reports.",
		url: "https://fchub.co/fluentcart-mcp",
	},
};

export default function FluentCartMcpLayout({
	children,
}: {
	children: React.ReactNode;
}) {
	return children;
}
