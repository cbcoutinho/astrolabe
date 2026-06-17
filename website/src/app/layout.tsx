import type { Metadata } from "next";
import { Inter, JetBrains_Mono } from "next/font/google";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
  display: "swap",
});

const SITE_URL = "https://astrolabecloud.com";

export const metadata: Metadata = {
  // Absolute base so the generated og:image (opengraph-image.tsx) and other
  // relative metadata URLs resolve to https://astrolabecloud.com/... in the
  // exported tags.
  metadataBase: new URL(SITE_URL),
  title: "Astrolabe Cloud",
  description: "A managed MCP server for your Nextcloud.",
  // OG/Twitter defaults inherited by every page (pages may override). Pairs
  // with the site-wide opengraph-image, which supplies og:image/twitter:image.
  openGraph: {
    siteName: "Astrolabe Cloud",
    type: "website",
    url: SITE_URL,
  },
  twitter: {
    card: "summary_large_image",
  },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={`${inter.variable} ${jetbrainsMono.variable}`}>
      <body className="font-sans antialiased">{children}</body>
    </html>
  );
}
