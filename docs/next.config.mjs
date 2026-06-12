import { createMDX } from "fumadocs-mdx/next";

const withMDX = createMDX();

// Hosted on Vercel (not a static export like the marketing site), so Next runs
// natively — image optimization, route handlers, and search all work.
/** @type {import('next').NextConfig} */
const config = {
  reactStrictMode: true,
  // Pin the workspace root for `next dev --turbopack`. The repo has sibling
  // lockfiles (root, website/), so without this Turbopack infers the parent
  // repo as the root and warns in local dev. This only affects dev — it has no
  // effect on `next build` (what Vercel runs) — but it keeps local dev quiet.
  turbopack: {
    root: import.meta.dirname,
  },
};

export default withMDX(config);
