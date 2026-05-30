import Link from "next/link";
import type { ReactNode } from "react";

import { Container } from "./container";
import { Logo } from "./logo";

type NavItem = { label: string; href: string };

export function SiteHeader({
  nav = [],
  right,
  variant = "default",
}: {
  nav?: NavItem[];
  right?: ReactNode;
  variant?: "default" | "inverse" | "transparent";
}) {
  const isInverse = variant === "inverse";
  const wrapClass =
    variant === "transparent"
      ? "bg-transparent"
      : isInverse
        ? "bg-ink-900 text-white"
        : "border-b border-slate-200 bg-white/80 backdrop-blur";

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
                <Link
                  key={item.href}
                  href={item.href}
                  className={`text-sm font-medium transition-colors ${linkClass}`}
                >
                  {item.label}
                </Link>
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
                      <Link
                        key={item.href}
                        href={item.href}
                        className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900"
                      >
                        {item.label}
                      </Link>
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
