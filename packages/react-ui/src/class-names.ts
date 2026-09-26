export function classNames(...values: Array<string | undefined | false>): string {
  return values.filter((value): value is string => typeof value === "string" && value !== "").join(" ");
}
