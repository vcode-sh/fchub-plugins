import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Contribute — FCHub",
  description:
    "Built something for FluentCart or FluentCommunity? Submit your plugin, contribute code, or help translate. FCHub is open to new plugins — yours probably belongs here.",
  openGraph: {
    title: "Contribute — FCHub",
    description:
      "Built something for FluentCart or FluentCommunity? Submit your plugin, contribute code, or help translate. FCHub is open to new plugins — yours probably belongs here.",
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
