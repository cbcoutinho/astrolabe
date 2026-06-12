import { createMDX } from "fumadocs-mdx/next";

const withMDX = createMDX();

// Hosted on Vercel (not a static export like the marketing site), so Next runs
// natively — image optimization, route handlers, and search all work.
/** @type {import('next').NextConfig} */
const config = {
  reactStrictMode: true,
  // Pin the workspace root to this app. The repo has sibling lockfiles
  // (root, website/), so without this Next infers the parent repo as the
  // root and warns. On Vercel the project's root_directory is `docs`.
  turbopack: {
    root: import.meta.dirname,
  },
};

export default withMDX(config);
