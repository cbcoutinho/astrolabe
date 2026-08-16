import type { Metadata } from "next";

import { LinkButton } from "@/components/button";
import { Container } from "@/components/container";
import { CheckMark } from "@/components/icons";
import { PageHeader } from "@/components/page-header";
import { OG_IMAGE_ALT, OG_IMAGE_PATH, SITE_URL } from "@/lib/site";

const DESCRIPTION =
  "A flat monthly fee per Nextcloud plus usage — metered on what you keep indexed, not on seats or storage tiers. Or let us help you self-host Astrolabe on your own hardware.";

export const metadata: Metadata = {
  title: "Pricing — Astrolabe Cloud",
  description: DESCRIPTION,
  // A page-level openGraph block replaces the parent's entirely (it is not
  // deep-merged) and suppresses the auto-merged root opengraph-image, so this
  // is self-contained: title/description/url + the generated card all set here.
  openGraph: {
    title: "Pricing — Astrolabe Cloud",
    description: DESCRIPTION,
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
// live plan lookup here. We describe the metering dimensions rather than
// publish rates — rates come from the Stripe catalog and would go stale here;
// the quote comes from us. Two ways to buy: managed, or help self-hosting.
export default function PricingPage() {
  const included = [
    "A dedicated managed Astrolabe backend for one Nextcloud",
    "AI semantic search across Notes, Files, Calendar, and Deck",
    "A hosted MCP endpoint for Claude, Cursor, or any MCP client",
    "Per-tenant vector index, kept in sync — no infrastructure to run",
    "EU hosting on Hetzner; your files stay on your own Nextcloud",
  ];

  // What we actually meter, in the order it shows up on an invoice. No rates:
  // see the comment above.
  const metered = [
    {
      title: "A flat monthly platform fee",
      body: "One fee per Nextcloud, covering the managed backend and the hosted MCP endpoint. It's the whole bill if you only use the MCP endpoint.",
    },
    {
      title: "Indexed content, per month",
      body: "Metered on what stays indexed — measured in chunks, the passages your content is split into, averaged over the month. Load or purge mid-month and it's pro-rated. Semantic and keyword-only indexes are metered at different rates; we'll quote you in pages, which is what you can count up front.",
    },
    {
      title: "OCR, per scanned page",
      body: "Scanned documents are OCR'd once on the way in and billed per page at cost-plus, so a born-digital corpus never subsidises a scanned one. Text and born-digital documents skip it entirely.",
    },
    {
      title: "No tiers, no seats",
      body: "One rate at every size — no volume bands to negotiate, no upgrade cliffs, no per-user charge. Keyword indexing is free to ingest and re-index.",
    },
  ];

  return (
    <Container size="lg" className="py-16 sm:py-20">
      <PageHeader
        eyebrow="Pricing"
        title="A flat fee, plus what you index."
        description="One Astrolabe backend per Nextcloud: a fixed monthly fee, then usage metered on the content you keep indexed. Access is still by invitation while the platform stabilises — reach out and we'll quote your corpus."
      />

      <div className="mt-10 grid gap-6 lg:grid-cols-2">
        <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
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

        <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
          <h2 className="text-lg font-semibold text-slate-900">
            How usage is metered
          </h2>
          <dl className="mt-5 space-y-5">
            {metered.map((item) => (
              <div key={item.title}>
                <dt className="text-sm font-medium text-slate-900">
                  {item.title}
                </dt>
                <dd className="mt-1 text-sm leading-relaxed text-slate-600">
                  {item.body}
                </dd>
              </div>
            ))}
          </dl>
        </div>
      </div>

      <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
        <h2 className="text-lg font-semibold text-slate-900">
          Rather run it yourself?
        </h2>
        <p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">
          Astrolabe is open source, and plenty of teams have hardware, a GPU, or
          a compliance requirement that says the index stays in-house. We help
          individuals and companies deploy it on their own infrastructure —
          sizing the vector index and OCR pipeline for your corpus, getting it
          running alongside your Nextcloud, and supporting it afterwards. Quoted
          per engagement, with an optional ongoing support agreement.
        </p>
        <div className="mt-8">
          <LinkButton
            href="mailto:hello@astrolabecloud.com?subject=Self-hosting%20Astrolabe"
            size="lg"
            variant="secondary"
          >
            Talk to us about self-hosting
          </LinkButton>
        </div>
      </div>
    </Container>
  );
}
