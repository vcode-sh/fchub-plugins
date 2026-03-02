"use client";

import { useState } from "react";
import {
	ArrowRight,
	BookOpen,
	Bot,
	Check,
	ClipboardCopy,
	Container,
	CreditCard,
	Globe,
	LayoutDashboard,
	Package,
	Receipt,
	RefreshCw,
	ShoppingCart,
	Tag,
	Terminal,
	TrendingUp,
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
	CardHeader,
	CardTitle,
} from "@/components/ui/card";

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

const capabilities = [
	{
		icon: ShoppingCart,
		title: "Orders",
		count: 23,
		description: "List, create, refund, update statuses, handle disputes",
	},
	{
		icon: Package,
		title: "Products",
		count: 53,
		description: "CRUD, pricing, variants, downloads, categories",
	},
	{
		icon: Users,
		title: "Customers",
		count: 19,
		description: "Profiles, addresses, stats, lifetime value",
	},
	{
		icon: RefreshCw,
		title: "Subscriptions",
		count: 7,
		description: "Pause, resume, cancel, reactivate",
	},
	{
		icon: Tag,
		title: "Coupons",
		count: 12,
		description: "Create, apply, check eligibility",
	},
	{
		icon: TrendingUp,
		title: "Reports",
		count: 30,
		description: "Revenue, sales, top products, customer insights",
	},
];

const clients = [
	{ name: "Claude Desktop", difficulty: "JSON config" },
	{ name: "Claude Code", difficulty: "One command" },
	{ name: "Cursor", difficulty: "JSON config" },
	{ name: "VS Code + Copilot", difficulty: "JSON config" },
	{ name: "Windsurf", difficulty: "JSON config" },
	{ name: "Codex CLI", difficulty: "Env vars" },
];

const prompts = [
	"Show me all orders from the last 7 days",
	"What's my revenue this month?",
	"Create a 20% off coupon that expires Friday",
	"Find customer john@example.com",
	"Pause subscription #42",
	"Which products sold the most this week?",
	"Refund order #1234",
	"Create a digital product at $99",
];

const installMethods = [
	{
		icon: Terminal,
		title: "npx",
		command: "npx -y fluentcart-mcp",
		description: "Zero install. Runs locally via stdio.",
	},
	{
		icon: Container,
		title: "Docker",
		command: "docker pull vcodesh/fluentcart-mcp",
		description: "HTTP transport on port 3000. For VPS and remote clients.",
	},
	{
		icon: Globe,
		title: "Smithery",
		command: "npx -y @smithery/cli install fluentcart-mcp",
		description: "One-click from the Smithery directory.",
	},
];

const QUICK_START_CMD = "claude mcp add fluentcart -- npx -y fluentcart-mcp";

export default function FluentCartMcpPage() {
	const [copied, setCopied] = useState(false);

	function copyCommand() {
		navigator.clipboard.writeText(QUICK_START_CMD);
		setCopied(true);
		setTimeout(() => setCopied(false), 2000);
	}

	return (
		<div className="flex flex-col justify-center items-center flex-1 px-4 py-12">
			{/* Hero */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={heroVariants}
				className="max-w-4xl w-full text-center mb-16"
			>
				<div className="flex items-center justify-center gap-2 mb-6">
					<Badge variant="secondary" className="text-xs">
						<Bot className="size-3" />
						MCP Server
					</Badge>
					<Badge variant="secondary" className="text-xs">
						Open Source
					</Badge>
					<Badge variant="secondary" className="text-xs">
						200+ Tools
					</Badge>
				</div>
				<h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
					Talk to your FluentCart store
					<br />
					like a normal person
				</h1>
				<p className="text-lg text-muted-foreground mb-8 max-w-2xl mx-auto text-balance">
					I built an MCP server that gives AI assistants direct access to your
					FluentCart store. Orders, products, customers, subscriptions, reports
					— the lot. No more clicking through admin panels.
				</p>
				<div className="flex items-center justify-center gap-3 mb-4">
					<Button
						variant="default"
						size="lg"
						render={<Link href="/docs/fluentcart-mcp/setup" />}
					>
						<BookOpen />
						Setup Guide
					</Button>
					<Button
						variant="outline"
						size="lg"
						render={
							<a
								href="https://github.com/vcode-sh/fchub-plugins/tree/main/fluentcart-mcp"
								target="_blank"
								rel="noopener noreferrer"
							/>
						}
					>
						View on GitHub
					</Button>
				</div>
				<p className="text-xs text-muted-foreground">
					MIT licence · npm: fluentcart-mcp · Built by Vibe Code
				</p>
			</motion.div>

			{/* Quick Install */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants}>
					<Card className="gap-0 py-0 bg-fd-card">
						<CardContent className="p-6">
							<div className="flex items-center gap-2 mb-3">
								<ClipboardCopy className="size-4 text-muted-foreground" />
								<span className="text-sm font-medium">Quick Start — Claude Code</span>
							</div>
							<button
								type="button"
								onClick={copyCommand}
								className="w-full bg-muted rounded-md p-4 font-mono text-sm overflow-x-auto text-left flex items-center gap-3 cursor-pointer transition-colors hover:bg-muted/70 group"
							>
								<span className="flex-1">
									<span className="text-muted-foreground">$</span>{" "}
									{QUICK_START_CMD}
								</span>
								{copied ? (
									<Check className="size-4 text-green-500 shrink-0" />
								) : (
									<ClipboardCopy className="size-4 text-muted-foreground group-hover:text-foreground shrink-0 transition-colors" />
								)}
							</button>
							<p className="text-xs text-muted-foreground mt-3">
								Then set your credentials via env vars or config file.{" "}
								<Link
									href="/docs/fluentcart-mcp/setup"
									className="underline underline-offset-4 hover:text-foreground transition-colors"
								>
									Full setup guide
								</Link>{" "}
								covers every platform.
							</p>
						</CardContent>
					</Card>
				</motion.div>
			</motion.div>

			{/* Capabilities Grid */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						What It Manages
					</h2>
				</motion.div>
				<motion.div
					variants={containerVariants}
					className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
				>
					{capabilities.map((cap) => {
						const Icon = cap.icon;
						return (
							<motion.div key={cap.title} variants={itemVariants}>
								<Card className="h-full gap-0 py-0">
									<CardHeader className="py-3">
										<div className="flex items-center gap-2">
											<Icon className="size-4 text-muted-foreground" />
											<CardTitle className="text-sm">{cap.title}</CardTitle>
											<Badge
												variant="secondary"
												className="ml-auto text-[10px] h-4"
											>
												{cap.count} tools
											</Badge>
										</div>
									</CardHeader>
									<CardContent className="pb-4">
										<CardDescription className="text-xs">
											{cap.description}
										</CardDescription>
									</CardContent>
								</Card>
							</motion.div>
						);
					})}
				</motion.div>
				<motion.div variants={itemVariants} className="mt-4">
					<Link href="/docs/fluentcart-mcp/tools" className="group">
						<Card className="gap-0 py-0 transition-colors hover:border-primary/30">
							<CardContent className="flex items-center gap-3 py-3">
								<LayoutDashboard className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
								<div className="flex-1">
									<CardTitle className="text-sm">
										+ 56 more tools across settings, integrations, labels,
										activity, and more
									</CardTitle>
								</div>
								<ArrowRight className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
							</CardContent>
						</Card>
					</Link>
				</motion.div>
			</motion.div>

			{/* Example Prompts */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						Just Talk to It
					</h2>
				</motion.div>
				<motion.div
					variants={containerVariants}
					className="grid grid-cols-1 md:grid-cols-2 gap-3"
				>
					{prompts.map((prompt) => (
						<motion.div key={prompt} variants={itemVariants}>
							<div className="border rounded-md px-4 py-3 text-sm text-muted-foreground">
								<span className="text-foreground/30 mr-2">&gt;</span>
								{prompt}
							</div>
						</motion.div>
					))}
				</motion.div>
				<motion.div variants={itemVariants} className="text-center mt-6">
					<Button
						variant="outline"
						size="sm"
						render={<Link href="/docs/fluentcart-mcp/usage" />}
					>
						See all example prompts
						<ArrowRight />
					</Button>
				</motion.div>
			</motion.div>

			{/* Supported Clients */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						Works Everywhere
					</h2>
				</motion.div>
				<motion.div variants={itemVariants}>
					<div className="grid grid-cols-2 md:grid-cols-3 gap-px bg-border rounded-lg overflow-hidden border">
						{clients.map((client) => (
							<div
								key={client.name}
								className="flex items-center justify-between gap-2 px-4 py-3 bg-card"
							>
								<span className="text-sm font-medium">{client.name}</span>
								<Badge
									variant="secondary"
									className="text-[10px] h-4 shrink-0"
								>
									{client.difficulty}
								</Badge>
							</div>
						))}
					</div>
				</motion.div>
			</motion.div>

			{/* Install Methods */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						Install Your Way
					</h2>
				</motion.div>
				<motion.div
					variants={containerVariants}
					className="grid grid-cols-1 md:grid-cols-3 gap-4"
				>
					{installMethods.map((method) => {
						const Icon = method.icon;
						return (
							<motion.div key={method.title} variants={itemVariants}>
								<Card className="h-full gap-0 py-0">
									<CardHeader className="py-3">
										<div className="flex items-center gap-2">
											<Icon className="size-4 text-muted-foreground" />
											<CardTitle className="text-sm">{method.title}</CardTitle>
										</div>
									</CardHeader>
									<CardContent className="pb-4 space-y-2">
										<code className="block bg-muted rounded px-3 py-2 text-xs font-mono overflow-x-auto">
											{method.command}
										</code>
										<CardDescription className="text-xs">
											{method.description}
										</CardDescription>
									</CardContent>
								</Card>
							</motion.div>
						);
					})}
				</motion.div>
				<motion.div variants={itemVariants} className="mt-4">
					<Link href="/docs/fluentcart-mcp/deployment" className="group">
						<Card className="gap-0 py-0 transition-colors hover:border-primary/30">
							<CardContent className="flex items-center gap-3 py-3">
								<Globe className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
								<div className="flex-1">
									<CardTitle className="text-sm">
										VPS deployment guide — Docker, Dokploy, Cloudflare Tunnel
									</CardTitle>
								</div>
								<ArrowRight className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
							</CardContent>
						</Card>
					</Link>
				</motion.div>
			</motion.div>

			{/* How It Works */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						How It Works
					</h2>
				</motion.div>
				<motion.div variants={itemVariants}>
					<div className="grid grid-cols-1 md:grid-cols-3 gap-4">
						<Card className="gap-0 py-0">
							<CardContent className="py-4">
								<div className="text-2xl font-bold text-muted-foreground/30 mb-2">
									1
								</div>
								<CardTitle className="text-sm mb-1">You ask</CardTitle>
								<CardDescription className="text-xs">
									&ldquo;Show me today&apos;s orders&rdquo; — plain English, no
									special syntax
								</CardDescription>
							</CardContent>
						</Card>
						<Card className="gap-0 py-0">
							<CardContent className="py-4">
								<div className="text-2xl font-bold text-muted-foreground/30 mb-2">
									2
								</div>
								<CardTitle className="text-sm mb-1">AI calls my server</CardTitle>
								<CardDescription className="text-xs">
									Picks the right tool, fills in the parameters, makes the API
									call to your store
								</CardDescription>
							</CardContent>
						</Card>
						<Card className="gap-0 py-0">
							<CardContent className="py-4">
								<div className="text-2xl font-bold text-muted-foreground/30 mb-2">
									3
								</div>
								<CardTitle className="text-sm mb-1">You get answers</CardTitle>
								<CardDescription className="text-xs">
									Data from your store, translated into a human-friendly response
								</CardDescription>
							</CardContent>
						</Card>
					</div>
				</motion.div>
			</motion.div>

			{/* Requirements */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={containerVariants}
				className="max-w-4xl w-full mb-16"
			>
				<motion.div variants={itemVariants} className="mb-6">
					<h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider">
						What You Need
					</h2>
				</motion.div>
				<motion.div variants={itemVariants}>
					<div className="grid grid-cols-1 md:grid-cols-3 gap-4">
						<div className="border rounded-md p-4 space-y-1">
							<div className="text-sm font-medium">Node.js 22+</div>
							<div className="text-xs text-muted-foreground">
								Modern runtime for the MCP server
							</div>
						</div>
						<div className="border rounded-md p-4 space-y-1">
							<div className="text-sm font-medium">WordPress + FluentCart</div>
							<div className="text-xs text-muted-foreground">
								Your store, running FluentCart
							</div>
						</div>
						<div className="border rounded-md p-4 space-y-1">
							<div className="text-sm font-medium">Application Password</div>
							<div className="text-xs text-muted-foreground">
								Built into WordPress 5.6+. No plugins.
							</div>
						</div>
					</div>
				</motion.div>
			</motion.div>

			{/* CTA */}
			<motion.div
				initial="hidden"
				animate="visible"
				variants={itemVariants}
				className="max-w-4xl w-full text-center pb-8"
			>
				<div className="flex items-center justify-center gap-3 mb-4">
					<Button
						variant="default"
						size="lg"
						render={<Link href="/docs/fluentcart-mcp/setup" />}
					>
						Get Started
						<ArrowRight />
					</Button>
					<Button
						variant="outline"
						size="lg"
						render={<Link href="/docs/fluentcart-mcp" />}
					>
						<BookOpen />
						Full Documentation
					</Button>
				</div>
				<p className="text-xs text-muted-foreground">
					Open source · MIT · Built by{" "}
					<a
						href="https://x.com/vcode_sh"
						target="_blank"
						rel="noopener noreferrer"
						className="underline underline-offset-4 hover:text-foreground transition-colors"
					>
						Vibe Code
					</a>
				</p>
			</motion.div>
		</div>
	);
}
