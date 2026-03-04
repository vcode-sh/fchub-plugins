import { HomeLayout } from "fumadocs-ui/layouts/home";
import { NavbarMenu, NavbarMenuTrigger } from "fumadocs-ui/layouts/home/navbar";
import {
  ArrowRightLeft,
  Bot,
  Braces,
  CreditCard,
  Flame,
  GitPullRequest,
  Heart,
  Home,
  LayoutDashboard,
  Mail,
  MessageSquare,
  Newspaper,
  Receipt,
  Smartphone,
  SquarePlay,
  Users,
} from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import {
  NavigationMenuContent,
  NavigationMenuLink,
} from "@/components/ui/navigation-menu";
import { baseOptions } from "@/lib/layout.shared";

const opts = baseOptions();

export default function Layout({ children }: LayoutProps<"/">) {
  return (
    <HomeLayout
      {...opts}
      links={[
        {
          type: "menu",
          on: "menu",
          text: "Docs",
          items: [
            {
              text: "Przelewy24",
              url: "/docs/fchub-p24",
              icon: <CreditCard size={16} />,
            },
            {
              text: "Fakturownia",
              url: "/docs/fchub-fakturownia",
              icon: <Receipt size={16} />,
            },
            {
              text: "Memberships",
              url: "/docs/fchub-memberships",
              icon: <Users size={16} />,
            },
            {
              text: "Portal Extender",
              url: "/docs/fchub-portal-extender",
              icon: <LayoutDashboard size={16} />,
            },
            {
              text: "WC Migrator",
              url: "/docs/wc-fc",
              icon: <ArrowRightLeft size={16} />,
            },
            { text: "Wishlist", url: "#", icon: <Heart size={16} /> },
            { text: "FCHub", url: "/docs/fchub", icon: <Home size={16} /> },
            {
              text: "FCHub Stream",
              url: "/docs/fchub-stream",
              icon: <SquarePlay size={16} />,
            },
            {
              text: "FluentCart MCP",
              url: "/docs/fluentcart-mcp",
              icon: <Bot size={16} />,
            },
            {
              text: "FluentCart API",
              url: "/docs/fluentcart-api",
              icon: <Braces size={16} />,
            },
          ],
        },
        {
          type: "custom",
          on: "nav",
          children: (
            <NavbarMenu>
              <NavbarMenuTrigger>Docs</NavbarMenuTrigger>
              <NavigationMenuContent className="grid grid-cols-[1.2fr_1fr_1fr] p-2 w-[640px]">
                <div className="space-y-1 rounded-lg bg-muted/50 p-2">
                  <div className="px-2 pb-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                    FluentCart
                  </div>
                  <NavigationMenuLink render={<Link href="/docs/fchub-p24" />}>
                    <CreditCard size={16} />
                    Przelewy24
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4"
                    >
                      New
                    </Badge>
                  </NavigationMenuLink>
                  <NavigationMenuLink
                    render={<Link href="/docs/fchub-fakturownia" />}
                  >
                    <Receipt size={16} />
                    Fakturownia
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4"
                    >
                      New
                    </Badge>
                  </NavigationMenuLink>
                  <NavigationMenuLink
                    render={<Link href="/docs/fchub-memberships" />}
                  >
                    <Users size={16} />
                    Memberships
                    <Badge className="ml-auto text-[10px] h-4 bg-orange-500/15 text-orange-500 border-transparent">
                      <Flame size={10} />
                      Hot
                    </Badge>
                  </NavigationMenuLink>
                  <NavigationMenuLink
                    render={<Link href="/docs/fchub-portal-extender" />}
                  >
                    <LayoutDashboard size={16} />
                    Portal Extender
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4"
                    >
                      New
                    </Badge>
                  </NavigationMenuLink>
                  <NavigationMenuLink render={<Link href="/docs/wc-fc" />}>
                    <ArrowRightLeft size={16} />
                    WC Migrator
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4"
                    >
                      New
                    </Badge>
                  </NavigationMenuLink>
                  <div className="flex items-center gap-1.5 rounded-sm p-2 text-sm text-muted-foreground/50 pointer-events-none [&_svg:not([class*='size-'])]:size-4">
                    <Heart size={16} />
                    Wishlist
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4 opacity-60"
                    >
                      Soon
                    </Badge>
                  </div>
                </div>
                <div className="space-y-1 p-2">
                  <div className="px-2 pb-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                    FluentCommunity
                  </div>
                  <NavigationMenuLink render={<Link href="/docs/fchub" />}>
                    <Home size={16} />
                    FCHub
                  </NavigationMenuLink>
                  <NavigationMenuLink
                    render={<Link href="/docs/fchub-stream" />}
                  >
                    <SquarePlay size={16} />
                    FCHub Stream
                  </NavigationMenuLink>
                  <div className="flex items-center gap-1.5 rounded-sm p-2 text-sm text-muted-foreground/50 pointer-events-none [&_svg:not([class*='size-'])]:size-4">
                    <MessageSquare size={16} />
                    FCHub Chat
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4 opacity-60"
                    >
                      Soon
                    </Badge>
                  </div>
                  <div className="flex items-center gap-1.5 rounded-sm p-2 text-sm text-muted-foreground/50 pointer-events-none [&_svg:not([class*='size-'])]:size-4">
                    <Smartphone size={16} />
                    FCHub Mobile
                    <Badge
                      variant="secondary"
                      className="ml-auto text-[10px] h-4 opacity-60"
                    >
                      Soon
                    </Badge>
                  </div>
                </div>
                <div className="space-y-1 p-2">
                  <div className="px-2 pb-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                    Tools & Extra
                  </div>
                  <NavigationMenuLink
                    render={<Link href="/docs/fluentcart-mcp" />}
                  >
                    <Bot size={16} />
                    FluentCart MCP
                  </NavigationMenuLink>
                  <NavigationMenuLink
                    render={<Link href="/docs/fluentcart-api" />}
                  >
                    <Braces size={16} />
                    FluentCart API
                  </NavigationMenuLink>
                </div>
              </NavigationMenuContent>
            </NavbarMenu>
          ),
        },
        {
          text: "Blog",
          url: "/blog",
          icon: <Newspaper size={18} />,
        },
        {
          text: "MCP",
          url: "/fluentcart-mcp",
          icon: <Bot size={18} />,
        },
        {
          text: "API",
          url: "/docs/fluentcart-api",
          icon: <Braces size={18} />,
        },
        {
          text: "Contribute",
          url: "/contribute",
          icon: <GitPullRequest size={18} />,
        },
        {
          text: "Contact",
          url: "/contact",
          icon: <Mail size={18} />,
        },
        {
          text: "Support Projects",
          url: "https://donate.stripe.com/aFa00i5Ml01U1eKeO39Zm0G",
          icon: <Heart size={18} />,
        },
        ...(opts.links ?? []),
      ]}
    >
      {children}
    </HomeLayout>
  );
}
