"use client";

import {
  CheckCircle,
  Code,
  ExternalLink,
  GitPullRequest,
  Globe,
  Languages,
  Package,
  Shield,
} from "lucide-react";
import { motion } from "motion/react";
import { Button } from "@/components/ui/button";

const GITHUB_REPO = "https://github.com/vcode-sh/fchub-plugins";

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

const monorepoChecks = [
  "Extends FluentCart or FluentCommunity — no generic WordPress plugins, we're not wp.org",
  "GPL-compatible licence (GPLv2 or later preferred)",
  "Follows the repo code style — PSR-12, strict types, no unnecessary abstractions",
  "Has tests if the plugin does anything non-trivial (if it touches money, it has tests)",
  "No external service dependencies without clear docs — surprise API calls are not a feature",
  "You're OK with shared maintenance — others may send PRs to your plugin, that's the deal",
];

const communityChecks = [
  "Extends FluentCart or FluentCommunity",
  "GPL-compatible licence",
  "Has a public GitHub repo with a README that explains what the thing actually does",
  "Actively maintained — or at least you respond to issues before they grow cobwebs",
];

export default function ContributePage() {
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
          Help build the bits
          <br />
          nobody else will
        </h1>
        <p className="text-lg text-muted-foreground mb-4 max-w-2xl mx-auto text-balance">
          FCHub is not just my plugins — it&apos;s a platform for yours too.
          Built something for FluentCart or FluentCommunity? A payment gateway
          for your region, a shipping connector, something beautifully niche? It
          probably belongs here.
        </p>
      </motion.div>

      <motion.div
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="max-w-4xl w-full space-y-16"
      >
        {/* Submit Your Plugin */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            Submit Your Plugin
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div className="border rounded-md p-6 space-y-3">
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                  <Package className="size-4" />
                </div>
                <h3 className="font-medium">Merge into monorepo</h3>
              </div>
              <p className="text-sm text-muted-foreground text-balance">
                Your plugin moves into{" "}
                <code className="text-xs bg-muted px-1.5 py-0.5 rounded">
                  plugins/
                </code>{" "}
                and gets the full treatment — CI, automated releases,
                distribution ZIPs, docs on the site, and other developers
                helping maintain it. The trade-off: your code follows the
                repo&apos;s conventions and anyone can send PRs. That&apos;s not
                a bug. That&apos;s the point.
              </p>
            </div>
            <div className="border rounded-md p-6 space-y-3">
              <div className="flex items-center gap-2">
                <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                  <Globe className="size-4" />
                </div>
                <h3 className="font-medium">Community listing</h3>
              </div>
              <p className="text-sm text-muted-foreground text-balance">
                Keep it in your own repo, on your own terms. FCHub lists it on
                the site as a community plugin — you stay in control, we give
                you visibility. For people who want the audience without the
                code reviews.
              </p>
            </div>
          </div>
          <div className="flex justify-center">
            <Button
              variant="outline"
              size="lg"
              render={
                <a
                  href={`${GITHUB_REPO}/issues/new?title=Plugin+submission:+&labels=plugin-submission`}
                  target="_blank"
                  rel="noopener noreferrer"
                />
              }
            >
              <GitPullRequest />
              Open a GitHub Issue
            </Button>
          </div>
        </motion.section>

        {/* Acceptance Criteria */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            The Bar You Need to Clear
          </h2>
          <p className="text-sm text-muted-foreground mb-6">
            Not trying to be gatekeepers. Just trying to keep the repo from
            turning into a graveyard of abandoned experiments.
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="space-y-4">
              <h3 className="font-medium flex items-center gap-2">
                <Package className="size-4 text-muted-foreground" />
                To merge into the monorepo
              </h3>
              <ul className="space-y-2">
                {monorepoChecks.map((check) => (
                  <li
                    key={check}
                    className="flex items-start gap-2 text-sm text-muted-foreground"
                  >
                    <CheckCircle className="size-4 shrink-0 mt-0.5 text-green-500" />
                    <span>{check}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="space-y-4">
              <h3 className="font-medium flex items-center gap-2">
                <Globe className="size-4 text-muted-foreground" />
                To be listed as a community plugin
              </h3>
              <ul className="space-y-2">
                {communityChecks.map((check) => (
                  <li
                    key={check}
                    className="flex items-start gap-2 text-sm text-muted-foreground"
                  >
                    <CheckCircle className="size-4 shrink-0 mt-0.5 text-green-500" />
                    <span>{check}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </motion.section>

        {/* Contribute Code */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            Contribute Code
          </h2>
          <div className="border rounded-md p-6 space-y-4">
            <div className="flex items-center gap-2">
              <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                <Code className="size-4" />
              </div>
              <h3 className="font-medium">The usual drill, no surprises</h3>
            </div>
            <ul className="space-y-2 text-sm text-muted-foreground">
              <li className="flex items-start gap-2">
                <span className="text-muted-foreground font-mono">1.</span>
                Fork the repo, create a branch, do the thing
              </li>
              <li className="flex items-start gap-2">
                <span className="text-muted-foreground font-mono">2.</span>
                One thing per PR — no drive-by refactors, no &ldquo;while
                I&apos;m here&rdquo; cleanups
              </li>
              <li className="flex items-start gap-2">
                <span className="text-muted-foreground font-mono">3.</span>
                Follow existing patterns — PSR-12, strict types, Vue 3
                Composition API. Read the code before rewriting it.
              </li>
              <li className="flex items-start gap-2">
                <span className="text-muted-foreground font-mono">4.</span>
                Open a PR against{" "}
                <code className="text-xs bg-muted px-1.5 py-0.5 rounded">
                  main
                </code>
                . That&apos;s it. No cover letter required.
              </li>
            </ul>
            <div className="flex gap-3 pt-2">
              <Button
                variant="outline"
                size="sm"
                render={
                  <a
                    href={`${GITHUB_REPO}/blob/main/CONTRIBUTING.md`}
                    target="_blank"
                    rel="noopener noreferrer"
                  />
                }
              >
                <ExternalLink />
                CONTRIBUTING.md
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
                <ExternalLink />
                Issue Templates
              </Button>
            </div>
          </div>
        </motion.section>

        {/* Translations */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            Translations
          </h2>
          <div className="border rounded-md p-6 space-y-3">
            <div className="flex items-center gap-2">
              <div className="flex items-center justify-center size-8 border rounded-sm bg-muted">
                <Languages className="size-4" />
              </div>
              <h3 className="font-medium">Help localise FCHub plugins</h3>
            </div>
            <p className="text-sm text-muted-foreground text-balance">
              Polish is at ~96% because someone had to do it. Other languages
              are at a round 0%. If you speak something other than English and
              fancy translating payment gateway strings for fun, we&apos;d love
              the help. No coding required — just language skills and patience.
            </p>
            <div className="pt-2">
              <Button
                variant="outline"
                size="sm"
                render={
                  <a
                    href={`${GITHUB_REPO}/tree/main/translations`}
                    target="_blank"
                    rel="noopener noreferrer"
                  />
                }
              >
                <ExternalLink />
                translations/
              </Button>
            </div>
          </div>
        </motion.section>

        {/* Code of Conduct */}
        <motion.section variants={itemVariants}>
          <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-6">
            Code of Conduct
          </h2>
          <div className="flex items-center gap-3 text-sm text-muted-foreground">
            <Shield className="size-4 shrink-0" />
            <p>
              Don&apos;t be terrible. The full version is more eloquent about
              it.{" "}
              <a
                href={`${GITHUB_REPO}/blob/main/CODE_OF_CONDUCT.md`}
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-4 hover:text-foreground transition-colors"
              >
                Read the Code of Conduct
              </a>
            </p>
          </div>
        </motion.section>

        {/* Footer */}
        <motion.div variants={itemVariants} className="text-center pb-8">
          <p className="text-xs text-muted-foreground">
            Open source &middot; GPLv2 &middot; Built by{" "}
            <a
              href="https://x.com/vcode_sh"
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
