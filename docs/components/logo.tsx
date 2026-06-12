// Presentational brand mark for the Fumadocs nav title. Deliberately NOT a
// link: Fumadocs already wraps the nav `title` in an anchor to the home route,
// so rendering our own <a> here would nest anchors and break hydration.
export function Logo() {
  return (
    <span className="flex items-center gap-2.5">
      <Compass className="h-6 w-6 text-brand-500" />
      <span className="text-[15px] font-semibold tracking-tight text-slate-900 dark:text-white">
        Astrolabe Cloud
      </span>
    </span>
  );
}

function Compass({ className }: Readonly<{ className?: string }>) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className={className}
    >
      <circle cx="12" cy="12" r="9" />
      <path
        d="M15.2 8.8 13 13l-4.2 2.2L11 11z"
        fill="currentColor"
        stroke="none"
      />
    </svg>
  );
}
