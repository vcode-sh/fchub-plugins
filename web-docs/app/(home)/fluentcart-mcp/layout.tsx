import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "FluentCart MCP — AI Tools for Your Store",
  description:
    "Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients.",
  openGraph: {
    title: "FluentCart MCP — AI Tools for Your Store",
    description:
      "Open-source MCP server for reading and safely administering a FluentCart store from supported AI clients.",
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
