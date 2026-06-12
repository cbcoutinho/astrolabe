import { defineConfig, defineDocs } from "fumadocs-mdx/config";

// Docs live in content/docs as MDX. The default frontmatter schema gives us
// `title`, `description`, `toc`, and `full`. Customize schemas here if needed:
// https://fumadocs.dev/docs/mdx/collections
export const docs = defineDocs({
  dir: "content/docs",
});

export default defineConfig();
