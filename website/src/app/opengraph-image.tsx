import { ImageResponse } from "next/og";

// Default social-share card for every route (home + pricing). Next.js wires
// this up as og:image, and — because there is no twitter-image file — reuses it
// for twitter:image too. Rendered at build time by next/og's ImageResponse.
//
// Required under `output: export` so the route is materialised to a static
// PNG (same convention as sitemap.ts / robots.ts).
export const dynamic = "force-static";

export const alt = "Astrolabe Cloud — Semantic search & MCP for your Nextcloud";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

// Nextcloud blue — matches the brand mark in src/app/icon.svg.
const BRAND = "#0082C9";

// The white astrolabe glyph from src/app/icon.svg (the figure only, without the
// rounded-square plate), so it reads as a white mark on the blue card.
const GLYPH =
  "M255.9 21.04c-11.8 0-22.2 4.08-28.6 10.01-5.6 4.98-8.6 11.41-8.6 18.11 0 5.55 2.2 11.01 5.9 15.48-16.4 4.97-30.1 13.64-39 24.53 22.1-7.67 45.7-11.86 70.3-11.86 24.6 0 48.3 4.19 70.3 11.86-8.9-10.89-22.6-19.56-39-24.53 3.9-4.47 5.9-9.93 5.9-15.48 0-6.7-3-13.13-8.5-18.11-6.4-5.93-16.9-10.01-28.7-10.01zm0 20.34c5.3 0 10.1 1.27 13.6 3.52 1.7 1.16 3.4 2.43 3.4 4.27 0 1.76-1.7 3.03-3.4 4.19-3.5 2.33-8.3 3.61-13.6 3.61-5.3 0-10.1-1.28-13.6-3.61-1.6-1.16-3.3-2.43-3.3-4.19 0-1.84 1.7-3.11 3.3-4.27 3.5-2.25 8.3-3.52 13.6-3.52zm.1 48.1c-110.8 0-200.72 90.02-200.72 200.82S145.2 491 256 491s200.7-89.9 200.7-200.7c0-110.8-89.9-200.82-200.7-200.82zm0 32.62c92.9 0 168.2 75.3 168.2 168.2 0 92.8-75.3 168.2-168.2 168.2-92.9 0-168.26-75.4-168.26-168.2 0-92.9 75.36-168.2 168.26-168.2z";

export default function OpengraphImage() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          background: BRAND,
          color: "white",
          padding: "80px",
          fontFamily: "sans-serif",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: "24px" }}>
          <svg width="84" height="84" viewBox="0 0 512 512">
            <path d={GLYPH} fill="#fff" />
          </svg>
          <div style={{ fontSize: "44px", fontWeight: 700, letterSpacing: "-0.01em" }}>
            Astrolabe Cloud
          </div>
        </div>

        <div
          style={{
            display: "flex",
            fontSize: "76px",
            fontWeight: 700,
            lineHeight: 1.1,
            letterSpacing: "-0.02em",
            maxWidth: "900px",
          }}
        >
          A managed MCP server for your Nextcloud.
        </div>

        <div style={{ display: "flex", fontSize: "30px", color: "rgba(255,255,255,0.85)" }}>
          {/* next/og renders via Satori (canvas, not HTML), so the bare "&"
              is drawn literally — no HTML-entity encoding needed. */}
          Semantic search & MCP · astrolabecloud.com
        </div>
      </div>
    ),
    size,
  );
}
