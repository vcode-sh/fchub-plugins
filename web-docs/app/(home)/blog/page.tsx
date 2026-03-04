import { blogSource } from "@/lib/source";
import { BlogListing } from "./blog-listing";

export default function BlogPage() {
  const posts = blogSource
    .getPages()
    .sort((a, b) => {
      const dateA = new Date(a.data.date);
      const dateB = new Date(b.data.date);
      return dateB.getTime() - dateA.getTime();
    })
    .map((post) => ({
      title: post.data.title,
      description: post.data.description ?? "",
      url: post.url,
      slug: post.slugs[0] ?? "",
      author: post.data.author,
      date:
        typeof post.data.date === "string"
          ? post.data.date
          : post.data.date.toISOString().split("T")[0],
      category: post.data.category,
      tags: post.data.tags,
    }));

  return <BlogListing posts={posts} />;
}
