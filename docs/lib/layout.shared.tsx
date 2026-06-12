import type { BaseLayoutProps } from "fumadocs-ui/layouts/shared";

import { Logo } from "@/components/logo";
import { gitConfig } from "./shared";

// Shared layout options for the docs (and any future home) layout. The nav
// title is the Astrolabe Cloud logo, which links back to the marketing site;
// the top-level links point out to the main site so docs feel part of the same
// product.
export function baseOptions(): BaseLayoutProps {
  return {
    nav: {
      title: <Logo />,
    },
    githubUrl: `https://github.com/${gitConfig.user}/${gitConfig.repo}`,
    links: [
      {
        text: "Website",
        url: "https://astrolabecloud.com",
      },
      {
        text: "Pricing",
        url: "https://astrolabecloud.com/pricing",
      },
    ],
  };
}
