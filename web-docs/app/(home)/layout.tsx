import { HomeLayout } from "fumadocs-ui/layouts/home";
import {
  NavbarMenu,
  NavbarMenuContent,
  NavbarMenuLink,
  NavbarMenuTrigger,
} from "fumadocs-ui/layouts/home/navbar";
import {
  ArrowRightLeft,
  CreditCard,
  GitPullRequest,
  Heart,
  Home,
  Mail,
  MessageSquare,
  Receipt,
  Smartphone,
  SquarePlay,
  Users,
} from "lucide-react";
import { baseOptions } from "@/lib/layout.shared";

export default function Layout({ children }: LayoutProps<"/">) {
  return (
    <HomeLayout
      {...baseOptions()}
      links={[
        {
          type: "custom",
          on: "nav",
          children: (
            <NavbarMenu>
              <NavbarMenuTrigger>Docs</NavbarMenuTrigger>
              <NavbarMenuContent>
                <div className="px-3 py-1.5 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  FluentCommunity
                </div>
                <NavbarMenuLink href="/docs/fchub">
                  <Home size={16} />
                  FCHub
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/fchub-stream">
                  <SquarePlay size={16} />
                  FCHub Stream
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/fchub-chat">
                  <MessageSquare size={16} />
                  FCHub Chat
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/fchub-mobile">
                  <Smartphone size={16} />
                  FCHub Mobile
                </NavbarMenuLink>
                <div className="px-3 py-1.5 mt-1 text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  FluentCart
                </div>
                <NavbarMenuLink href="/docs/fchub-p24">
                  <CreditCard size={16} />
                  Przelewy24
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/fchub-fakturownia">
                  <Receipt size={16} />
                  Fakturownia
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/fchub-memberships">
                  <Users size={16} />
                  Memberships
                </NavbarMenuLink>
                <NavbarMenuLink href="/docs/wc-fc">
                  <ArrowRightLeft size={16} />
                  WC Migrator
                </NavbarMenuLink>
              </NavbarMenuContent>
            </NavbarMenu>
          ),
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
        ...(baseOptions().links ?? []),
      ]}
    >
      {children}
    </HomeLayout>
  );
}
