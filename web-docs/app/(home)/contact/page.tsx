"use client";

import { Bug, Github, Lightbulb, Send, Twitter } from "lucide-react";
import { motion } from "motion/react";
import { Button } from "@/components/ui/button";

const GITHUB_REPO = "https://github.com/vcode-sh/fchub-plugins";
const TELEGRAM_URL = "https://t.me/+s_-YxYytlelmMDM0";
const X_URL = "https://x.com/vcode_sh";

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: {
      staggerChildren: 0.1,
    },
  },
};

const itemVariants = {
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

const heroVariants = {
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

export default function ContactPage() {
  return (
    <div className="flex flex-col justify-center items-center flex-1 px-4 py-12">
      {/* Hero */}
      <motion.div
        initial="hidden"
        animate="visible"
        variants={heroVariants}
        className="max-w-4xl w-full text-center mb-16"
      >
        <h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
          There&apos;s no form
          <br />
          and I&apos;m not sorry
        </h1>
        <p className="text-lg text-muted-foreground mb-4 max-w-2xl mx-auto text-balance">
          No ticketing systems, no &ldquo;I&apos;ll get back to you in 3-5
          business days&rdquo;. Just real channels where actual humans respond.
        </p>
      </motion.div>

      <motion.div
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="max-w-4xl w-full space-y-16"
      >
        {/* Channel Cards */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            Get in Touch
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* GitHub Issues */}
            <div className="border rounded-md p-6 space-y-3">
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                  <Github className="size-4" />
                </div>
                <h3 className="font-medium">GitHub Issues</h3>
              </div>
              <p className="text-sm text-muted-foreground text-balance">
                Bug reports, feature requests, and the occasional existential
                crisis about code architecture. This is where the work happens.
              </p>
              <div className="flex flex-wrap gap-2 pt-2">
                <Button
                  variant="outline"
                  size="sm"
                  render={
                    <a
                      href={`${GITHUB_REPO}/issues/new/choose`}
                      target="_blank"
                      rel="noopener noreferrer"
                    />
                  }
                >
                  <Bug className="size-3" />
                  Report a Bug
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  render={
                    <a
                      href={`${GITHUB_REPO}/issues/new/choose`}
                      target="_blank"
                      rel="noopener noreferrer"
                    />
                  }
                >
                  <Lightbulb className="size-3" />
                  Request a Feature
                </Button>
              </div>
            </div>

            {/* Telegram */}
            <div className="border rounded-md p-6 space-y-3">
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                  <Send className="size-4" />
                </div>
                <h3 className="font-medium">Telegram</h3>
              </div>
              <p className="text-sm text-muted-foreground text-balance">
                The community chat. Quick questions, plugin discussions, and
                general banter about FluentCart&apos;s lack of documentation.
              </p>
              <div className="pt-2">
                <Button
                  variant="outline"
                  size="sm"
                  render={
                    <a
                      href={TELEGRAM_URL}
                      target="_blank"
                      rel="noopener noreferrer"
                    />
                  }
                >
                  <Send className="size-3" />
                  Join the Group
                </Button>
              </div>
            </div>

            {/* X / Twitter */}
            <div className="border rounded-md p-6 space-y-3">
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                  <Twitter className="size-4" />
                </div>
                <h3 className="font-medium">X / Twitter</h3>
              </div>
              <p className="text-sm text-muted-foreground text-balance">
                Release announcements, the odd plugin demo, and mildly
                opinionated takes on the WordPress ecosystem.
              </p>
              <div className="pt-2">
                <Button
                  variant="outline"
                  size="sm"
                  render={
                    <a href={X_URL} target="_blank" rel="noopener noreferrer" />
                  }
                >
                  <Twitter className="size-3" />
                  Follow @vcode_sh
                </Button>
              </div>
            </div>
          </div>
        </motion.section>

        {/* Footer */}
        <motion.div variants={itemVariants} className="text-center pb-8">
          <p className="text-xs text-muted-foreground">
            Open source &middot; GPLv2 &middot; Built by{" "}
            <a
              href={X_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="underline underline-offset-4 hover:text-foreground transition-colors"
            >
              Vibe Code
            </a>
          </p>
        </motion.div>
      </motion.div>
    </div>
  );
}
