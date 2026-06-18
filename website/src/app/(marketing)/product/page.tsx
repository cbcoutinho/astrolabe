import type { Metadata } from "next";
import Image from "next/image";

import { LinkButton } from "@/components/button";
import { Container } from "@/components/container";
import { CheckMark } from "@/components/icons";
import { PageHeader } from "@/components/page-header";
import { OG_IMAGE_ALT, OG_IMAGE_PATH, SITE_URL } from "@/lib/site";

const DESCRIPTION =
  "Astrolabe Cloud is a semantic knowledge base for your Nextcloud. It indexes your notes, files, calendar, and Deck and serves them back by meaning — over Nextcloud's unified search and a hosted MCP endpoint any client can use. EU-sovereign by design: compute on Hetzner, embeddings by Mistral AI, vectors in Qdrant Cloud.";

export const metadata: Metadata = {
  title: "Product — Astrolabe Cloud",
  description: DESCRIPTION,
  // A page-level openGraph block replaces the parent's entirely (it is not
  // deep-merged) and suppresses the auto-merged root opengraph-image, so this
  // is self-contained: title/description/url + the generated card all set here.
  openGraph: {
    title: "Product — Astrolabe Cloud",
    description: DESCRIPTION,
    url: `${SITE_URL}/product`,
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

// The retrieval pipeline, described as a few discrete stages so the "indexing
// & retrieval" capability reads as a system rather than a feature list.
const pipeline = [
  {
    n: "01",
    title: "Index",
    body: "Astrolabe watches your Nextcloud and chunks new and changed content — Notes, Files, Calendar, Deck — as it lands. Each chunk is turned into a vector embedding so it can be matched by meaning.",
  },
  {
    n: "02",
    title: "Store",
    body: "Embeddings live in a per-tenant vector index, kept in sync with your Nextcloud. No index to provision, tune, or back up on your side.",
  },
  {
    n: "03",
    title: "Retrieve",
    body: "A query is embedded the same way and ranked against the index with hybrid semantic + keyword scoring — so you get conceptual recall and exact-match precision in one result set.",
  },
];

// What an MCP client can do once it is connected. Framed around retrieval, not
// generation, because the model lives in the client (see the "no LLM" section).
const capabilities = [
  "Semantic search across Notes, Files, Calendar, and Deck from one index",
  "Hybrid ranking — meaning for recall, keywords for precision",
  "Tunable result count and score thresholds for narrow or exploratory queries",
  "Citations back to the source note, file, or event",
  "Retrieval context any MCP client can feed to its own model for RAG-style answers",
];

// EU-sovereignty providers — the headline differentiator. Kept concrete (named
// vendors and regions) rather than a vague "privacy-first" claim.
const stack = [
  {
    title: "Compute — Hetzner",
    body: "The managed backend and your vector workloads run on Hetzner in the EU (Germany & Finland). No hop through a US hyperscaler.",
  },
  {
    title: "Embeddings — Mistral AI",
    body: "Text is embedded by Mistral AI, a European model provider — your content is vectorised in-region, not shipped to a US API.",
  },
  {
    title: "Vectors — Qdrant Cloud",
    body: "Embeddings are stored and searched in Qdrant Cloud, hosted in the EU, isolated per tenant.",
  },
  {
    title: "Your files — your Nextcloud",
    body: "You bring your own Nextcloud; the source documents never leave the server you already control. We index, we don't host.",
  },
];

const jsonLd = {
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  name: "Astrolabe Cloud",
  description:
    "A semantic knowledge base for Nextcloud: document indexing and retrieval over unified search and a managed MCP server, hosted in the EU.",
  applicationCategory: "DeveloperApplication",
  operatingSystem: "Web",
  url: `${SITE_URL}/product`,
  image: `${SITE_URL}${OG_IMAGE_PATH}`,
};

export default function ProductPage() {
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />

      <Container size="lg" className="py-16 sm:py-20">
        <PageHeader
          eyebrow="Product"
          title="A semantic knowledge base for your Nextcloud."
          description="Astrolabe Cloud turns your Nextcloud into searchable, retrievable knowledge — indexed by meaning and served back over the search bar you already use and a hosted MCP endpoint. It does the retrieval; your tools do the rest."
        />
        <div className="mt-8 flex flex-wrap gap-3">
          <LinkButton href="/#waitlist" size="lg">
            Join the waitlist
          </LinkButton>
          <LinkButton href="/pricing" size="lg" variant="ghost">
            See pricing
          </LinkButton>
        </div>
      </Container>

      {/* Indexing & retrieval — the core capability, as a pipeline */}
      <section className="border-y border-slate-200 bg-slate-50/60 py-20 sm:py-24">
        <Container size="lg">
          <div className="max-w-2xl">
            <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
              Document indexing &amp; retrieval
            </p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
              From your documents to answers by meaning.
            </h2>
            <p className="mt-4 text-base leading-relaxed text-slate-600">
              Keyword search only finds the words you remember. Astrolabe indexes
              your content as vector embeddings and retrieves it by meaning, so
              &ldquo;the contract we signed in spring&rdquo; finds the right
              document even if it never used those words.
            </p>
          </div>
          <ol className="mt-12 grid gap-8 sm:grid-cols-3">
            {pipeline.map((s) => (
              <li key={s.n}>
                <div className="font-mono text-sm font-medium text-brand-600">
                  {s.n}
                </div>
                <h3 className="mt-2 text-base font-semibold text-slate-900">
                  {s.title}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                  {s.body}
                </p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      {/* What it can do */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="grid items-center gap-12 lg:grid-cols-2">
            <div className="max-w-xl">
              <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
                Capabilities
              </p>
              <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                Built for retrieval, ready for agents.
              </h2>
              <p className="mt-4 text-base leading-relaxed text-slate-600">
                Everything Astrolabe does is in service of one job: getting the
                right context out of your Nextcloud, fast and accurately —
                whether a human is searching or an agent is.
              </p>
              <ul className="mt-6 space-y-3 text-sm text-slate-600">
                {capabilities.map((c) => (
                  <li key={c} className="flex gap-3">
                    <CheckMark />
                    <span className="leading-relaxed">{c}</span>
                  </li>
                ))}
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

      {/* Nextcloud integration */}
      <section className="border-y border-slate-200 bg-slate-50/60 py-20 sm:py-24">
        <Container size="lg">
          <div className="grid items-center gap-12 lg:grid-cols-2">
            <div>
              <Image
                src="/unified-search.png"
                alt="Astrolabe results inside Nextcloud's unified search bar — a plain-language query surfacing relevant files and Deck cards."
                width={1738}
                height={1103}
                sizes="(min-width: 1024px) 45vw, 90vw"
                className="rounded-xl border border-slate-200 shadow-card"
              />
            </div>
            <div className="max-w-xl">
              <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
                Nextcloud integration
              </p>
              <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                It lives where your data already does.
              </h2>
              <p className="mt-4 text-base leading-relaxed text-slate-600">
                You bring your own Nextcloud — we never host or copy your files.
                Connect it once with an OIDC client, and Astrolabe provisions a
                managed backend with a per-tenant index that stays in sync as
                your content changes.
              </p>
              <ul className="mt-6 space-y-2 text-sm text-slate-600">
                <li className="flex gap-2">
                  <CheckMark /> Results land in the Nextcloud unified search bar
                  you already use.
                </li>
                <li className="flex gap-2">
                  <CheckMark /> One index spans Notes, Files, Calendar, and Deck.
                </li>
                <li className="flex gap-2">
                  <CheckMark /> Per-tenant isolation with secret-managed
                  credentials.
                </li>
                <li className="flex gap-2">
                  <CheckMark /> The Astrolabe app and backend are open source —
                  inspect or self-host.
                </li>
              </ul>
            </div>
          </div>
        </Container>
      </section>

      {/* RAG via MCP */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="max-w-2xl">
            <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
              RAG via MCP
            </p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
              Generation happens in your client.
            </h2>
            <p className="mt-4 text-base leading-relaxed text-slate-600">
              Astrolabe ships a hosted{" "}
              <abbr title="Model Context Protocol">MCP</abbr> endpoint. Plug in
              Claude, Cursor, or any MCP client and it can search your knowledge
              bank and pull back the most relevant passages — the{" "}
              <em>retrieval</em> half of retrieval-augmented generation. Your
              client&apos;s model reads that context and writes the answer, with
              citations back to the source.
            </p>
          </div>
          <div className="mt-10 grid gap-4 sm:grid-cols-3">
            <RagStep step="Retrieve" who="Astrolabe">
              Your query is embedded and matched against your per-tenant index;
              the top passages come back as context.
            </RagStep>
            <RagStep step="Augment" who="Your MCP client">
              The client assembles those passages into the prompt it sends to its
              own model.
            </RagStep>
            <RagStep step="Generate" who="Your model">
              The model you already use — and pay for — writes the grounded
              answer.
            </RagStep>
          </div>
        </Container>
      </section>

      {/* Why no bundled LLM */}
      <section className="border-y border-slate-200 bg-slate-50/60 py-20 sm:py-24">
        <Container size="lg">
          <div className="grid items-start gap-12 lg:grid-cols-2">
            <div className="max-w-xl">
              <p className="text-xs font-medium uppercase tracking-wider text-brand-600">
                A deliberate choice
              </p>
              <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                Why we don&apos;t bundle an LLM.
              </h2>
              <p className="mt-4 text-base leading-relaxed text-slate-600">
                Astrolabe is the retrieval layer, not another chatbot. We
                deliberately stop at giving your client clean, relevant context —
                and let you choose the model that reads it. That keeps the
                product focused and your data path short.
              </p>
            </div>
            <ul className="grid gap-4 sm:grid-cols-2 lg:mt-2">
              <ReasonPoint title="Bring your own model">
                Use the LLM you already trust and pay for — no second AI bill,
                no lock-in to ours.
              </ReasonPoint>
              <ReasonPoint title="A shorter data path">
                Your content is embedded and retrieved in the EU; it isn&apos;t
                forwarded to a bundled generation model you didn&apos;t pick.
              </ReasonPoint>
              <ReasonPoint title="Do one thing well">
                Retrieval quality is the hard part of RAG. We invest there
                instead of chasing a general-purpose model.
              </ReasonPoint>
              <ReasonPoint title="Composable by design">
                An open MCP endpoint plugs into whatever agent stack you run,
                today and as it changes.
              </ReasonPoint>
            </ul>
          </div>
        </Container>
      </section>

      {/* EU sovereignty — the headline differentiator */}
      <section className="py-20 sm:py-24">
        <Container size="lg">
          <div className="max-w-2xl">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
              <IconShield />
            </div>
            <h2 className="mt-5 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
              EU data sovereignty, by architecture.
            </h2>
            <p className="mt-4 text-base leading-relaxed text-slate-600">
              Every component that touches your content sits in Europe. Not a
              compliance checkbox — the actual data path. Here is where each part
              runs:
            </p>
          </div>
          <div className="mt-10 grid gap-4 sm:grid-cols-2">
            {stack.map((s) => (
              <div
                key={s.title}
                className="rounded-xl border border-slate-200 bg-white p-6 shadow-card"
              >
                <h3 className="text-sm font-semibold text-slate-900">
                  {s.title}
                </h3>
                <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                  {s.body}
                </p>
              </div>
            ))}
          </div>
        </Container>
      </section>

      {/* CTA */}
      <section className="pb-24">
        <Container size="lg">
          <div className="rounded-2xl bg-ink-900 px-6 py-12 text-white sm:px-12 sm:py-16">
            <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
              <div className="max-w-xl">
                <h2 className="text-balance text-2xl font-semibold tracking-tight sm:text-3xl">
                  Put your Nextcloud knowledge to work.
                </h2>
                <p className="mt-3 text-slate-300">
                  We&apos;re rolling out early-access tenants now. Join the
                  waitlist and we&apos;ll be in touch.
                </p>
              </div>
              <div className="flex flex-wrap gap-3">
                <LinkButton href="/#waitlist" size="lg" variant="inverse">
                  Join the waitlist
                </LinkButton>
                <LinkButton href="/pricing" size="lg" variant="ghost-inverse">
                  See pricing
                </LinkButton>
              </div>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}

function RagStep({
  step,
  who,
  children,
}: Readonly<{
  step: string;
  who: string;
  children: React.ReactNode;
}>) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-card">
      <div className="flex items-baseline justify-between gap-2">
        <h3 className="text-base font-semibold text-slate-900">{step}</h3>
        <span className="text-xs font-medium uppercase tracking-wider text-brand-600">
          {who}
        </span>
      </div>
      <p className="mt-2 text-sm leading-relaxed text-slate-600">{children}</p>
    </div>
  );
}

function ReasonPoint({
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

function IconShield() {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      className="h-5 w-5"
    >
      <path d="M12 3 5 6v5c0 4.5 3 8 7 9 4-1 7-4.5 7-9V6l-7-3z" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}
