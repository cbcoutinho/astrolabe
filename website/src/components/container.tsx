import type { ReactNode } from "react";

type Size = "sm" | "md" | "lg";

const sizeClass: Record<Size, string> = {
  sm: "max-w-3xl",
  md: "max-w-5xl",
  lg: "max-w-6xl",
};

export function Container({
  children,
  size = "md",
  className = "",
}: Readonly<{
  children: ReactNode;
  size?: Size;
  className?: string;
}>) {
  return (
    <div className={`mx-auto w-full ${sizeClass[size]} px-6 ${className}`}>
      {children}
    </div>
  );
}
