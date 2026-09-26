import { forwardRef, type ButtonHTMLAttributes } from "react";

import { classNames } from "../class-names.js";
import { VisuallyHidden } from "./VisuallyHidden.js";

export interface ForwextButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  loading?: boolean;
  loadingLabel?: string;
  tone?: "primary" | "neutral" | "danger";
}

export const ForwextButton = forwardRef<HTMLButtonElement, ForwextButtonProps>(
  function ForwextButton(
    {
      children,
      className,
      disabled = false,
      loading = false,
      loadingLabel = "İşleniyor",
      tone = "primary",
      type = "button",
      ...props
    },
    ref,
  ) {
    const unavailable = disabled || loading;

    return (
      <button
        {...props}
        ref={ref}
        type={type}
        disabled={unavailable}
        aria-busy={loading || undefined}
        className={classNames("fxr-button", `fxr-button--${tone}`, className)}
      >
        {loading ? <VisuallyHidden>{loadingLabel}</VisuallyHidden> : null}
        <span aria-hidden={loading || undefined}>{children}</span>
      </button>
    );
  },
);
