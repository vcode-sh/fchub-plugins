import { DocsLayout } from "fumadocs-ui/layouts/docs";
import {
  ArrowRightLeft,
  Braces,
  CreditCard,
  Heart,
  Home,
  MessageSquare,
  Receipt,
  Smartphone,
  SquarePlay,
  Users,
} from "lucide-react";
import { baseOptions } from "@/lib/layout.shared";
import { source } from "@/lib/source";

export default function Layout({ children }: LayoutProps<"/docs">) {
  return (
    <DocsLayout
      tree={source.pageTree}
      {...baseOptions()}
      links={[
        {
          text: "Support Projects",
          url: "https://donate.stripe.com/aFa00i5Ml01U1eKeO39Zm0G",
          icon: <Heart size={18} />,
        },
        ...(baseOptions().links ?? []),
      ]}
      sidebar={{
        tabs: {
          transform: (option) => {
            const iconMap: Record<string, React.ReactNode> = {
              "/docs/fchub": <Home size={16} />,
              "/docs/fchub-stream": <SquarePlay size={16} />,
              "/docs/fchub-chat": <MessageSquare size={16} />,
              "/docs/fchub-mobile": <Smartphone size={16} />,
              "/docs/fchub-p24": <CreditCard size={16} />,
              "/docs/fchub-fakturownia": <Receipt size={16} />,
              "/docs/fchub-memberships": <Users size={16} />,
              "/docs/wc-fc": <ArrowRightLeft size={16} />,
              "/docs/fluentcart-api": <Braces size={16} />,
            };

            const descriptionMap: Record<string, string> = {
              "/docs/fchub": "FluentCommunity",
              "/docs/fchub-stream": "FluentCommunity",
              "/docs/fchub-chat": "FluentCommunity",
              "/docs/fchub-mobile": "FluentCommunity",
              "/docs/fchub-p24": "FluentCart",
              "/docs/fchub-fakturownia": "FluentCart",
              "/docs/fchub-memberships": "FluentCart",
              "/docs/wc-fc": "FluentCart",
              "/docs/fluentcart-api": "FluentCart REST API",
            };

            return {
              ...option,
              icon: iconMap[option.url] || option.icon,
              description: descriptionMap[option.url] || option.description,
            };
          },
        },
      }}
    >
      {children}
    </DocsLayout>
  );
}
