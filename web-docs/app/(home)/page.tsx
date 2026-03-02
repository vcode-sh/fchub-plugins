"use client";

import {
  ArrowRightLeft,
  CreditCard,
  Home,
  Receipt,
  SquarePlay,
  Users,
} from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import {
  Item,
  ItemContent,
  ItemDescription,
  ItemMedia,
  ItemTitle,
} from "@/components/ui/item";

const communityPlugins = [
  {
    title: "FCHub",
    description: "Core FCHub plugin documentation and guides",
    icon: Home,
    href: "/docs/fchub",
  },
  {
    title: "FCHub Stream",
    description: "Direct video uploads for FluentCommunity",
    icon: SquarePlay,
    href: "/docs/fchub-stream",
  },
];

const cartPlugins = [
  {
    title: "Przelewy24",
    description: "Polish payment gateway for FluentCart",
    icon: CreditCard,
    href: "/docs/fchub-p24",
  },
  {
    title: "Fakturownia",
    description: "Invoice automation with KSeF 2.0 support",
    icon: Receipt,
    href: "/docs/fchub-fakturownia",
  },
  {
    title: "Memberships",
    description: "Membership plans, content gating, and drip",
    icon: Users,
    href: "/docs/fchub-memberships",
  },
  {
    title: "WC Migrator",
    description: "WooCommerce to FluentCart migration tool",
    icon: ArrowRightLeft,
    href: "/docs/wc-fc",
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

const headingVariants = {
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

function PluginGrid({ plugins }: { plugins: typeof communityPlugins }) {
  return (
    <motion.div
      initial="hidden"
      animate="visible"
      variants={containerVariants}
      className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl w-full"
    >
      {plugins.map((plugin) => {
        const Icon = plugin.icon;
        return (
          <motion.div key={plugin.href} variants={itemVariants}>
            <Item variant="outline" asChild>
              <Link href={plugin.href}>
                <ItemMedia variant="icon">
                  <Icon />
                </ItemMedia>
                <ItemContent>
                  <ItemTitle>{plugin.title}</ItemTitle>
                  <ItemDescription>{plugin.description}</ItemDescription>
                </ItemContent>
              </Link>
            </Item>
          </motion.div>
        );
      })}
    </motion.div>
  );
}

export default function HomePage() {
  return (
    <div className="flex flex-col justify-center items-center flex-1 px-4 py-12">
      <motion.div
        initial="hidden"
        animate="visible"
        variants={headingVariants}
        className="max-w-4xl w-full text-center mb-12"
      >
        <h1 className="text-4xl font-bold mb-4">Getting Started</h1>
        <p className="text-lg text-muted-foreground">
          Choose a plugin to view its documentation
        </p>
      </motion.div>

      <motion.div
        initial="hidden"
        animate="visible"
        variants={itemVariants}
        className="max-w-4xl w-full mb-6"
      >
        <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-4">
          FluentCommunity
        </h2>
      </motion.div>
      <PluginGrid plugins={communityPlugins} />

      <motion.div
        initial="hidden"
        animate="visible"
        variants={itemVariants}
        className="max-w-4xl w-full mt-10 mb-6"
      >
        <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-4">
          FluentCart
        </h2>
      </motion.div>
      <PluginGrid plugins={cartPlugins} />
    </div>
  );
}
