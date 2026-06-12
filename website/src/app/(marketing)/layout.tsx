import Script from "next/script";

import { LinkButton } from "@/components/button";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { portalSignInUrl } from "@/lib/portal";

const nav = [
  // Docs live on their own Vercel-hosted site at docs.astrolabecloud.com,
  // separate from this GitHub Pages marketing site.
  { label: "Docs", href: "https://docs.astrolabecloud.com", external: true },
  { label: "Pricing", href: "/pricing" },
];

export default function MarketingLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <div className="flex min-h-screen flex-col">
      <SiteHeader
        nav={nav}
        right={
          <LinkButton href={portalSignInUrl()} size="sm" external>
            Sign in
          </LinkButton>
        }
      />
      <main className="flex-1">{children}</main>
      <SiteFooter />
      {/* Hydrates the `data-tally-src` iframe in the #waitlist section and
          drives its dynamicHeight. afterInteractive keeps it off the critical
          render path. */}
      <Script
        src="https://tally.so/widgets/embed.js"
        strategy="afterInteractive"
      />
    </div>
  );
}
