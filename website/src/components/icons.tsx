// Shared inline SVG icons used across marketing pages. Inlined (rather than
// pulling an icon library) to keep the static bundle tiny — these are the only
// glyphs the site needs. Each is `aria-hidden` since it's decorative; the
// adjacent text carries the meaning.

// A small check used in feature/included-in lists.
export function CheckMark() {
  return (
    <svg
      viewBox="0 0 20 20"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className="mt-0.5 h-4 w-4 shrink-0 text-brand-500"
    >
      <path d="m4 10 4 4 8-9" />
    </svg>
  );
}
