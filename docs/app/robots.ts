import type { MetadataRoute } from "next";

const SITE_URL =
  process.env.NEXT_PUBLIC_SITE_URL ?? "https://docs.astrolabecloud.com";

// Only the production Vercel deploy is indexable. Preview deploys (and local
// builds) emit `Disallow: /` so crawlers don't index duplicate docs content
// under a non-canonical preview URL.
export default function robots(): MetadataRoute.Robots {
  if (process.env.VERCEL_ENV !== "production") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: { userAgent: "*", allow: "/" },
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
