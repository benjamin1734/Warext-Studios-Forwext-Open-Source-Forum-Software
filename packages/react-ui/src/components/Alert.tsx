import type { HTMLAttributes, ReactNode } from "react";

import { classNames } from "../class-names.js";

export interface ForwextAlertProps extends HTMLAttributes<HTMLDivElement> {
  tone?: "info" | "success" | "warning" | "danger";
  title?: ReactNode;
}

export function ForwextAlert({
  children,
  className,
  role,
  title,
  tone = "info",
  ...props
}: ForwextAlertProps) {
  const resolvedRole = role ?? (tone === "danger" ? "alert" : "status");

  return (
    <div
      {...props}
      role={resolvedRole}
      aria-live={resolvedRole === "alert" ? "assertive" : "polite"}
      className={classNames("fxr-alert", `fxr-alert--${tone}`, className)}
    >
      {title === undefined ? null : <strong className="fxr-alert__title">{title}</strong>}
      <div className="fxr-alert__body">{children}</div>
    </div>
  );
}
