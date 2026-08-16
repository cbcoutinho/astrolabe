// This marketing site (astrolabecloud.com, GitHub Pages) has no auth surface of
// its own — sign-in lives on the portal deploy (app.<env>.astrolabecloud.com,
// cloudfleet). So every auth affordance links cross-domain to the portal's own
// /api/auth route. NEXT_PUBLIC_PORTAL_URL is the portal origin; it MUST be
// inlined at build time (NEXT_PUBLIC_ prefix) because these links render into
// statically-exported HTML. The Pages workflow sets it to the production portal
// (https://app.astrolabecloud.com).
// Defaults to the local portal dev server (apps/web on :3000) for local runs.
const PORTAL_URL = (
  process.env.NEXT_PUBLIC_PORTAL_URL ?? "http://localhost:3000"
).replace(/\/$/, "");

/**
 * Absolute URL to the portal's sign-in entry point. Signups are invite-only,
 * so this is the only auth affordance the marketing site exposes.
 *
 * @param callbackUrl portal-relative path to land on after sign-in. The portal
 *   serves the dashboard at "/", so that's the default.
 */
export function portalSignInUrl(callbackUrl = "/"): string {
  const qs = new URLSearchParams({ callbackUrl }).toString();
  return `${PORTAL_URL}/api/auth/signin?${qs}`;
}
