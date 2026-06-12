import Link from "next/link";
import type { AnchorHTMLAttributes, ReactNode } from "react";

import { cn } from "@/lib/cn";

type Variant =
  | "primary"
  | "secondary"
  | "ghost"
  | "ghost-inverse"
  | "danger"
  | "inverse";
type Size = "sm" | "md" | "lg";

const base =
  "inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white";

const variantClass: Record<Variant, string> = {
  primary: "bg-brand-500 text-white hover:bg-brand-600",
  secondary:
    "border border-slate-300 bg-white text-slate-900 hover:border-slate-400 hover:bg-slate-50",
  ghost: "text-slate-700 hover:bg-slate-100 hover:text-slate-900",
  "ghost-inverse":
    "text-white hover:bg-white/10 focus-visible:ring-offset-ink-900",
  danger: "bg-red-600 text-white hover:bg-red-700",
  inverse:
    "bg-white text-ink-900 hover:bg-slate-100 focus-visible:ring-offset-ink-900",
};

const sizeClass: Record<Size, string> = {
  sm: "h-8 px-3 text-sm",
  md: "h-10 px-4 text-sm",
  lg: "h-12 px-6 text-base",
};

function classes(variant: Variant, size: Size, extra?: string) {
  return cn(base, variantClass[variant], sizeClass[size], extra);
}

export function LinkButton({
  href,
  variant = "primary",
  size = "md",
  className,
  external,
  children,
  ...rest
}: {
  href: string;
  variant?: Variant;
  size?: Size;
  className?: string;
  external?: boolean;
  children: ReactNode;
} & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href">) {
  if (external) {
    // {...rest} is applied BEFORE target/rel so a caller-supplied `rel`
    // or `target` can't strip the security defaults.
    return (
      <a
        href={href}
        {...rest}
        target="_blank"
        rel="noopener noreferrer"
        className={classes(variant, size, className)}
      >
        {children}
      </a>
    );
  }
  return (
    <Link href={href} {...rest} className={classes(variant, size, className)}>
      {children}
    </Link>
  );
}
