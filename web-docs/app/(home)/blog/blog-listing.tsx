"use client";

import { motion } from "motion/react";
import { useMemo, useState } from "react";
import { BlogCategoryFilter } from "./blog-category-filter";
import { BlogEmptyState } from "./blog-empty-state";
import { BlogHeroPost } from "./blog-hero-post";
import { type BlogFilterCategory, fadeIn } from "./blog-listing.config";
import type { BlogPost } from "./blog-listing.types";
import { filterPostsByCategory, sortPinnedPosts } from "./blog-listing.utils";
import { BlogPinnedSection } from "./blog-pinned-section";
import { BlogSectionDivider } from "./blog-section-divider";
import { BlogTimeline } from "./blog-timeline";

type BlogListingProps = {
  posts: BlogPost[];
};

export function BlogListing({ posts }: BlogListingProps) {
  const [activeCategory, setActiveCategory] =
    useState<BlogFilterCategory>("all");
  const [pinnedOpen, setPinnedOpen] = useState(true);

  const featuredPost = useMemo(
    () => posts.find((post) => post.featured) ?? null,
    [posts],
  );

  const pinnedPosts = useMemo(
    () => sortPinnedPosts(posts.filter((post) => post.pinned)),
    [posts],
  );

  const timelinePosts = useMemo(
    () => posts.filter((post) => !post.featured),
    [posts],
  );

  const filteredTimeline = useMemo(
    () => filterPostsByCategory(timelinePosts, activeCategory),
    [timelinePosts, activeCategory],
  );

  const nothingVisible =
    !featuredPost && pinnedPosts.length === 0 && filteredTimeline.length === 0;

  return (
    <div className="pb-20">
      {featuredPost && <BlogHeroPost post={featuredPost} />}

      <div className="max-w-5xl mx-auto px-4 pt-10">
        <motion.div
          initial="hidden"
          animate="visible"
          variants={fadeIn}
          className={featuredPost ? "" : "text-center mb-12 pt-12"}
        >
          {!featuredPost && (
            <>
              <h1 className="text-4xl md:text-5xl font-bold mb-4 tracking-tight">
                Blog
              </h1>
              <p className="text-muted-foreground text-lg mb-8">
                Release notes, tutorials, and the occasional hot take on the
                WordPress ecosystem.
              </p>
            </>
          )}

          <BlogCategoryFilter
            activeCategory={activeCategory}
            onChange={setActiveCategory}
          />
        </motion.div>

        <BlogPinnedSection
          posts={pinnedPosts}
          open={pinnedOpen}
          onToggle={() => setPinnedOpen((value) => !value)}
        />

        {filteredTimeline.length > 0 && (
          <div>
            <BlogSectionDivider>
              <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                All Posts
              </span>
            </BlogSectionDivider>
            <BlogTimeline posts={filteredTimeline} />
          </div>
        )}

        {nothingVisible && <BlogEmptyState />}
      </div>
    </div>
  );
}
