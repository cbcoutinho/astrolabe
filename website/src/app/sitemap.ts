import type { MetadataRoute } from "next";

// Required under `output: export` so the route is materialised to a file.
export const dynamic = "force-static";

const SITE_URL = "https://astrolabecloud.com";

// Emitted as a static /sitemap.xml by the export. Docs are no longer part of
// this site — they live at docs.astrolabecloud.com with their own sitemap — so
// only the marketing routes are listed here. lastModified is omitted
// deliberately — Date.now() at build time would churn the file on every
// deploy and isn't meaningful for largely-static marketing pages.
export default function sitemap(): MetadataRoute.Sitemap {
  return [
    {
      url: `${SITE_URL}/`,
      changeFrequency: "monthly",
      priority: 1,
    },
    {
      url: `${SITE_URL}/product`,
      changeFrequency: "monthly",
      priority: 0.9,
    },
    {
      url: `${SITE_URL}/pricing`,
      changeFrequency: "monthly",
      priority: 0.8,
    },
  ];
}
