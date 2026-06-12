import type { MetadataRoute } from "next";

// Only the production Vercel deploy is indexable. Preview deploys (and local
// builds) emit `Disallow: /` so crawlers don't index duplicate docs content
// under a non-canonical preview URL.
export default function robots(): MetadataRoute.Robots {
  if (process.env.VERCEL_ENV !== "production") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  const siteUrl =
    process.env.NEXT_PUBLIC_SITE_URL ?? "https://docs.astrolabecloud.com";
  return {
    rules: { userAgent: "*", allow: "/" },
    sitemap: `${siteUrl}/sitemap.xml`,
  };
}
