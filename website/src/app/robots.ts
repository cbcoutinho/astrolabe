import type { MetadataRoute } from "next";

// Required under `output: export` so the route is materialised to a file.
export const dynamic = "force-static";

const SITE_URL = "https://astrolabecloud.com";

// Emitted as a static /robots.txt by the export. Permissive — this is a public
// marketing site — and points crawlers at the sitemap.
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
    },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
