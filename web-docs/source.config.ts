import {
  defineCollections,
  defineConfig,
  defineDocs,
  frontmatterSchema,
  metaSchema,
} from "fumadocs-mdx/config";
import { z } from "zod";

// You can customise Zod schemas for frontmatter and `meta.json` here
// see https://fumadocs.dev/docs/mdx/collections
export const docs = defineDocs({
  dir: "content/docs",
  docs: {
    schema: frontmatterSchema,
    files: ["**/*.mdx", "!**/_*/**"],
    postprocess: {
      includeProcessedMarkdown: true,
    },
  },
  meta: {
    schema: metaSchema,
  },
});

export const blogPosts = defineCollections({
  type: "doc",
  dir: "content/blog",
  schema: frontmatterSchema.extend({
    author: z.string().default("Vibe Code"),
    date: z.string().date().or(z.date()),
    category: z
      .enum(["fluentcart", "fluentcommunity", "general"])
      .default("general"),
    tags: z.array(z.string()).default([]),
    image: z.string().optional(),
    video: z.string().optional(),
    featured: z.boolean().default(false),
    pinned: z.boolean().default(false),
    pinOrder: z.number().optional(),
  }),
});

export default defineConfig({
  mdxOptions: {
    // MDX options
  },
});
