"use client";

import { Send } from "lucide-react";
import { motion } from "motion/react";
import { Button } from "@/components/ui/button";
import {
  GITHUB_REPOSITORY_URL,
  heroVariants,
  TELEGRAM_COMMUNITY_URL,
} from "./home-page.config";

export function HomeHero() {
  return (
    <motion.div
      initial="hidden"
      animate="visible"
      variants={heroVariants}
      className="max-w-4xl w-full text-center mb-16"
    >
      <h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
        WordPress plugins for people
        <br />
        who ship things
      </h1>
      <p className="text-lg text-muted-foreground mb-8 max-w-2xl mx-auto text-balance">
        Payments, invoicing, memberships, video, migrations — the bits
        FluentCart and FluentCommunity forgot to ship. So I did.
      </p>
      <div className="flex items-center justify-center gap-3 mb-4">
        <Button
          variant="default"
          size="lg"
          render={
            <a
              href={GITHUB_REPOSITORY_URL}
              target="_blank"
              rel="noopener noreferrer"
            />
          }
        >
          View on GitHub
        </Button>
        <Button
          variant="outline"
          size="lg"
          render={
            <a
              href={TELEGRAM_COMMUNITY_URL}
              target="_blank"
              rel="noopener noreferrer"
            />
          }
        >
          <Send />
          Join Telegram
        </Button>
      </div>
      <p className="text-xs text-muted-foreground">
        Open source · GPLv2 · Built by Vibe Code
      </p>
    </motion.div>
  );
}
