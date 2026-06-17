import type { Metadata } from "next";

import { LinkButton } from "@/components/button";
import { Container } from "@/components/container";
import { CheckMark } from "@/components/icons";
import { PageHeader } from "@/components/page-header";
import { OG_IMAGE_ALT, OG_IMAGE_PATH, SITE_URL } from "@/lib/site";

export const metadata: Metadata = {
  title: "Pricing — Astrolabe Cloud",
  description:
    "One managed Astrolabe backend per Nextcloud, with semantic search and a hosted MCP endpoint included. We're invite-only while we roll out — get in touch.",
  // A page-level openGraph block replaces the parent's entirely (it is not
  // deep-merged) and suppresses the auto-merged root opengraph-image, so this
  // is self-contained: correct canonical /pricing url + the generated card.
  openGraph: {
    url: `${SITE_URL}/pricing`,
    siteName: "Astrolabe Cloud",
    type: "website",
    images: [
      {
        url: OG_IMAGE_PATH,
        type: "image/png",
        width: 1200,
        height: 630,
        alt: OG_IMAGE_ALT,
      },
    ],
  },
  twitter: {
    card: "summary_large_image",
    images: [{ url: OG_IMAGE_PATH, alt: OG_IMAGE_ALT }],
  },
};

// Static page: the landing app has no control-plane access, so there is no
// live plan lookup here. Signups are invite-only during rollout, so rather
// than publish prices we point at a single contact path. Swap in tiers when
// public self-serve pricing launches.
export default function PricingPage() {
  const included = [
    "A dedicated managed Astrolabe backend for one Nextcloud",
    "AI semantic search across Notes, Files, Calendar, and Deck",
    "A hosted MCP endpoint for Claude, Cursor, or any MCP client",
    "Per-tenant vector index, kept in sync — no infrastructure to run",
    "EU hosting on Hetzner; your files stay on your own Nextcloud",
  ];

  return (
    <Container size="lg" className="py-16 sm:py-20">
      <PageHeader
        eyebrow="Pricing"
        title="Simple per-tenant pricing."
        description="One Astrolabe backend per Nextcloud. We're rolling out access by invitation while the platform stabilises, so reach out and we'll set you up with a plan that fits."
      />

      <div className="mt-10 max-w-2xl rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
        <h2 className="text-lg font-semibold text-slate-900">
          Every tenant includes
        </h2>
        <ul className="mt-5 space-y-3">
          {included.map((item) => (
            <li key={item} className="flex gap-3 text-sm text-slate-600">
              <CheckMark />
              <span className="leading-relaxed">{item}</span>
            </li>
          ))}
        </ul>
        <div className="mt-8">
          <LinkButton href="mailto:hello@astrolabecloud.com" size="lg">
            Contact us
          </LinkButton>
        </div>
      </div>
    </Container>
  );
}
