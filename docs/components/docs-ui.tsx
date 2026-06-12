import type { ReactNode } from "react";

// Presentational MDX components for the docs. Fumadocs wraps page content in
// its own typography ("prose") styles; structural chrome (the callout box, the
// step number/title row, the CTA) is marked `not-prose` to opt out and keep
// full control of its layout, while body text keeps prose styling.

export function Callout({
  title,
  children,
}: Readonly<{ title: string; children: ReactNode }>) {
  return (
    <div className="my-8 rounded-xl border border-brand-100 bg-brand-50/60 p-5">
      <strong className="not-prose mb-1.5 block text-sm font-semibold text-slate-900">
        {title}
      </strong>
      <div className="text-sm [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
        {children}
      </div>
    </div>
  );
}

export function Steps({
  children,
  label,
}: Readonly<{ children: ReactNode; label?: string }>) {
  // An ordered list so assistive tech announces the sequence and count. Each
  // Step renders its own visible number, so `list-none` + `pl-0` strip default
  // markers and indent. `role="list"` restores the list semantics that
  // Safari/VoiceOver drop when `list-style: none` is set — SonarCloud's S6822
  // ("redundant role") is a false positive here and doesn't affect the quality
  // gate. `label` is optional so pages with multiple step lists can
  // disambiguate them; the implicit list role suffices otherwise.
  return (
    <ol
      role="list"
      aria-label={label}
      className="mt-6 list-none pl-0 [&>li]:my-0 [&>li+li]:mt-5"
    >
      {children}
    </ol>
  );
}

// `n` is a hand-authored label ("01", "02", …), not auto-incremented — inserting
// a step means renumbering the ones after it. Fine at this scale; revisit with a
// CSS counter or a positional index from <Steps> if the list grows.
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
      <div className="mt-2 [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
        {children}
      </div>
    </li>
  );
}

// Closing call-to-action for the docs home. Kept as a component (not inline
// MDX) because multi-line text inside a JSX element written in .mdx gets
// reparsed as a Markdown paragraph — which would nest a <p> and break
// hydration. In a .tsx file the JSX text is left alone. The two buttons are
// inlined as plain <a>s (the only place the docs need a button) rather than
// importing a button component copied from the marketing site.
export function DocsCTA() {
  // Pill button on the dark CTA panel; ring offset matches the ink-900 backdrop.
  const button =
    "inline-flex h-12 items-center justify-center gap-2 rounded-md px-6 text-base font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-ink-900";

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
            <a
              href="mailto:hello@astrolabecloud.com"
              className={`${button} bg-white text-ink-900 hover:bg-slate-100`}
            >
              Request access
            </a>
            <a
              href="https://apps.nextcloud.com/apps/astrolabe"
              target="_blank"
              rel="noopener noreferrer"
              className={`${button} text-white hover:bg-white/10`}
            >
              Get the Nextcloud app
            </a>
          </div>
        </div>
      </div>
    </div>
  );
}
