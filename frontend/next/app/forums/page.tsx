import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import { createForwextClient } from "@/lib/forwext";
import { pageNumber } from "@/lib/page-utils";

export const metadata: Metadata = {
  title: "Forumlar",
  description: "Forwext forum kategorileri ve tartışma alanları.",
};

interface ForumsPageProps {
  searchParams: Promise<{ page?: string | string[] }>;
}

export default async function ForumsPage({ searchParams }: ForumsPageProps) {
  const params = await searchParams;
  const page = pageNumber(params.page);
  const result = await createForwextClient().forums({ page, perPage: 24 });

  return (
    <div className="stack">
      <section className="hero">
        <h1>Forumlar</h1>
        <p>Topluluğun herkese açık forum alanları.</p>
      </section>
      {result.items.length === 0 ? (
        <div className="empty">Bu sayfada forum bulunmuyor.</div>
      ) : (
        <div className="grid">
          {result.items.map((forum) => (
            <Link className="card-link" href={"/forums/" + forum.id} key={forum.id}>
              <ForwextSurface heading={forum.title}>
                <p className="muted">{forum.description || "Açıklama bulunmuyor."}</p>
              </ForwextSurface>
            </Link>
          ))}
        </div>
      )}
      <Pager hasMore={result.pagination.has_more} page={page} path="/forums" />
    </div>
  );
}
