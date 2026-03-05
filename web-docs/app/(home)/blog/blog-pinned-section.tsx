import { Calendar, ChevronDown, Clock, Pin } from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { BlogCardMedia } from "./blog-card-media";
import { BlogCategoryBadge } from "./blog-category-badge";
import { fadeIn, stagger } from "./blog-listing.config";
import type { BlogPost } from "./blog-listing.types";
import { formatPostDate } from "./blog-listing.utils";

type BlogPinnedSectionProps = {
  posts: BlogPost[];
  open: boolean;
  onToggle: () => void;
};

type PinnedCardProps = {
  post: BlogPost;
};

function PinnedCard({ post }: PinnedCardProps) {
  return (
    <Link href={post.url} className="group block h-full">
      <article className="rounded-xl border border-foreground/10 bg-card overflow-hidden transition-all hover:border-primary/30 hover:shadow-sm h-full flex flex-col">
        {(post.image || post.video) && (
          <div className="relative aspect-[16/9] overflow-hidden bg-muted">
            <BlogCardMedia
              post={post}
              sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
            />
          </div>
        )}
        <div className="flex flex-col flex-1 p-4">
          <div className="flex flex-wrap items-center gap-2 mb-2">
            <BlogCategoryBadge category={post.category} />
            <span className="inline-flex items-center gap-1 text-[10px] text-amber-500/80 font-medium">
              <Pin size={10} /> Pinned
            </span>
          </div>
          <div className="flex-1">
            <h3 className="font-semibold tracking-tight mb-1.5 group-hover:text-primary transition-colors line-clamp-2">
              {post.title}
            </h3>
            <p className="text-sm text-muted-foreground line-clamp-2">
              {post.description || "\u00A0"}
            </p>
          </div>
          <div className="mt-3 flex items-center gap-3 text-xs text-muted-foreground">
            <span className="flex items-center gap-1">
              <Calendar size={11} />
              {formatPostDate(post.date, "d MMM yyyy")}
            </span>
            <span className="flex items-center gap-1">
              <Clock size={11} />
              {post.readingTime} min
            </span>
          </div>
        </div>
      </article>
    </Link>
  );
}

export function BlogPinnedSection({
  posts,
  open,
  onToggle,
}: BlogPinnedSectionProps) {
  if (posts.length === 0) {
    return null;
  }

  return (
    <div className="mb-12">
      <button
        type="button"
        onClick={onToggle}
        className="flex items-center gap-2 mb-4 w-full"
      >
        <Pin size={14} className="text-amber-500/70" />
        <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Pinned
        </span>
        <div className="flex-1 h-px bg-foreground/5" />
        <ChevronDown
          size={14}
          className={`text-muted-foreground transition-transform duration-200 ${
            open ? "rotate-0" : "-rotate-90"
          }`}
        />
      </button>
      {open && (
        <motion.div initial="hidden" animate="visible" variants={stagger}>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {posts.map((post) => (
              <motion.div key={post.slug} variants={fadeIn}>
                <PinnedCard post={post} />
              </motion.div>
            ))}
          </div>
        </motion.div>
      )}
    </div>
  );
}
