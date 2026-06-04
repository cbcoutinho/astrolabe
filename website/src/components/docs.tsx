import type { ReactNode } from "react";

import { LinkButton } from "@/components/button";

// Presentational components for the MDX docs. The docs layout wraps page
// content in a `prose` (Tailwind typography) container, so Markdown text inside
// these components is styled automatically. Structural chrome (the callout box,
// the step number/title row) is marked `not-prose` to opt out of prose styling
// and keep full control of its layout.

export function Callout({
  title,
  children,
}: Readonly<{ title: string; children: ReactNode }>) {
  return (
    <div className="my-8 rounded-xl border border-brand-100 bg-brand-50/60 p-5">
      {/* A strong label, not a heading: it names the box's purpose and should
          stay out of the document's heading outline (which the `##` section
          headings own). `block` makes the inline <strong> carry the margin. */}
      <strong className="not-prose mb-1.5 block text-sm font-semibold text-slate-900">
        {title}
      </strong>
      {/* Children are Markdown; prose styles the text and links. Trim only the
          orphan margins at the edges so the box stays tight while multi-paragraph
          callouts keep their inter-paragraph spacing. */}
      <div className="text-sm [&>p:first-child]:mt-0 [&>p:last-child]:mb-0">
        {children}
      </div>
    </div>
  );
}

export function Steps({ children }: Readonly<{ children: ReactNode }>) {
  // An ordered list so assistive tech announces the sequence and count. Each
  // Step renders its own visible number, so `not-prose` (+ list-none) strips
  // prose's default list styling. The explicit role="list" restores list
  // semantics that Safari/VoiceOver drop when `list-style: none` is set.
  return (
    <ol role="list" className="not-prose mt-6 list-none space-y-5 pl-0">
      {children}
    </ol>
  );
}

export function Step({
  n,
  title,
  children,
}: Readonly<{ n: string; title: string; children: ReactNode }>) {
  return (
    <li className="rounded-xl border border-slate-200 bg-white p-6 shadow-card">
      <div className="not-prose flex items-baseline gap-4">
        <span className="font-mono text-sm font-medium text-brand-600">{n}</span>
        <h3 className="text-base font-semibold text-slate-900">{title}</h3>
      </div>
      <div className="mt-2 [&>*:first-child]:mt-0">{children}</div>
    </li>
  );
}

// Closing call-to-action for the docs page. Kept as a component (not inline MDX)
// because multi-line text inside a JSX element written in .mdx gets reparsed as
// a Markdown paragraph — which would nest a <p> inside this <p> and break
// hydration. In a .tsx file the JSX text is left alone.
export function DocsCTA() {
  return (
    <div className="not-prose mt-16">
      <div className="rounded-2xl bg-ink-900 px-6 py-10 text-white sm:px-10 sm:py-12">
        <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="max-w-xl">
            <h2 className="text-balance text-2xl font-semibold tracking-tight">
              Don&apos;t have a tenant yet?
            </h2>
            <p className="mt-2 text-slate-300">
              Access is invite-only while we roll out. Get in touch and we&apos;ll
              set you up — or grab the free Nextcloud app to explore Astrolabe
              today.
            </p>
          </div>
          <div className="flex flex-wrap gap-3">
            <LinkButton
              href="mailto:hello@astrolabecloud.com"
              size="lg"
              variant="inverse"
            >
              Request access
            </LinkButton>
            <LinkButton
              href="https://apps.nextcloud.com/apps/astrolabe"
              external
              size="lg"
              variant="ghost-inverse"
            >
              Get the Nextcloud app
            </LinkButton>
          </div>
        </div>
      </div>
    </div>
  );
}
