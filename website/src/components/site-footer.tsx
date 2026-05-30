import Link from "next/link";

import { Container } from "./container";
import { Logo } from "./logo";

// The default year is computed at render time. For SSG marketing pages
// (any route without `force-dynamic`) Next.js evaluates this at build,
// so the rendered HTML carries the build year until the site is
// rebuilt — visible as a stale copyright into early January. Callers
// can pass `year` explicitly (e.g. from a deploy-time env var) to keep
// it accurate without a rebuild.
export function SiteFooter({ year = new Date().getFullYear() }: { year?: number }) {
  return (
    <footer className="mt-24 border-t border-slate-200 bg-slate-50/60 py-10">
      <Container size="lg">
        <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-col gap-2">
            <Logo />
            <p className="max-w-md text-sm text-slate-600">
              The hosted backend for Astrolabe — semantic search and MCP for your
              Nextcloud, run by us.
            </p>
          </div>
          <nav
            aria-label="Footer"
            className="flex flex-wrap gap-x-6 gap-y-2 text-sm"
          >
            <Link href="/pricing" className="text-slate-600 hover:text-slate-900">
              Pricing
            </Link>
            <a
              href="https://apps.nextcloud.com/apps/astrolabe"
              className="text-slate-600 hover:text-slate-900"
              target="_blank"
              rel="noopener noreferrer"
            >
              Nextcloud app
            </a>
            <a
              href="https://github.com/cbcoutinho/astrolabe"
              className="text-slate-600 hover:text-slate-900"
              target="_blank"
              rel="noopener noreferrer"
            >
              GitHub
            </a>
            <a
              href="mailto:hello@astrolabecloud.com"
              className="text-slate-600 hover:text-slate-900"
            >
              Contact
            </a>
          </nav>
        </div>
        <div className="mt-8 border-t border-slate-200 pt-6 text-xs text-slate-500">
          © {year} Astrolabe Cloud
        </div>
      </Container>
    </footer>
  );
}
