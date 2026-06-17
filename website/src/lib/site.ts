// Canonical site constants, shared across metadata exports so the production
// URL and the generated social card aren't duplicated string-by-string.
export const SITE_URL = "https://astrolabecloud.com";

// Path of the build-time generated social card (src/app/opengraph-image.tsx).
export const OG_IMAGE_PATH = "/opengraph-image";

// Alt text for the social card, reused by og:image and twitter:image.
export const OG_IMAGE_ALT =
  "Astrolabe Cloud — Semantic search & MCP for your Nextcloud";
