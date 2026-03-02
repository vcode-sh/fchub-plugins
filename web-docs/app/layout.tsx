import { RootProvider } from "fumadocs-ui/provider/next";
import type { Metadata } from "next";
import { Geist_Mono } from "next/font/google";
import { acidGrotesk } from "./fonts";
import "./global.css";

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_URL || "http://localhost:3000"),
  title: {
    default: "FCHub — WordPress Plugins for FluentCart & FluentCommunity",
    template: "%s | FCHub",
  },
  description:
    "Payments, invoicing, memberships, video streaming, and migration plugins for FluentCart and FluentCommunity. Open source, GPLv2.",
  openGraph: {
    type: "website",
    siteName: "FCHub",
    title: "FCHub — WordPress Plugins for FluentCart & FluentCommunity",
    description:
      "Payments, invoicing, memberships, video streaming, and migration plugins for FluentCart and FluentCommunity. Open source, GPLv2.",
    images: "/fchub-share.jpg",
  },
  twitter: {
    card: "summary_large_image",
    creator: "@vcode_sh",
    images: "/fchub-share.jpg",
  },
  icons: {
    icon: "/fchub-icon.webp",
    apple: "/fchub-icon.webp",
  },
};

export default function Layout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${acidGrotesk.variable} ${geistMono.variable}`}
      suppressHydrationWarning
    >
      <body className="flex flex-col min-h-screen font-sans antialiased">
        <RootProvider
          theme={{
            attribute: "class",
            defaultTheme: "dark",
            enableSystem: false,
            value: { dark: "dark" },
          }}
        >
          {children}
        </RootProvider>
      </body>
    </html>
  );
}
