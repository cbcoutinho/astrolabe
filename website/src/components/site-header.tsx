import Link from "next/link";
import type { ReactNode } from "react";

import { Container } from "./container";
import { Logo } from "./logo";

// `external` items live on another origin (e.g. the docs subdomain), so they
// render as a plain <a> rather than a client-routed next/link.
type NavItem = { label: string; href: string; external?: boolean };

type HeaderVariant = "default" | "inverse" | "transparent";

// Renders a nav entry as a client-routed link, or a plain anchor when the entry
// points off-site (e.g. the docs subdomain). Same-tab navigation either way.
function NavLink({
  item,
  className,
}: Readonly<{ item: NavItem; className: string }>) {
  if (item.external) {
    return (
      <a href={item.href} rel="noopener" className={className}>
        {item.label}
      </a>
    );
  }
  return (
    <Link href={item.href} className={className}>
      {item.label}
    </Link>
  );
}

// Header wrapper styling per variant. Pulled out of the component as a flat
// lookup so there's no nested ternary at the call site.
function wrapClassFor(variant: HeaderVariant): string {
  switch (variant) {
    case "transparent":
      return "bg-transparent";
    case "inverse":
      return "bg-ink-900 text-white";
    default:
      return "border-b border-slate-200 bg-white/80 backdrop-blur";
  }
}

export function SiteHeader({
  nav = [],
  right,
  variant = "default",
}: Readonly<{
  nav?: NavItem[];
  right?: ReactNode;
  variant?: HeaderVariant;
}>) {
  const isInverse = variant === "inverse";
  const wrapClass = wrapClassFor(variant);

  const linkClass = isInverse
    ? "text-slate-300 hover:text-white"
    : "text-slate-600 hover:text-slate-900";

  // The summary's native disclosure triangle is hidden so the hamburger
  // SVG stands alone. List-style hides Firefox's marker; the
  // ::-webkit-details-marker selector hides Safari's.
  const summaryClass =
    "flex h-9 w-9 cursor-pointer list-none items-center justify-center rounded-md text-slate-700 hover:bg-slate-100 [&::-webkit-details-marker]:hidden";

  return (
    <header className={`relative ${wrapClass}`}>
      <Container size="lg">
        <div className="flex h-16 items-center gap-8">
          <Logo variant={isInverse ? "inverse" : "default"} />

          {/* Desktop nav: visible at sm+ */}
          {nav.length > 0 && (
            <nav
              aria-label="Primary"
              className="hidden items-center gap-6 sm:flex"
            >
              {nav.map((item) => (
                <NavLink
                  key={item.href}
                  item={item}
                  className={`text-sm font-medium transition-colors ${linkClass}`}
                />
              ))}
            </nav>
          )}

          {/* Desktop right cluster: visible at sm+ */}
          {right ? (
            <div className="ml-auto hidden items-center gap-3 sm:flex">
              {right}
            </div>
          ) : null}

          {/* Mobile disclosure: visible below sm. <details>/<summary>
              keeps SiteHeader a server component — no useState. */}
          {(nav.length > 0 || right) && (
            <details className="group relative ml-auto sm:hidden">
              <summary
                aria-label="Open menu"
                className={summaryClass}
              >
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={1.75}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden
                  className="h-5 w-5 group-open:hidden"
                >
                  <path d="M4 7h16M4 12h16M4 17h16" />
                </svg>
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={1.75}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden
                  className="hidden h-5 w-5 group-open:block"
                >
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </summary>
              <div className="absolute right-0 top-full z-40 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                {nav.length > 0 && (
                  <nav
                    aria-label="Primary mobile"
                    className="flex flex-col gap-1"
                  >
                    {nav.map((item) => (
                      <NavLink
                        key={item.href}
                        item={item}
                        className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900"
                      />
                    ))}
                  </nav>
                )}
                {right ? (
                  <div className="mt-3 flex flex-col gap-2 border-t border-slate-200 pt-3 text-sm">
                    {right}
                  </div>
                ) : null}
              </div>
            </details>
          )}
        </div>
      </Container>
    </header>
  );
}
