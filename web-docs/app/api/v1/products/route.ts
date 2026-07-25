import { createHash } from "node:crypto";
import catalogue from "@/lib/fchub-catalog.json";

// Public product catalogue for FCHub site discovery. Serves the committed,
// schema-versioned catalogue built by scripts/sync-fchub-catalog.mjs. Cached
// at the CDN; force-dynamic only so the route can evaluate If-None-Match.
export const dynamic = "force-dynamic";

const body = JSON.stringify(catalogue);
const etag = `"${createHash("sha256").update(body).digest("hex")}"`;
const cacheControl =
  "public, max-age=300, s-maxage=3600, stale-while-revalidate=86400";

export async function GET(request: Request) {
  if (request.headers.get("if-none-match") === etag) {
    return new Response(null, {
      status: 304,
      headers: {
        "Cache-Control": cacheControl,
        ETag: etag,
      },
    });
  }

  return new Response(body, {
    status: 200,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "Cache-Control": cacheControl,
      ETag: etag,
    },
  });
}
