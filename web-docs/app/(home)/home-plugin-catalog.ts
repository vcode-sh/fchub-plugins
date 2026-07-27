import type { LucideIcon } from "lucide-react";
import {
  ArrowRightLeft,
  CreditCard,
  Globe,
  Heart,
  Home,
  LayoutDashboard,
  MessageSquare,
  Receipt,
  Smartphone,
  SquarePlay,
  Users,
} from "lucide-react";
import { versions } from "@/lib/versions";

export type HomePlugin = {
  title: string;
  description: string;
  icon: LucideIcon;
  docsHref: string;
  downloadUrl?: string;
  comingSoon?: boolean;
  discontinued?: boolean;
  hot?: boolean;
};

export const communityPlugins: HomePlugin[] = [
  {
    title: "FCHub Stream",
    description:
      "Discontinued and retained as-is. Historical video uploads via Cloudflare Stream & Bunny.net.",
    icon: SquarePlay,
    docsHref: "/docs/fchub-stream",
    downloadUrl: versions["fchub-stream"].releaseUrl,
    discontinued: true,
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

export const cartPlugins: HomePlugin[] = [
  {
    title: "FCHub",
    description:
      "One calm screen for every FCHub product on your site — install, switch on, update. Entirely optional; nothing depends on it.",
    icon: Home,
    docsHref: "/docs/fchub",
    downloadUrl: versions.fchub.releaseUrl,
  },
  {
    title: "Wishlist",
    description:
      "Wishlists for FluentCart. Let customers hoard things they'll never buy.",
    icon: Heart,
    docsHref: "/docs/fchub-wishlist",
    downloadUrl: versions["fchub-wishlist"].releaseUrl,
    hot: true,
  },
  {
    title: "Multi-Currency",
    description:
      "Automatic currency conversion. Show prices in your customer's currency so they can complain in their own language.",
    icon: Globe,
    docsHref: "/docs/fchub-multi-currency",
    downloadUrl: versions["fchub-multi-currency"].releaseUrl,
    hot: true,
  },
  {
    title: "Przelewy24",
    description: "Polish payment gateway. Because Stripe doesn't speak Polish.",
    icon: CreditCard,
    docsHref: "/docs/fchub-p24",
    downloadUrl: versions["fchub-p24"].releaseUrl,
  },
  {
    title: "Fakturownia",
    description:
      "Invoice automation with KSeF 2.0. Automate paperwork before the tax office automates you.",
    icon: Receipt,
    docsHref: "/docs/fchub-fakturownia",
    downloadUrl: versions["fchub-fakturownia"].releaseUrl,
  },
  {
    title: "Memberships",
    description:
      "A calmer way to run memberships: guided setup, protected content, member care, automation, and fewer mystery fires.",
    icon: Users,
    docsHref: "/docs/fchub-memberships",
    downloadUrl: versions["fchub-memberships"].releaseUrl,
  },
  {
    title: "Portal Extender",
    description:
      "Custom portal pages without writing PHP. Because not everyone wants to be a developer.",
    icon: LayoutDashboard,
    docsHref: "/docs/fchub-portal-extender",
    downloadUrl: versions["fchub-portal-extender"].releaseUrl,
  },
  {
    title: "CartShift",
    description:
      "Products, orders, subscriptions, customers. Your WooCommerce escape hatch.",
    icon: ArrowRightLeft,
    docsHref: "/docs/cartshift",
    downloadUrl: versions.cartshift.releaseUrl,
  },
];
