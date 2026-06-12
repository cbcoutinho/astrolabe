# Astrolabe Cloud Docs

The documentation site for Astrolabe Cloud, served at
**https://docs.astrolabecloud.com**. Built with [Next.js](https://nextjs.org)
and [Fumadocs](https://fumadocs.dev) (sidebar nav, search, theming) and hosted
on **Vercel** — separate from the marketing site (`../website`, GitHub Pages at
astrolabecloud.com).

## Develop

```bash
npm install
npm run dev      # http://localhost:3002 (root redirects to /docs)
```

Docs are MDX under `content/docs/`. Branded components (`Callout`, `Steps`,
`Step`, `Screenshot`, `DocsCTA`) are registered in `components/mdx.tsx`; the
brand theme mirrors the marketing site in `app/global.css`.

## Build / typecheck

```bash
npm run build
npm run typecheck
```

## Deploy

Provisioned and deployed via Vercel, configured in Terraform
(`homelab-terraform`, `infra/live/astrolabecloud/astrolabecloud-vercel.tf`): a
git-connected Vercel project with `root_directory = docs` that auto-deploys on
push to `main`. DNS (`docs.astrolabecloud.com` → Vercel) lives in the same
Terraform project.
