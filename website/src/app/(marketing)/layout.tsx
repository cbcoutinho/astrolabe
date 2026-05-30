import Script from "next/script";

import { LinkButton } from "@/components/button";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { portalSignInUrl } from "@/lib/portal";

const nav = [{ label: "Pricing", href: "/pricing" }];

export default function MarketingLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col">
      <SiteHeader
        nav={nav}
        right={
          <LinkButton href={portalSignInUrl()} size="sm">
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
