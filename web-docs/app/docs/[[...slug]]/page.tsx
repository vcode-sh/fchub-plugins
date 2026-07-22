import type { LoaderConfig, LoaderOutput } from "fumadocs-core/source";
import { Callout } from "fumadocs-ui/components/callout";
import { createRelativeLink } from "fumadocs-ui/mdx";
import {
  DocsBody,
  DocsDescription,
  DocsPage,
  DocsTitle,
} from "fumadocs-ui/page";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getPageImage, source } from "@/lib/source";
import { getMDXComponents } from "@/mdx-components";

export default async function Page(props: PageProps<"/docs/[[...slug]]">) {
  const params = await props.params;
  const page = source.getPage(params.slug);
  if (!page) notFound();

  const MDX = page.data.body;
  const isDiscontinuedStream = params.slug?.[0] === "fchub-stream";

  return (
    <DocsPage
      toc={page.data.toc}
      full={page.data.full}
      tableOfContent={{
        style: "clerk",
      }}
    >
      <DocsTitle>{page.data.title}</DocsTitle>
      <DocsDescription>{page.data.description}</DocsDescription>
      <DocsBody>
        {isDiscontinuedStream && (
          <Callout type="warn" title="Maintenance suspended indefinitely">
            FCHub Stream is discontinued and retained as-is. It receives no
            support, bug fixes, compatibility updates, security updates, or new
            releases. The GPLv2 source remains available for anyone who wants to
            fork it and continue independently. The project may return someday,
            but there is no roadmap or schedule.
          </Callout>
        )}
        <MDX
          components={getMDXComponents({
            // this allows you to link to other pages with relative file paths
            a: createRelativeLink(
              source as unknown as LoaderOutput<LoaderConfig>,
              page,
            ),
          })}
        />
      </DocsBody>
    </DocsPage>
  );
}

export async function generateStaticParams() {
  return source.generateParams();
}

export async function generateMetadata(
  props: PageProps<"/docs/[[...slug]]">,
): Promise<Metadata> {
  const params = await props.params;
  const page = source.getPage(params.slug);
  if (!page) notFound();

  return {
    title: page.data.title,
    description: page.data.description,
    openGraph: {
      images: getPageImage(page).url,
    },
  };
}
