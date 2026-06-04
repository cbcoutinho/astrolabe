import type { ReactNode } from "react";

import { Container } from "@/components/container";

// Wraps the MDX docs content in a `prose` (Tailwind typography) container so
// Markdown renders with sensible, on-brand typography without per-element
// styling in every .mdx file. The prose-* modifiers retheme links to the brand
// colour and render inline `code` as a subtle chip (dropping prose's default
// backtick quotes via empty before/after content). Constrained to max-w-3xl for
// a comfortable reading measure; the shared marketing header/footer come from
// the parent (marketing) layout.
const articleClass =
  "prose prose-slate prose-headings:tracking-tight " +
  "prose-a:font-medium prose-a:text-brand-600 prose-a:no-underline hover:prose-a:text-brand-700 " +
  "prose-code:rounded prose-code:bg-slate-100 prose-code:px-1.5 prose-code:py-0.5 prose-code:font-normal " +
  "prose-code:before:content-[''] prose-code:after:content-['']";

export default function DocsLayout({
  children,
}: Readonly<{ children: ReactNode }>) {
  return (
    <Container size="sm" className="py-16 sm:py-20">
      <article className={articleClass}>{children}</article>
    </Container>
  );
}
