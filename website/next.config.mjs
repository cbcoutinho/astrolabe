import createMDX from "@next/mdx";

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Static HTML export for GitHub Pages — `next build` emits ./out with no
  // server runtime. The marketing pages are fully static; the Tally embed and
  // any interactivity are client-side, which the export supports.
  output: "export",
  // The default next/image loader needs a running server; `unoptimized` emits
  // plain <img> against the files in public/, which is what a static host wants.
  images: { unoptimized: true },
  // Let .mdx files be routes/pages, so docs can be authored in Markdown
  // alongside the .tsx marketing pages. MDX compiles to static HTML at build
  // time, so it's fully compatible with `output: export`. Plain .md is
  // deliberately excluded so a stray README in src/app/ can't become a route.
  pageExtensions: ["ts", "tsx", "mdx"],
};

// MDX is compiled by @next/mdx at build time. No remark/rehype plugins are
// configured; if any are added they must be passed by string name (e.g.
// "remark-gfm") so they work under Turbopack, which can't accept JS-function
// plugins. Custom element styling lives in src/mdx-components.tsx.
const withMDX = createMDX({});

export default withMDX(nextConfig);
