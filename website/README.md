# Astrolabe Cloud — marketing site

The static marketing/landing site for **Astrolabe Cloud** (the managed hosting
service for the Astrolabe Nextcloud app), served at
[astrolabecloud.com](https://astrolabecloud.com).

This is **separate from the Nextcloud app** in the repo root — it's a
self-contained Next.js project that builds to a static export and is published
to **GitHub Pages** by `.github/workflows/landing-pages.yml`. It has its own
`package.json`/`node_modules` and is not part of the app's Vite build.

## Local dev

```bash
cd website
npm ci
npm run dev          # http://localhost:3001
npm run build        # static export -> ./out
```

## Configuration

`NEXT_PUBLIC_PORTAL_URL` is the origin of the authenticated portal (a separate
app on Cloudfleet). The "Sign in" button links cross-domain to
`${NEXT_PUBLIC_PORTAL_URL}/api/auth/signin`. It's inlined at build time and set
by the Pages workflow — currently `https://app.dev.astrolabecloud.com`, to be
flipped to `https://app.astrolabecloud.com` at production launch.

## Deploy

Pushing to the default branch under `website/**` triggers the GitHub Pages
workflow, which builds the static export and publishes it. Repo Settings →
Pages → Source must be set to **GitHub Actions**, with the custom domain
`astrolabecloud.com`.
