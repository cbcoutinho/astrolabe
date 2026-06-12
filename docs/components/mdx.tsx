import defaultMdxComponents from "fumadocs-ui/mdx";
import type { MDXComponents } from "mdx/types";

import { Callout, DocsCTA, Step, Steps } from "@/components/docs-ui";
import { Screenshot } from "@/components/screenshot";

// Components made available to every MDX page. Our branded Callout/Steps/Step
// override Fumadocs' defaults; Screenshot and DocsCTA are docs-specific.
export function getMDXComponents(components?: MDXComponents) {
  return {
    ...defaultMdxComponents,
    Callout,
    Steps,
    Step,
    Screenshot,
    DocsCTA,
    ...components,
  } satisfies MDXComponents;
}

export const useMDXComponents = getMDXComponents;

declare global {
  type MDXProvidedComponents = ReturnType<typeof getMDXComponents>;
}
