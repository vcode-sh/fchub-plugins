"use client";

import type { LucideIcon } from "lucide-react";
import {
	ArrowRightLeft,
	BookOpen,
	Bot,
	Braces,
	CreditCard,
	Download,
	Home,
	Heart,
	LayoutDashboard,
	MessageSquare,
	Receipt,
	Send,
	Smartphone,
	SquarePlay,
	Users,
} from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
	Card,
	CardContent,
	CardDescription,
	CardFooter,
	CardHeader,
	CardTitle,
} from "@/components/ui/card";

const GITHUB_REPO = "https://github.com/vcode-sh/fchub-plugins";

type Plugin = {
	title: string;
	description: string;
	icon: LucideIcon;
	docsHref: string;
	downloadUrl?: string;
	comingSoon?: boolean;
};

const communityPlugins: Plugin[] = [
	{
		title: "FCHub",
		description: "The mothership. Core docs and guides for everything FCHub.",
		icon: Home,
		docsHref: "/docs/fchub",
	},
	{
		title: "FCHub Stream",
		description:
			"Video uploads via Cloudflare Stream & Bunny.net. Because WP media and video don't mix.",
		icon: SquarePlay,
		docsHref: "/docs/fchub-stream",
		downloadUrl: `${GITHUB_REPO}/releases/tag/fchub-stream-v1.0.0`,
	},
	{
		title: "FCHub Chat",
		description: "Real-time chat for FluentCommunity.",
		icon: MessageSquare,
		docsHref: "/docs/fchub-chat",
		comingSoon: true,
	},
	{
		title: "FCHub Mobile",
		description: "Native mobile app for FluentCommunity.",
		icon: Smartphone,
		docsHref: "/docs/fchub-mobile",
		comingSoon: true,
	},
];

const cartPlugins: Plugin[] = [
	{
		title: "Przelewy24",
		description: "Polish payment gateway. Because Stripe doesn't speak Polish.",
		icon: CreditCard,
		docsHref: "/docs/fchub-p24",
		downloadUrl: `${GITHUB_REPO}/releases/tag/fchub-p24-v1.0.0`,
	},
	{
		title: "Fakturownia",
		description:
			"Invoice automation with KSeF 2.0. Automate paperwork before the tax office automates you.",
		icon: Receipt,
		docsHref: "/docs/fchub-fakturownia",
		downloadUrl: `${GITHUB_REPO}/releases/tag/fchub-fakturownia-v1.0.0`,
	},
	{
		title: "Memberships",
		description:
			"Plans, content gating, drip scheduling. 15k lines so people can pay to read your blog.",
		icon: Users,
		docsHref: "/docs/fchub-memberships",
		downloadUrl: `${GITHUB_REPO}/releases/tag/fchub-memberships-v1.0.0`,
	},
	{
		title: "Portal Extender",
		description:
			"Custom portal pages without writing PHP. Because not everyone wants to be a developer.",
		icon: LayoutDashboard,
		docsHref: "/docs/fchub-portal-extender",
		downloadUrl: `${GITHUB_REPO}/releases/tag/fchub-portal-extender/v1.0.0`,
	},
	{
		title: "Wishlist",
		description: "Wishlists for FluentCart. Let customers hoard things they'll never buy.",
		icon: Heart,
		docsHref: "/docs/fchub-wishlist",
		comingSoon: true,
	},
	{
		title: "WC Migrator",
		description:
			"Products, orders, subscriptions, customers. Your WooCommerce escape hatch.",
		icon: ArrowRightLeft,
		docsHref: "/docs/wc-fc",
		downloadUrl: `${GITHUB_REPO}/releases/tag/wc-fc-v1.0.0`,
	},
];

const containerVariants = {
	hidden: { opacity: 0 },
	visible: {
		opacity: 1,
		transition: {
			staggerChildren: 0.1,
		},
	},
};

const itemVariants = {
	hidden: {
		opacity: 0,
		transform: "translateY(20px)",
	},
	visible: {
		opacity: 1,
		transform: "translateY(0px)",
		transition: {
			duration: 0.3,
			ease: [0.25, 0.1, 0.25, 1] as const,
		},
	},
};

const heroVariants = {
	hidden: { opacity: 0, transform: "translateY(-10px)" },
	visible: {
		opacity: 1,
		transform: "translateY(0px)",
		transition: {
			duration: 0.25,
			ease: [0.25, 0.1, 0.25, 1] as const,
		},
	},
};

function PluginCard({ plugin }: { plugin: Plugin }) {
	const Icon = plugin.icon;

	if (plugin.comingSoon) {
		return (
			<Card className="h-full gap-0 py-0 opacity-50 pointer-events-none">
				<CardHeader className="bg-muted/50 rounded-t-xl py-3">
					<div className="flex items-center gap-2">
						<Icon className="size-4" />
						<CardTitle>{plugin.title}</CardTitle>
						<Badge variant="secondary">Coming Soon</Badge>
					</div>
				</CardHeader>
				<CardContent className="pt-4 pb-6">
					<CardDescription>{plugin.description}</CardDescription>
				</CardContent>
				<CardFooter className="gap-2 mt-auto justify-end bg-muted/50 rounded-b-xl py-3">
					<Button variant="secondary" size="xs" disabled>
						<BookOpen />
						Docs
					</Button>
					<Button variant="outline" size="xs" disabled>
						<Download />
						Download
					</Button>
				</CardFooter>
			</Card>
		);
	}

	return (
		<Card className="h-full gap-0 py-0">
			<CardHeader className="bg-muted/50 rounded-t-xl py-3">
				<div className="flex items-center gap-2">
					<Icon className="size-4" />
					<CardTitle>{plugin.title}</CardTitle>
				</div>
			</CardHeader>
			<CardContent className="pt-4 pb-6">
				<CardDescription>{plugin.description}</CardDescription>
			</CardContent>
			<CardFooter className="gap-2 mt-auto justify-end bg-muted/50 rounded-b-xl py-3">
				<Button variant="secondary" size="xs" render={<Link href={plugin.docsHref} />}>
					<BookOpen />
					Docs
				</Button>
				{plugin.downloadUrl && (
					<Button
						variant="outline"
						size="xs"
						render={
							<a
								href={plugin.downloadUrl}
								target="_blank"
								rel="noopener noreferrer"
							/>
						}
					>
						<Download />
						Download
					</Button>
				)}
			</CardFooter>
		</Card>
	);
}

function PluginGrid({ plugins }: { plugins: Plugin[] }) {
	return (
		<motion.div
			initial="hidden"
			animate="visible"
			variants={containerVariants}
			className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl w-full"
		>
			{plugins.map((plugin) => (
				<motion.div key={plugin.title} variants={itemVariants}>
					<PluginCard plugin={plugin} />
				</motion.div>
			))}
		</motion.div>
	);
}

export default function HomePage() {
	return (
		<div className="flex flex-col justify-center items-center flex-1 px-4 py-12">
			<motion.div
				initial="hidden"
				animate="visible"
				variants={heroVariants}
				className="max-w-4xl w-full text-center mb-16"
			>
				<h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
					WordPress plugins for people
					<br />
					who ship things
				</h1>
				<p className="text-lg text-muted-foreground mb-8 max-w-2xl mx-auto text-balance">
					Payments, invoicing, memberships, video, migrations — the bits
					FluentCart and FluentCommunity forgot to ship. So I did.
				</p>
				<div className="flex items-center justify-center gap-3 mb-4">
					<Button
						variant="default"
						size="lg"
						render={
							<a href={GITHUB_REPO} target="_blank" rel="noopener noreferrer" />
						}
					>
						View on GitHub
					</Button>
					<Button
						variant="outline"
						size="lg"
						render={
							<a
								href="https://t.me/+s_-YxYytlelmMDM0"
								target="_blank"
								rel="noopener noreferrer"
							/>
						}
					>
						<Send />
						Join Telegram
					</Button>
				</div>
				<p className="text-xs text-muted-foreground">
					Open source · GPLv2 · Built by Vibe Code
				</p>
			</motion.div>

			<motion.div
				initial="hidden"
				animate="visible"
				variants={itemVariants}
				className="max-w-4xl w-full mb-6"
			>
				<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-4">
					FluentCart
				</h2>
			</motion.div>
			<PluginGrid plugins={cartPlugins} />

			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mt-4 space-y-3"
			>
				<motion.div variants={itemVariants}>
					<Link href="/fluentcart-mcp" className="block group">
						<Card className="gap-0 py-0 transition-colors hover:border-primary/30">
							<CardContent className="flex items-center gap-3 py-3">
								<Bot className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
								<div className="flex-1">
									<CardTitle className="text-sm">FluentCart MCP Server</CardTitle>
									<CardDescription className="text-xs">
										200+ AI-agent tools for your FluentCart store. Talk to your orders, products, and reports in plain English.
									</CardDescription>
								</div>
								<Badge variant="secondary" className="text-[10px] h-4">New</Badge>
							</CardContent>
						</Card>
					</Link>
				</motion.div>
				<motion.div variants={itemVariants}>
					<Link href="/docs/fluentcart-api" className="block group">
						<Card className="gap-0 py-0 transition-colors hover:border-primary/30">
							<CardContent className="flex items-center gap-3 py-3">
								<Braces className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
								<div className="flex-1">
									<CardTitle className="text-sm">REST API Reference</CardTitle>
									<CardDescription className="text-xs">
										Full OpenAPI docs for the FluentCart API. Every endpoint, every parameter, zero guesswork.
									</CardDescription>
								</div>
								<Badge variant="secondary" className="text-[10px] h-4">New</Badge>
							</CardContent>
						</Card>
					</Link>
				</motion.div>
			</motion.div>

			<motion.div
				initial="hidden"
				animate="visible"
				variants={itemVariants}
				className="max-w-4xl w-full mt-10 mb-6"
			>
				<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-4">
					FluentCommunity
				</h2>
			</motion.div>
			<PluginGrid plugins={communityPlugins} />
		</div>
	);
}
