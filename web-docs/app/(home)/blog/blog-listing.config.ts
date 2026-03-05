export const categoryLabels: Record<string, string> = {
  fluentcart: "FluentCart",
  fluentcommunity: "FluentCommunity",
  general: "General",
};

export const categoryColors: Record<string, string> = {
  fluentcart:
    "bg-blue-500/15 text-blue-600 dark:text-blue-400 border-transparent",
  fluentcommunity:
    "bg-purple-500/15 text-purple-600 dark:text-purple-400 border-transparent",
  general:
    "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-transparent",
};

export const dotColors: Record<string, string> = {
  fluentcart: "bg-blue-500",
  fluentcommunity: "bg-purple-500",
  general: "bg-emerald-500",
};

export const categories = [
  { value: "all", label: "All Posts" },
  { value: "fluentcart", label: "FluentCart" },
  { value: "fluentcommunity", label: "FluentCommunity" },
  { value: "general", label: "General" },
] as const;

export type BlogFilterCategory = (typeof categories)[number]["value"];

export const fadeIn = {
  hidden: { opacity: 0, transform: "translateY(16px)" },
  visible: {
    opacity: 1,
    transform: "translateY(0px)",
    transition: { duration: 0.35, ease: [0.25, 0.1, 0.25, 1] as const },
  },
};

export const stagger = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: { staggerChildren: 0.08 } },
};
