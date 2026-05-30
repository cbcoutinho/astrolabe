import Link from "next/link";
import type { ReactNode } from "react";

type Variant = "default" | "dashed" | "muted";

const variantClass: Record<Variant, string> = {
  default:
    "border-slate-200 bg-white shadow-card hover:border-slate-300 hover:shadow-card-hover",
  dashed: "border-dashed border-slate-300 bg-slate-50/50",
  muted: "border-slate-200 bg-slate-50",
};

export function Card({
  children,
  variant = "default",
  className = "",
}: Readonly<{
  children: ReactNode;
  variant?: Variant;
  className?: string;
}>) {
  return (
    <div
      className={`rounded-xl border p-6 transition-colors ${variantClass[variant]} ${className}`}
    >
      {children}
    </div>
  );
}

export function CardLink({
  href,
  children,
  className = "",
}: Readonly<{
  href: string;
  children: ReactNode;
  className?: string;
}>) {
  return (
    <Link
      href={href}
      className={`block rounded-xl border border-slate-200 bg-white p-6 shadow-card transition-colors hover:border-brand-300 hover:shadow-card-hover ${className}`}
    >
      {children}
    </Link>
  );
}
