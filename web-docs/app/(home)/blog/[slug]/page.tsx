import type { Metadata } from "next";
import { notFound } from "next/navigation";
import Link from "next/link";
import { ArrowLeft, Calendar, Tag, User } from "lucide-react";
import { format, parseISO } from "date-fns";
import { DocsBody } from "fumadocs-ui/page";
import { Badge } from "@/components/ui/badge";
import { blogSource, getBlogPageImage } from "@/lib/source";
import { getMDXComponents } from "@/mdx-components";

const categoryLabels: Record<string, string> = {
  fluentcart: "FluentCart",
  fluentcommunity: "FluentCommunity",
  general: "General",
};

const categoryColors: Record<string, string> = {
  fluentcart: "bg-blue-500/15 text-blue-500 border-transparent",
  fluentcommunity: "bg-purple-500/15 text-purple-500 border-transparent",
  general: "bg-green-500/15 text-green-500 border-transparent",
};

export default async function BlogPostPage({
  params,
}: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const page = blogSource.getPage([slug]);
  if (!page) notFound();

  const MDXContent = page.data.body;
  const { category, author, date, tags } = page.data;

  return (
    <div className="flex flex-col items-center px-4 pt-12 pb-20">
      <article className="max-w-3xl w-full">
        <Link
          href="/blog"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors mb-8"
        >
          <ArrowLeft size={14} />
          Back to blog
        </Link>

        <header className="mb-10">
          <div className="flex items-center gap-2 mb-4">
            <Badge className={categoryColors[category]}>
              {categoryLabels[category] ?? category}
            </Badge>
            <span className="flex items-center gap-1 text-sm text-muted-foreground">
              <Calendar size={14} />
              {format(typeof date === "string" ? parseISO(date) : date, "d MMMM yyyy")}
            </span>
            <span className="flex items-center gap-1 text-sm text-muted-foreground">
              <User size={14} />
              {author}
            </span>
          </div>

          <h1 className="text-3xl md:text-4xl font-bold tracking-tight mb-3">
            {page.data.title}
          </h1>

          {page.data.description && (
            <p className="text-lg text-muted-foreground">
              {page.data.description}
            </p>
          )}

          {tags.length > 0 && (
            <div className="flex items-center gap-1.5 mt-4">
              <Tag size={14} className="text-muted-foreground" />
              {tags.map((tag) => (
                <Badge key={tag} variant="outline">
                  {tag}
                </Badge>
              ))}
            </div>
          )}
        </header>

        <DocsBody>
          <MDXContent components={getMDXComponents()} />
        </DocsBody>
      </article>
    </div>
  );
}

export function generateStaticParams() {
  return blogSource.getPages().map((page) => ({
    slug: page.slugs[0],
  }));
}

export async function generateMetadata({
  params,
}: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const page = blogSource.getPage([slug]);
  if (!page) notFound();

  const image = getBlogPageImage(page);

  return {
    title: page.data.title,
    description: page.data.description,
    openGraph: {
      title: page.data.title,
      description: page.data.description ?? undefined,
      type: "article",
      images: [{ url: image.url }],
    },
  };
}
