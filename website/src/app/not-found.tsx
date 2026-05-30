import { LinkButton } from "@/components/button";
import { Container } from "@/components/container";
import { Logo } from "@/components/logo";

// Lives at the app root (not inside the (marketing) group) so the static
// export emits a top-level 404.html. GitHub Pages serves that for any unknown
// path; without it, unmatched routes return a blank page. The (marketing)
// layout's header/footer don't wrap this route, so it carries its own minimal
// chrome.
export default function NotFound() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center text-center">
      <Container size="sm">
        <div className="flex flex-col items-center gap-6">
          <Logo />
          <p className="text-sm font-medium uppercase tracking-wider text-brand-500">
            404
          </p>
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
            Page not found
          </h1>
          <p className="max-w-md text-slate-600">
            The page you&apos;re looking for doesn&apos;t exist or may have
            moved.
          </p>
          <LinkButton href="/" size="md">
            Back to home
          </LinkButton>
        </div>
      </Container>
    </main>
  );
}
