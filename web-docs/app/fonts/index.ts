import localFont from "next/font/local";

// Acid Grotesk - Custom font family with multiple weights
export const acidGrotesk = localFont({
  src: [
    {
      path: "./AcidGrotesk-Thin.woff2",
      weight: "100",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-ExtraLight.woff2",
      weight: "200",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-Light.woff2",
      weight: "300",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-Regular.woff2",
      weight: "400",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-Normal.woff2",
      weight: "450",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-Medium.woff2",
      weight: "500",
      style: "normal",
    },
    {
      path: "./AcidGrotesk-Bold.woff2",
      weight: "700",
      style: "normal",
    },
  ],
  variable: "--font-acid-grotesk",
  display: "swap",
  fallback: ["system-ui", "sans-serif"],
});
