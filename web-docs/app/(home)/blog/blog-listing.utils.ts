import { format, parseISO } from "date-fns";
import type { BlogFilterCategory } from "./blog-listing.config";
import type { BlogPost } from "./blog-listing.types";

export function formatPostDate(date: string, pattern: string): string {
  return format(parseISO(date), pattern);
}

export function filterPostsByCategory(
  posts: BlogPost[],
  activeCategory: BlogFilterCategory,
): BlogPost[] {
  if (activeCategory === "all") {
    return posts;
  }

  return posts.filter((post) => post.category === activeCategory);
}

export function sortPinnedPosts(posts: BlogPost[]): BlogPost[] {
  return [...posts].sort((a, b) => (a.pinOrder ?? 99) - (b.pinOrder ?? 99));
}
