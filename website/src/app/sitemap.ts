import type { MetadataRoute } from "next";

// Required under `output: export` so the route is materialised to a file.
export const dynamic = "force-static";

const SITE_URL = "https://astrolabecloud.com";

// Emitted as a static /sitemap.xml by the export. The site is two static
// routes; add entries here as pages are added. lastModified is omitted
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
      url: `${SITE_URL}/pricing`,
      changeFrequency: "monthly",
      priority: 0.8,
    },
  ];
}
