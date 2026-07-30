"use client";

import {
  ArrowRight,
  BookOpen,
  Bot,
  Check,
  ClipboardCopy,
  LayoutDashboard,
  Package,
  ShoppingCart,
  TrendingUp,
} from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { useState } from "react";
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
    transition: { staggerChildren: 0.1 },
  },
};

const itemVariants = {
  hidden: { opacity: 0, transform: "translateY(20px)" },
  visible: {
    opacity: 1,
    transform: "translateY(0px)",
    transition: { duration: 0.3, ease: [0.25, 0.1, 0.25, 1] as const },
  },
};

const heroVariants = {
  hidden: { opacity: 0, transform: "translateY(-10px)" },
  visible: {
    opacity: 1,
    transform: "translateY(0px)",
    transition: { duration: 0.25, ease: [0.25, 0.1, 0.25, 1] as const },
  },
};

const focusAreas = [
  {
    icon: ShoppingCart,
    title: "Read the shop",
    description:
      "Ask about orders, products, customers, subscriptions, renewals, settings, and the details behind them.",
  },
  {
    icon: TrendingUp,
    title: "Analyse what matters",
    description:
      "Combine store reads into useful answers without asking your model to carry every intermediate payload.",
  },
  {
    icon: Package,
    title: "Do reversible admin work",
    description:
      "Opt in only to the changes the release policy can treat as reversible. Destructive work stays out of scope.",
  },
];

const prompts = [
  "Summarise orders that need attention today",
  "Which products sold best this month, and why?",
  "Find the customer behind this order and show their history",
  "Which renewals need attention next week?",
  "Create a coupon for the weekend sale (reversible mode only)",
];

const QUICK_START_CMD = "npx -y fluentcart-mcp setup";

export default function FluentCartMcpPage() {
  const [copied, setCopied] = useState(false);

  function copyCommand() {
    navigator.clipboard.writeText(QUICK_START_CMD);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div className="flex flex-1 flex-col items-center justify-center px-4 py-12">
      <motion.div
        initial="hidden"
        animate="visible"
        variants={heroVariants}
        className="mb-16 w-full max-w-4xl text-center"
      >
        <div className="mb-6 flex items-center justify-center gap-2">
          <Badge variant="secondary" className="text-xs">
            <Bot className="size-3" />
            MCP server
          </Badge>
          <Badge variant="secondary" className="text-xs">
            Open source
          </Badge>
        </div>
        <h1 className="mb-4 text-4xl font-bold tracking-tight md:text-5xl">
          Ask your FluentCart store
          <br />
          better questions
        </h1>
        <p className="mx-auto mb-8 max-w-2xl text-balance text-lg text-muted-foreground">
          Choose ChatGPT, Codex, Claude, Cursor, VS Code, Windsurf, or a private
          ChatGPT web tunnel. Give it one store, verify one read, and keep
          reversible administration behind an explicit switch.
        </p>
        <div className="mb-4 flex items-center justify-center gap-3">
          <Button
            variant="default"
            size="lg"
            render={<Link href="/docs/fluentcart-mcp" />}
          >
            <BookOpen />
            Choose your app
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

      <motion.div
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="mb-16 w-full max-w-4xl"
      >
        <motion.div variants={itemVariants}>
          <Card className="gap-0 bg-fd-card py-0">
            <CardContent className="p-6">
              <div className="mb-3 flex items-center gap-2">
                <ClipboardCopy className="size-4 text-muted-foreground" />
                <span className="text-sm font-medium">
                  Advanced local setup
                </span>
              </div>
              <button
                type="button"
                onClick={copyCommand}
                className="group flex w-full cursor-pointer items-center gap-3 overflow-x-auto rounded-md bg-muted p-4 text-left font-mono text-sm transition-colors hover:bg-muted/70"
              >
                <span className="flex-1">
                  <span className="text-muted-foreground">$</span>{" "}
                  {QUICK_START_CMD}
                </span>
                {copied ? (
                  <Check className="size-4 shrink-0 text-green-500" />
                ) : (
                  <ClipboardCopy className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-foreground" />
                )}
              </button>
              <p className="mt-3 text-xs text-muted-foreground">
                This advanced local route needs Node.js 24 or newer. The setup
                wizard verifies your store URL, WordPress username, and
                Application Password, then saves them locally.{" "}
                <code>npx -y</code> downloads on demand and does not install the
                package globally. The{" "}
                <Link
                  href="/docs/fluentcart-mcp"
                  className="underline underline-offset-4 transition-colors hover:text-foreground"
                >
                  client chooser
                </Link>{" "}
                sends you to the standalone guide for your app.
              </p>
            </CardContent>
          </Card>
        </motion.div>
      </motion.div>

      <motion.section
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="mb-16 w-full max-w-4xl"
      >
        <motion.div variants={itemVariants} className="mb-6">
          <Card className="gap-0 border-primary/20 bg-fd-card py-0">
            <CardContent className="p-5 text-sm text-muted-foreground">
              <span className="font-medium text-foreground">
                New in 2.1.0:{" "}
              </span>
              FluentCart 1.6+ subscription support adds direct renewal reads,
              richer subscription context, and a narrowly guarded billing-cycle
              limit update. Charges, cancellation, and renewal lifecycle actions
              remain out of scope. Verified exactly with WordPress 7.0.2,
              FluentCart Core 1.6.0, and FluentCart Pro 1.6.0 — not every future
              version wearing a plus sign.
            </CardContent>
          </Card>
        </motion.div>
        <motion.div variants={itemVariants} className="mb-6">
          <h2 className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
            Built for useful shop work
          </h2>
        </motion.div>
        <motion.div
          variants={containerVariants}
          className="grid grid-cols-1 gap-4 md:grid-cols-3"
        >
          {focusAreas.map((area) => {
            const Icon = area.icon;
            return (
              <motion.div key={area.title} variants={itemVariants}>
                <Card className="h-full gap-0 py-0">
                  <CardHeader className="py-4">
                    <div className="flex items-center gap-2">
                      <Icon className="size-4 text-muted-foreground" />
                      <CardTitle className="text-sm">{area.title}</CardTitle>
                    </div>
                  </CardHeader>
                  <CardContent className="pb-4">
                    <CardDescription className="text-xs">
                      {area.description}
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
                <LayoutDashboard className="size-4 text-muted-foreground transition-colors group-hover:text-primary" />
                <CardTitle className="flex-1 text-sm">
                  See the tool reference and its availability boundaries
                </CardTitle>
                <ArrowRight className="size-4 text-muted-foreground transition-colors group-hover:text-primary" />
              </CardContent>
            </Card>
          </Link>
        </motion.div>
      </motion.section>

      <motion.section
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="mb-16 w-full max-w-4xl"
      >
        <motion.div variants={itemVariants} className="mb-6">
          <h2 className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
            Ask in plain English
          </h2>
        </motion.div>
        <motion.div
          variants={containerVariants}
          className="grid grid-cols-1 gap-3 md:grid-cols-2"
        >
          {prompts.map((prompt) => (
            <motion.div key={prompt} variants={itemVariants}>
              <div className="rounded-md border px-4 py-3 text-sm text-muted-foreground">
                <span className="mr-2 text-foreground/30">&gt;</span>
                {prompt}
              </div>
            </motion.div>
          ))}
        </motion.div>
        <motion.div variants={itemVariants} className="mt-6 text-center">
          <Button
            variant="outline"
            size="sm"
            render={<Link href="/docs/fluentcart-mcp/usage" />}
          >
            See practical examples
            <ArrowRight />
          </Button>
        </motion.div>
      </motion.section>

      <motion.section
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="mb-16 w-full max-w-4xl"
      >
        <motion.div variants={itemVariants} className="mb-6">
          <h2 className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
            A deliberately small default
          </h2>
        </motion.div>
        <motion.div
          variants={containerVariants}
          className="grid grid-cols-1 gap-4 md:grid-cols-3"
        >
          <motion.div variants={itemVariants}>
            <Card className="h-full gap-0 py-0">
              <CardContent className="py-4">
                <CardTitle className="mb-1 text-sm">1. Ask</CardTitle>
                <CardDescription className="text-xs">
                  Describe the store question in the client you have configured.
                </CardDescription>
              </CardContent>
            </Card>
          </motion.div>
          <motion.div variants={itemVariants}>
            <Card className="h-full gap-0 py-0">
              <CardContent className="py-4">
                <CardTitle className="mb-1 text-sm">2. Discover</CardTitle>
                <CardDescription className="text-xs">
                  The default read-only surface searches and describes the
                  relevant capability before it runs it.
                </CardDescription>
              </CardContent>
            </Card>
          </motion.div>
          <motion.div variants={itemVariants}>
            <Card className="h-full gap-0 py-0">
              <CardContent className="py-4">
                <CardTitle className="mb-1 text-sm">3. Decide</CardTitle>
                <CardDescription className="text-xs">
                  Enable reversible administration only when that is the work
                  you actually intend to delegate.
                </CardDescription>
              </CardContent>
            </Card>
          </motion.div>
        </motion.div>
      </motion.section>

      <motion.section
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="mb-16 w-full max-w-4xl"
      >
        <motion.div variants={itemVariants} className="mb-6">
          <h2 className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
            What you need
          </h2>
        </motion.div>
        <motion.div
          variants={containerVariants}
          className="grid grid-cols-1 gap-4 md:grid-cols-3"
        >
          {[
            ["Your FluentCart store", "A WordPress site running FluentCart."],
            [
              "A WordPress username",
              "The WordPress account whose permissions the server uses.",
            ],
            [
              "An Application Password",
              "A dedicated credential you can revoke when needed.",
            ],
          ].map(([title, description]) => (
            <motion.div key={title} variants={itemVariants}>
              <Card className="h-full gap-0 py-0">
                <CardContent className="py-4">
                  <CardTitle className="mb-1 text-sm">{title}</CardTitle>
                  <CardDescription className="text-xs">
                    {description}
                  </CardDescription>
                </CardContent>
              </Card>
            </motion.div>
          ))}
        </motion.div>
      </motion.section>

      <motion.div
        initial="hidden"
        animate="visible"
        variants={itemVariants}
        className="w-full max-w-4xl pb-8 text-center"
      >
        <div className="mb-4 flex items-center justify-center gap-3">
          <Button
            variant="default"
            size="lg"
            render={<Link href="/docs/fluentcart-mcp" />}
          >
            Choose your app
            <ArrowRight />
          </Button>
          <Button
            variant="outline"
            size="lg"
            render={<Link href="/docs/fluentcart-mcp" />}
          >
            <BookOpen />
            Documentation
          </Button>
        </div>
        <p className="text-xs text-muted-foreground">
          Open source · MIT · Built by Vibe Code
        </p>
      </motion.div>
    </div>
  );
}
