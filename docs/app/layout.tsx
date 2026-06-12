import { RootProvider } from "fumadocs-ui/provider/next";
import type { Metadata } from "next";
import { Inter, JetBrains_Mono } from "next/font/google";

import "./global.css";

// Fallback metadata for the browser tab and social crawlers that don't run JS.
// Fumadocs fills in per-page title/description from frontmatter; the template
// appends the site name to each page title.
export const metadata: Metadata = {
  title: {
    default: "Astrolabe Cloud Docs",
    template: "%s – Astrolabe Cloud Docs",
  },
  description:
    "Documentation for Astrolabe Cloud — connect your Nextcloud to the managed semantic-search and MCP backend.",
};

// Match the marketing site's typography: Inter for body, JetBrains Mono for the
// step numbers / inline code. Exposed as CSS variables so `font-mono`
// (mapped in global.css) resolves to JetBrains Mono.
const inter = Inter({ subsets: ["latin"] });
const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
});

export default function Layout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${inter.className} ${jetbrainsMono.variable}`}
      suppressHydrationWarning
    >
      <body className="flex flex-col min-h-screen">
        <RootProvider>{children}</RootProvider>
      </body>
    </html>
  );
}
