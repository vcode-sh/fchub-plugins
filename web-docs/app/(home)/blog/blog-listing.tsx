"use client";

import { format, parseISO } from "date-fns";
import { ArrowRight, Calendar, Tag, User } from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";

type BlogPost = {
  title: string;
  description: string;
  url: string;
  slug: string;
  author: string;
  date: string;
  category: string;
  tags: string[];
};

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

const categories = [
  { value: "all", label: "All Posts" },
  { value: "fluentcart", label: "FluentCart" },
  { value: "fluentcommunity", label: "FluentCommunity" },
  { value: "general", label: "General" },
] as const;

const categoryLabels: Record<string, string> = {
  fluentcart: "FluentCart",
  fluentcommunity: "FluentCommunity",
  general: "General",
};

const categoryColors: Record<string, string> = {
  fluentcart:
    "bg-blue-500/15 text-blue-600 dark:text-blue-400 border-transparent",
  fluentcommunity:
    "bg-purple-500/15 text-purple-600 dark:text-purple-400 border-transparent",
  general:
    "bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-transparent",
};

function PostCard({ post }: { post: BlogPost }) {
  return (
    <Link href={post.url} className="group block">
      <article className="rounded-xl border border-foreground/10 bg-card p-6 transition-all hover:border-primary/30 hover:shadow-sm">
        <div className="flex items-center gap-3 mb-3">
          <Badge className={categoryColors[post.category]}>
            {categoryLabels[post.category] ?? post.category}
          </Badge>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Calendar size={12} />
            {format(parseISO(post.date), "d MMM yyyy")}
          </span>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <User size={12} />
            {post.author}
          </span>
        </div>

        <h2 className="text-xl font-semibold tracking-tight mb-2 group-hover:text-primary transition-colors">
          {post.title}
        </h2>

        <p className="text-muted-foreground leading-relaxed mb-4">
          {post.description}
        </p>

        <div className="flex items-center justify-between">
          {post.tags.length > 0 && (
            <div className="flex items-center gap-1.5">
              <Tag size={12} className="text-muted-foreground" />
              {post.tags.map((tag) => (
                <Badge key={tag} variant="outline" className="text-[10px] h-4">
                  {tag}
                </Badge>
              ))}
            </div>
          )}
          <span className="ml-auto flex items-center gap-1 text-sm text-primary opacity-0 group-hover:opacity-100 transition-opacity">
            Read
            <ArrowRight size={14} />
          </span>
        </div>
      </article>
    </Link>
  );
}

export function BlogListing({ posts }: { posts: BlogPost[] }) {
  const [activeCategory, setActiveCategory] = useState("all");

  const filtered = posts.filter(
    (post) => activeCategory === "all" || post.category === activeCategory,
  );

  return (
    <div className="flex flex-col items-center px-4 pt-12 pb-20">
      <motion.div
        initial="hidden"
        animate="visible"
        variants={heroVariants}
        className="max-w-4xl w-full text-center mb-16"
      >
        <h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
          Blog
        </h1>
        <p className="text-muted-foreground text-lg">
          Release notes, tutorials, and the occasional hot take on the WordPress
          ecosystem.
        </p>
      </motion.div>

      <motion.div
        initial="hidden"
        animate="visible"
        variants={itemVariants}
        className="max-w-4xl w-full"
      >
        <div className="flex items-center gap-2 mb-8">
          {categories.map((cat) => (
            <button
              key={cat.value}
              type="button"
              onClick={() => setActiveCategory(cat.value)}
              className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
                activeCategory === cat.value
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted text-muted-foreground hover:text-foreground"
              }`}
            >
              {cat.label}
            </button>
          ))}
        </div>

        <motion.div
          key={activeCategory}
          initial="hidden"
          animate="visible"
          variants={containerVariants}
          className="space-y-4"
        >
          {filtered.map((post) => (
            <motion.div key={post.slug} variants={itemVariants}>
              <PostCard post={post} />
            </motion.div>
          ))}
          {filtered.length === 0 && (
            <div className="text-center py-16 text-muted-foreground">
              <p className="text-lg">Nothing here yet.</p>
              <p className="text-sm mt-1">
                Check back soon — or don't. We're not your mum.
              </p>
            </div>
          )}
        </motion.div>
      </motion.div>
    </div>
  );
}
