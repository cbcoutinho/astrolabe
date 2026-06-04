import Image from "next/image";

// A figure for admin-portal screenshots in the docs. While a real screenshot
// hasn't been captured yet, `src` is omitted and the component renders a
// labelled dashed placeholder instead of a broken <img> — so the page is
// complete and self-documenting in the meantime. To wire a real shot: drop the
// PNG into website/public/, then pass `src` plus its intrinsic `width`/`height`
// (required by next/image under the static export). The placeholder's `label`
// becomes the image `alt`, so describe what the screenshot shows.
// `src` requires `width`/`height` together (next/image needs intrinsic
// dimensions under the static export); omitting `src` renders the placeholder
// and forbids stray dimensions. The discriminated union enforces this at build
// time so a real screenshot can't be wired up without its dimensions.
type ScreenshotProps = Readonly<
  { label: string; caption?: string } & (
    | { src: string; width: number; height: number }
    | { src?: never; width?: never; height?: never }
  )
>;

export function Screenshot({
  label,
  src,
  width,
  height,
  caption,
}: ScreenshotProps) {
  return (
    <figure className="not-prose mt-5">
      {src ? (
        // No `sizes` prop: next.config sets `images: { unoptimized: true }`
        // for the static export, so Next emits no responsive srcset and a
        // `sizes` hint would be silently ignored.
        <Image
          src={src}
          alt={label}
          width={width}
          height={height}
          // `not-prose` on the figure drops Typography's `max-width: 100%`, so
          // constrain the image here — large screenshots scale down to the
          // column width while small ones stay at their natural size.
          className="h-auto max-w-full rounded-lg border border-slate-200 shadow-card"
        />
      ) : (
        <div
          role="img"
          aria-label={`Screenshot placeholder: ${label}`}
          className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-6 py-10 text-center"
        >
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-slate-400 shadow-card">
            <IconCamera />
          </span>
          <span className="text-xs font-medium uppercase tracking-wider text-slate-400">
            Screenshot
          </span>
          <span className="max-w-sm text-sm text-slate-500">{label}</span>
        </div>
      )}
      {caption ? (
        <figcaption className="mt-2 text-xs text-slate-500">{caption}</figcaption>
      ) : null}
    </figure>
  );
}

function IconCamera() {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className="h-5 w-5"
    >
      <path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L19 6h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8z" />
      <circle cx="12" cy="13" r="3.5" />
    </svg>
  );
}
