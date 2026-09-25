import type { ApiPage } from "./types.js";

export interface PageParams {
  page?: number;
  perPage?: number;
}

export function paginationQuery(params: PageParams = {}): URLSearchParams {
  const page = params.page ?? 1;
  const perPage = params.perPage ?? 20;

  if (!Number.isInteger(page) || page < 1 || page > 100_000) {
    throw new Error("Forwext API page must be an integer from 1 to 100000.");
  }
  if (!Number.isInteger(perPage) || perPage < 1 || perPage > 100) {
    throw new Error("Forwext API perPage must be an integer from 1 to 100.");
  }

  return new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
}

export function nextPageParams<T>(page: ApiPage<T>): PageParams | null {
  return page.pagination.has_more
    ? { page: page.pagination.page + 1, perPage: page.pagination.per_page }
    : null;
}
