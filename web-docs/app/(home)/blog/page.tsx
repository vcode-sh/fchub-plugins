import { blogSource } from "@/lib/source";
import { BlogListing } from "./blog-listing";

function estimateReadingTime(text: string): number {
  const words = text.trim().split(/\s+/).length;
  return Math.max(1, Math.round(words / 230));
}

export default async function BlogPage() {
  const pages = blogSource.getPages().sort((a, b) => {
    const dateA = new Date(a.data.date);
    const dateB = new Date(b.data.date);
    return dateB.getTime() - dateA.getTime();
  });

  const posts = await Promise.all(
    pages.map(async (post) => {
      const text = await post.data.getText("raw");
      return {
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
        image: post.data.image,
        video: post.data.video,
        readingTime: estimateReadingTime(text),
      };
    }),
  );

  return <BlogListing posts={posts} />;
}
