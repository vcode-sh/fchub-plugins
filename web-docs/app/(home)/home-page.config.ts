export const GITHUB_REPOSITORY_URL =
  "https://github.com/vcode-sh/fchub-plugins";
export const TELEGRAM_COMMUNITY_URL = "https://t.me/+s_-YxYytlelmMDM0";
export const FLUENTCART_API_URL = "https://dev.fluentcart.com/restapi/";

export const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.1,
    },
  },
};

export const itemVariants = {
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

export const heroVariants = {
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
