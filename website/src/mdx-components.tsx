import type { MDXComponents } from "mdx/types";

// Required by @next/mdx with the App Router — without this file MDX pages won't
// compile. Element styling is handled by the `prose` (Tailwind typography)
// wrapper in the docs layout rather than per-element overrides here, so the map
// is intentionally empty. Components used inside .mdx (Screenshot, Step, …) are
// imported explicitly in each file. Add element overrides here only if a tag
// needs styling that `prose` can't express.
const components: MDXComponents = {};

export function useMDXComponents(
  otherComponents: MDXComponents,
): MDXComponents {
  return { ...otherComponents, ...components };
}
