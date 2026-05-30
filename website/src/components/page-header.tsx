import type { ReactElement, ReactNode } from "react";

// description / eyebrow render inside a <p> / <div>, so the prop type is
// narrowed to text-or-inline-element. Passing a block element (e.g. a
// <div>) would surface as a "div in p" hydration warning at runtime; the
// stricter type catches it at the call site.
export function PageHeader({
  title,
  description,
  action,
  eyebrow,
}: {
  title: string;
  description?: string | ReactElement;
  action?: ReactNode;
  eyebrow?: string | ReactElement;
}) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        {eyebrow ? (
          <div className="mb-1 text-xs font-medium uppercase tracking-wider text-slate-500">
            {eyebrow}
          </div>
        ) : null}
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
          {title}
        </h1>
        {description ? (
          <p className="mt-2 max-w-2xl text-sm text-slate-600 sm:text-base">
            {description}
          </p>
        ) : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}
