import type { MetadataRoute } from "next";

import { source } from "@/lib/source";

// Production domain by default; override for non-prod deploys via env so a
// preview's sitemap points at the preview rather than production.
const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL ?? "https://docs.astrolabecloud.com";

// Emitted as /sitemap.xml. Generated from the docs source so new pages are
// discovered automatically as content grows (the root "/" just 308-redirects
// to /docs, so it's intentionally not listed here).
export default function sitemap(): MetadataRoute.Sitemap {
  return source.getPages().map((page) => ({
    url: `${SITE_URL}${page.url}`,
    changeFrequency: "monthly",
    // The intro page (/) is the entry point; leaf pages rank a touch lower.
    priority: page.url === "/" ? 1 : 0.8,
  }));
}
