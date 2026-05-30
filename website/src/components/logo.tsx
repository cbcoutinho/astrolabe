import Link from "next/link";

export function Logo({
  variant = "default",
  href = "/",
}: Readonly<{
  variant?: "default" | "inverse";
  href?: string;
}>) {
  const dotColor = variant === "inverse" ? "text-brand-300" : "text-brand-500";
  const textColor = variant === "inverse" ? "text-white" : "text-slate-900";
  return (
    <Link href={href} className="group flex items-center gap-2.5">
      <Compass className={`h-6 w-6 ${dotColor}`} />
      <span className={`text-[15px] font-semibold tracking-tight ${textColor}`}>
        Astrolabe Cloud
      </span>
    </Link>
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
      <path d="M15.2 8.8 13 13l-4.2 2.2L11 11z" fill="currentColor" stroke="none" />
    </svg>
  );
}
