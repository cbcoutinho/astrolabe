import type { MetadataRoute } from "next";

import { source } from "@/lib/source";

const SITE_URL = "https://docs.astrolabecloud.com";

// Emitted as /sitemap.xml. Generated from the docs source so new pages are
// discovered automatically as content grows (the root "/" just 308-redirects
// to /docs, so it's intentionally not listed here).
export default function sitemap(): MetadataRoute.Sitemap {
  return source.getPages().map((page) => ({
    url: `${SITE_URL}${page.url}`,
    changeFrequency: "monthly",
    priority: 0.8,
  }));
}
