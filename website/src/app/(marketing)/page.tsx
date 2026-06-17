import type { Metadata } from "next";
import Image from "next/image";

import { LinkButton } from "@/components/button";
import { Container } from "@/components/container";
import { CheckMark } from "@/components/icons";

const APPSTORE_URL = "https://apps.nextcloud.com/apps/astrolabe";
const GITHUB_URL = "https://github.com/cbcoutinho/astrolabe";

export const metadata: Metadata = {
  title: "Astrolabe Cloud — Semantic search & MCP for your Nextcloud",
  description:
    "Keyword search only finds the words you remember. Astrolabe Cloud adds AI semantic search across your Nextcloud and a hosted MCP endpoint — so Claude, Cursor, or any MCP client can act on your knowledge bank. Bring your own Nextcloud; we run the rest.",
  openGraph: {
    title: "Astrolabe Cloud — Semantic search & MCP for your Nextcloud",
    description:
      "Find your Nextcloud content by meaning, not keywords — and let any MCP client act on it. Bring your own Nextcloud; we run the index and the server.",
    url: "https://astrolabecloud.com",
    siteName: "Astrolabe Cloud",
    type: "website",
    // Declaring openGraph here overrides the parent metadata, so the
    // root opengraph-image.tsx is no longer auto-merged — reference it
    // explicitly (same generated card pricing inherits automatically).
    images: [
      {
        url: "/opengraph-image",
        type: "image/png",
        width: 1200,
        height: 630,
        alt: "Astrolabe Cloud — Semantic search & MCP for your Nextcloud",
      },
    ],
  },
  twitter: {
    card: "summary_large_image",
    images: ["/opengraph-image"],
  },
};

const features = [
  {
    title: "Bring your own Nextcloud",
    body: "Connect your existing Nextcloud — we don't host your files. Your data stays where it lives.",
    icon: IconCloud,
  },
  {
    title: "Find by meaning, not keywords",
    body: "Ask in plain language and Astrolabe surfaces the right notes, files, and events — even when they don't contain the words you typed. Results land in the Nextcloud search bar you already use.",
    icon: IconSearch,
  },
  {
    title: "MCP server, hosted",
    body: "Plug Claude, Cursor, or any MCP client into your knowledge bank — no self-hosting.",
    icon: IconPlug,
  },
];

const howSteps = [
  {
    n: "01",
    title: "Point us at your Nextcloud",
    body: "Register an OIDC client in your Nextcloud admin app and paste the credentials. Your files stay put.",
  },
  {
    n: "02",
    title: "We provision the backend",
    body: "A managed Astrolabe MCP server spins up on our cluster, with a per-tenant vector index and secret-managed credentials.",
  },
  {
    n: "03",
    title: "Connect your agent",
    body: "Add the MCP URL to Claude, Cursor, or any MCP client. Search and act against your knowledge bank.",
  },
];

// schema.org structured data so search engines can render rich results. Kept
// deliberately accurate to the product: no offers/price node while signups are
// invite-only. Rendered as a JSON-LD <script> per the Next.js guidance.
const jsonLd = {
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  name: "Astrolabe Cloud",
  description: "Semantic search and a managed MCP server for your Nextcloud.",
  applicationCategory: "DeveloperApplication",
  operatingSystem: "Web",
  url: "https://astrolabecloud.com",
};

export default function HomePage() {
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      {/* Hero on dark navy — problem first, then the fix, beside the product. */}
      <section className="bg-ink-900 text-white">
        <Container size="lg" className="py-20 sm:py-28">
          <div className="grid items-center gap-12 lg:grid-cols-2">
            <div>
              <p className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium uppercase tracking-wider text-brand-200">
                <span className="h-1.5 w-1.5 rounded-full bg-brand-300" />
                <span>Managed MCP for your Nextcloud</span>
              </p>
              <h1 className="mt-6 text-balance text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">
                Your Nextcloud knows more than its search bar lets on.
              </h1>
              <p className="mt-6 max-w-xl text-lg text-slate-300">
                Keyword search only finds the words you remember. Astrolabe
                Cloud adds AI semantic search across your notes, files, and
                calendar — and a hosted MCP endpoint so Claude, Cursor, or any
                MCP client can act on it. Bring your own Nextcloud; we run the
                index and the server.
              </p>
              <div className="mt-10 flex flex-wrap gap-3">
                <LinkButton href="#waitlist" size="lg" variant="inverse">
                  Join the waitlist
                </LinkButton>
                <LinkButton href="/pricing" size="lg" variant="ghost-inverse">
                  See pricing
                </LinkButton>
              </div>
            </div>
            <div className="relative">
              <Image
                src="/unified-search.png"
                alt="Astrolabe results inside Nextcloud's unified search bar — a plain-language query surfacing relevant files and Deck cards."
                width={1738}
                height={1103}
                priority
                sizes="(min-width: 1024px) 40vw, 90vw"
                className="rounded-xl border border-white/10 shadow-2xl ring-1 ring-white/5"
              />
            </div>
          </div>
        </Container>
        <HeroDivider />
      </section>

      {/* Features */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="grid gap-8 sm:grid-cols-3">
            {features.map((f) => {
              const Icon = f.icon;
              return (
                <div key={f.title}>
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                    <Icon />
                  </div>
                  <h2 className="mt-5 text-base font-semibold text-slate-900">
                    {f.title}
                  </h2>
                  <p className="mt-2 text-sm leading-relaxed text-slate-600">
                    {f.body}
                  </p>
                </div>
              );
            })}
          </div>
        </Container>
      </section>

      {/* Unified-search adoption story, illustrated with the vector view */}
      <section className="border-y border-slate-200 bg-slate-50/60 py-20 sm:py-24">
        <Container size="lg">
          <div className="grid items-center gap-12 lg:grid-cols-2">
            <div className="max-w-xl">
              <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
                Semantic search
              </p>
              <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                Search what you meant, not just what you typed.
              </h2>
              <p className="mt-4 text-base leading-relaxed text-slate-600">
                Astrolabe indexes your content in the background and searches it
                by meaning — hybrid semantic and keyword ranking — so &ldquo;the
                contract we signed in spring&rdquo; finds the right document even
                if it never used those words. The same index powers Nextcloud&apos;s
                unified search and every MCP client you connect.
              </p>
              <ul className="mt-6 space-y-2 text-sm text-slate-600">
                <li className="flex gap-2">
                  <CheckMark /> Notes, Files, Calendar, and Deck — one index.
                </li>
                <li className="flex gap-2">
                  <CheckMark /> Hybrid ranking: semantic recall, keyword
                  precision.
                </li>
                <li className="flex gap-2">
                  <CheckMark /> No Qdrant, no embedders, no ops on your side.
                </li>
              </ul>
            </div>
            <div>
              <Image
                src="/semantic-search-plot.png"
                alt="Astrolabe's semantic search results with an interactive vector-space visualization of how documents relate."
                width={1738}
                height={1103}
                sizes="(min-width: 1024px) 45vw, 90vw"
                className="rounded-xl border border-slate-200 shadow-card"
              />
            </div>
          </div>
        </Container>
      </section>

      {/* How it works */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="max-w-2xl">
            <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
              How it works
            </p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
              Bring your Nextcloud — we manage the rest.
            </h2>
            <p className="mt-4 text-base text-slate-600">
              You stay in control of your files. We run the parts that turn a
              Nextcloud into a searchable knowledge bank any MCP client can use.
            </p>
          </div>
          <ol className="mt-12 grid gap-8 sm:grid-cols-3">
            {howSteps.map((s) => (
              <li key={s.n}>
                <div className="font-mono text-sm font-medium text-brand-600">
                  {s.n}
                </div>
                <h3 className="mt-2 text-base font-semibold text-slate-900">
                  {s.title}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{s.body}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      {/* EU / Hetzner / sovereignty */}
      <section className="border-y border-slate-200 bg-slate-50/60 py-20 sm:py-24">
        <Container size="lg">
          <div className="grid items-start gap-12 lg:grid-cols-2">
            <div className="max-w-xl">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                <IconShield />
              </div>
              <h2 className="mt-5 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                Hosted in Europe, on Hetzner.
              </h2>
              <p className="mt-4 text-base leading-relaxed text-slate-600">
                The managed backend and your vector index run on Hetzner
                infrastructure in the EU — your data stays in Europe. And because
                you bring your own Nextcloud, your files never leave the server
                you already control. Sovereignty by architecture, not by promise.
              </p>
            </div>
            <ul className="grid gap-4 sm:grid-cols-2 lg:mt-2">
              <SovereigntyPoint title="EU data residency">
                Compute and storage on Hetzner in Germany &amp; Finland.
              </SovereigntyPoint>
              <SovereigntyPoint title="Your files stay home">
                We index your Nextcloud — we never host or copy your files.
              </SovereigntyPoint>
              <SovereigntyPoint title="Per-tenant isolation">
                A dedicated index and secret-managed credentials per tenant.
              </SovereigntyPoint>
              <SovereigntyPoint title="Open source core">
                The Astrolabe backend is open source — inspect it, or self-host.
              </SovereigntyPoint>
            </ul>
          </div>
        </Container>
      </section>

      {/* CTA */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="rounded-2xl bg-ink-900 px-6 py-12 text-white sm:px-12 sm:py-16">
            <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
              <div className="max-w-xl">
                <h2 className="text-balance text-2xl font-semibold tracking-tight sm:text-3xl">
                  Ready to plug your Nextcloud into your agents?
                </h2>
                <p className="mt-3 text-slate-300">
                  We&apos;re rolling out early-access tenants now. Join the
                  waitlist and we&apos;ll be in touch — or explore the open-source
                  app today.
                </p>
              </div>
              <div className="flex flex-wrap gap-3">
                <LinkButton href="#waitlist" size="lg" variant="inverse">
                  Join the waitlist
                </LinkButton>
                <LinkButton
                  href={APPSTORE_URL}
                  external
                  size="lg"
                  variant="ghost-inverse"
                >
                  Get the free Nextcloud app
                </LinkButton>
                <LinkButton
                  href={GITHUB_URL}
                  external
                  size="lg"
                  variant="ghost-inverse"
                >
                  View on GitHub
                </LinkButton>
              </div>
            </div>
          </div>
        </Container>
      </section>

      {/* Waitlist — Tally embed */}
      <section id="waitlist" className="pb-24 scroll-mt-16">
        <Container size="lg">
          <div className="max-w-2xl">
            <h2 className="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
              Get notified at launch
            </h2>
            <p className="mt-3 text-slate-600">
              Drop your email and we&apos;ll let you know when early-access
              tenants open up.
            </p>
          </div>
          <div className="mt-6 max-w-2xl">
            <iframe
              data-tally-src="https://tally.so/embed/ob0Wqx?alignLeft=1&hideTitle=1&transparentBackground=1&dynamicHeight=1"
              loading="lazy"
              width="100%"
              height={300}
              title="Astrolabe Cloud waitlist"
              // Defence-in-depth around the third-party embed: allow-scripts
              // drives Tally's dynamicHeight, allow-same-origin lets it reach
              // its own origin for the form post, allow-forms permits submit,
              // and allow-popups covers any post-submit redirect.
              sandbox="allow-forms allow-scripts allow-same-origin allow-popups"
            />
          </div>
        </Container>
      </section>
    </>
  );
}

function HeroDivider() {
  return (
    <svg
      aria-hidden
      viewBox="0 0 1440 60"
      preserveAspectRatio="none"
      className="block h-8 w-full text-ink-900"
    >
      <path d="M0 0h1440v40c-240 13-480 20-720 20S240 53 0 40V0z" fill="currentColor" />
    </svg>
  );
}

function SovereigntyPoint({
  title,
  children,
}: Readonly<{
  title: string;
  children: React.ReactNode;
}>) {
  return (
    <li className="rounded-xl border border-slate-200 bg-white p-5 shadow-card">
      <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
      <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{children}</p>
    </li>
  );
}

function IconCloud() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M7 18a5 5 0 1 1 .6-9.96A6 6 0 0 1 19 10.5a4.5 4.5 0 0 1-.5 8.96L7 19" />
    </svg>
  );
}

function IconSearch() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <circle cx="11" cy="11" r="6.5" />
      <path d="m20 20-3.5-3.5" />
    </svg>
  );
}

function IconPlug() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-6 6 6 6 0 0 1-6-6V8zM12 17v4" />
    </svg>
  );
}

function IconShield() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      <path d="M12 3 5 6v5c0 4.5 3 8 7 9 4-1 7-4.5 7-9V6l-7-3z" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}
