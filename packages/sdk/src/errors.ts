import type { ApiErrorBody } from "./types.js";

export class ForwextApiError extends Error {
  public readonly status: number;
  public readonly code: string;
  public readonly details?: Record<string, unknown>;
  public readonly retryAfter?: number;

  public constructor(
    message: string,
    options: {
      status: number;
      code: string;
      details?: Record<string, unknown>;
      retryAfter?: number;
    },
  ) {
    super(message);
    this.name = "ForwextApiError";
    this.status = options.status;
    this.code = options.code;
    if (options.details !== undefined) this.details = options.details;
    if (options.retryAfter !== undefined) this.retryAfter = options.retryAfter;
  }
}

export class ForwextCompatibilityError extends Error {
  public constructor(
    public readonly expected: string,
    public readonly received: string,
  ) {
    super(`Forwext API version mismatch: expected ${expected}, received ${received}.`);
    this.name = "ForwextCompatibilityError";
  }
}

export function parseApiError(
  status: number,
  payload: unknown,
  retryAfterHeader: string | null,
): ForwextApiError {
  const retryAfter =
    retryAfterHeader !== null && /^[0-9]+$/u.test(retryAfterHeader)
      ? Number.parseInt(retryAfterHeader, 10)
      : undefined;

  if (isApiErrorBody(payload)) {
    return new ForwextApiError(payload.error.message, {
      status,
      code: payload.error.code,
      ...(payload.error.details === undefined ? {} : { details: payload.error.details }),
      ...(retryAfter === undefined ? {} : { retryAfter }),
    });
  }

  return new ForwextApiError(`Forwext API request failed with HTTP ${status}.`, {
    status,
    code: "http_error",
    ...(retryAfter === undefined ? {} : { retryAfter }),
  });
}

function isApiErrorBody(value: unknown): value is ApiErrorBody {
  if (typeof value !== "object" || value === null || !("error" in value)) return false;
  const error = (value as { error?: unknown }).error;
  return (
    typeof error === "object" &&
    error !== null &&
    typeof (error as { code?: unknown }).code === "string" &&
    typeof (error as { message?: unknown }).message === "string"
  );
}
