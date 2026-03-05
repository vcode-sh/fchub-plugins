import { Calendar, Clock } from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { BlogCardMedia } from "./blog-card-media";
import { BlogCategoryBadge } from "./blog-category-badge";
import { fadeIn } from "./blog-listing.config";
import type { BlogPost } from "./blog-listing.types";
import { formatPostDate } from "./blog-listing.utils";

type BlogHeroPostProps = {
  post: BlogPost;
};

export function BlogHeroPost({ post }: BlogHeroPostProps) {
  return (
    <Link href={post.url} className="group block">
      <motion.div
        initial="hidden"
        animate="visible"
        variants={fadeIn}
        className="relative overflow-hidden rounded-b-2xl sm:rounded-b-3xl"
      >
        <div className="relative aspect-[21/9] sm:aspect-[21/8] w-full">
          {post.video || post.image ? (
            <BlogCardMedia post={post} sizes="100vw" />
          ) : (
            <div className="absolute inset-0 bg-gradient-to-br from-blue-950 to-purple-950" />
          )}
          <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent" />
        </div>
        <div className="absolute bottom-0 left-0 right-0 p-6 sm:p-8 md:p-10">
          <div className="max-w-2xl">
            <div className="flex flex-wrap items-center gap-2 mb-3">
              <BlogCategoryBadge category={post.category} />
              <span className="flex items-center gap-1 text-xs text-white/60">
                <Calendar size={12} />
                {formatPostDate(post.date, "d MMM yyyy")}
              </span>
              <span className="flex items-center gap-1 text-xs text-white/60">
                <Clock size={12} />
                {post.readingTime} min read
              </span>
            </div>
            <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-white mb-2">
              {post.title}
            </h2>
            {post.description && (
              <p className="text-sm sm:text-base text-white/70 line-clamp-2 max-w-xl">
                {post.description}
              </p>
            )}
          </div>
        </div>
      </motion.div>
    </Link>
  );
}
