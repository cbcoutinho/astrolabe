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
};

export default nextConfig;
