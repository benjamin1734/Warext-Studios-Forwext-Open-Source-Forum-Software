import type { HTMLAttributes, ReactNode } from "react";

import { classNames } from "../class-names.js";

export interface ForwextSurfaceProps extends HTMLAttributes<HTMLDivElement> {
  heading?: ReactNode;
}

export function ForwextSurface({
  children,
  className,
  heading,
  ...props
}: ForwextSurfaceProps) {
  return (
    <div {...props} className={classNames("fxr-surface", className)}>
      {heading === undefined ? null : <div className="fxr-surface__heading">{heading}</div>}
      <div className="fxr-surface__body">{children}</div>
    </div>
  );
}
