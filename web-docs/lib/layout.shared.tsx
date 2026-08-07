import type { BaseLayoutProps } from "fumadocs-ui/layouts/shared";
import { Send } from "lucide-react";
import Image from "next/image";
import { XIcon } from "@/components/brand-icons";

export function baseOptions(): BaseLayoutProps {
  return {
    nav: {
      title: (
        <div className="flex items-center gap-2">
          <Image
            src="/fchub-icon.webp"
            alt="FCHub"
            width={24}
            height={24}
            className="size-6"
          />
          <span className="font-semibold">FCHub.co</span>
        </div>
      ),
    },
    githubUrl: "https://github.com/vcode-sh/fchub-plugins",
    links: [
      {
        type: "icon",
        label: "Follow on X",
        icon: <XIcon size={18} />,
        text: "X",
        url: "https://x.com/vcode_sh",
      },
      {
        type: "icon",
        label: "Join Telegram Group",
        icon: <Send size={18} />,
        text: "Telegram",
        url: "https://t.me/+s_-YxYytlelmMDM0",
      },
    ],
  };
}
