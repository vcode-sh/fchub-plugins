import { Clock } from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { BlogCardMedia } from "./blog-card-media";
import { BlogCategoryBadge } from "./blog-category-badge";
import { dotColors, fadeIn, stagger } from "./blog-listing.config";
import type { BlogPost } from "./blog-listing.types";
import { formatPostDate } from "./blog-listing.utils";

type BlogTimelineProps = {
  posts: BlogPost[];
};

type TimelineCardProps = {
  post: BlogPost;
};

type TimelineEntryProps = {
  post: BlogPost;
  side: "left" | "right";
};

function TimelineCard({ post }: TimelineCardProps) {
  return (
    <Link href={post.url} className="group block">
      <article className="rounded-xl border border-foreground/10 bg-card overflow-hidden transition-all hover:border-primary/30 hover:shadow-sm">
        {post.image && (
          <div className="relative aspect-[16/9] overflow-hidden bg-muted">
            <BlogCardMedia
              post={post}
              sizes="(max-width: 768px) 100vw, 320px"
            />
          </div>
        )}
        <div className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <BlogCategoryBadge category={post.category} />
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Clock size={11} /> {post.readingTime} min read
            </span>
          </div>
          <h3 className="font-semibold tracking-tight mb-1 group-hover:text-primary transition-colors line-clamp-2">
            {post.title}
          </h3>
          {post.description && (
            <p className="text-sm text-muted-foreground line-clamp-2">
              {post.description}
            </p>
          )}
        </div>
      </article>
    </Link>
  );
}

function TimelineEntry({ post, side }: TimelineEntryProps) {
  const dateLabel = (
    <span className="text-xs text-muted-foreground mt-2">
      {formatPostDate(post.date, "d MMM")}
    </span>
  );

  return (
    <motion.div
      variants={fadeIn}
      className="relative flex items-start mb-8 last:mb-0"
    >
      <div className="hidden md:flex flex-1 items-start justify-end pr-6">
        {side === "right" ? dateLabel : <TimelineCard post={post} />}
      </div>
      <div
        className={`relative z-10 mt-2 size-2.5 rounded-full shrink-0 mx-3 ${
          dotColors[post.category] ?? "bg-foreground/30"
        } ring-4 ring-background`}
      />
      <div className="hidden md:flex flex-1 items-start pl-6">
        {side === "left" ? dateLabel : <TimelineCard post={post} />}
      </div>
      <div className="flex-1 pl-4 md:hidden">
        <span className="text-xs text-muted-foreground mb-2 block">
          {formatPostDate(post.date, "d MMM yyyy")}
        </span>
        <TimelineCard post={post} />
      </div>
    </motion.div>
  );
}

export function BlogTimeline({ posts }: BlogTimelineProps) {
  if (posts.length === 0) {
    return null;
  }

  return (
    <div className="relative max-w-4xl mx-auto">
      <div className="absolute left-4 md:left-1/2 top-0 bottom-0 w-px bg-foreground/10 md:-translate-x-px" />
      <motion.div initial="hidden" animate="visible" variants={stagger}>
        {posts.map((post, index) => (
          <TimelineEntry
            key={post.slug}
            post={post}
            side={index % 2 === 0 ? "right" : "left"}
          />
        ))}
      </motion.div>
    </div>
  );
}
