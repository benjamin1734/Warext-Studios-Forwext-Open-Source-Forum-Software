import Link from "next/link";

export interface PagerProps {
  path: string;
  page: number;
  hasMore: boolean;
}

export function Pager({ path, page, hasMore }: PagerProps) {
  if (page <= 1 && !hasMore) return null;

  return (
    <nav aria-label="Sayfalama" className="pager">
      {page > 1 ? <Link href={path + "?page=" + (page - 1)}>← Önceki</Link> : <span />}
      <span>Sayfa {page}</span>
      {hasMore ? <Link href={path + "?page=" + (page + 1)}>Sonraki →</Link> : <span />}
    </nav>
  );
}
