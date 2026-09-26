import type { HTMLAttributes } from "react";

import { classNames } from "../class-names.js";

export function VisuallyHidden({
  className,
  ...props
}: HTMLAttributes<HTMLSpanElement>) {
  return <span {...props} className={classNames("fxr-visually-hidden", className)} />;
}
