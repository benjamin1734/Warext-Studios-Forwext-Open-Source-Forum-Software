import type { Metadata } from "next";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import {
  pageNumber,
  protectedClient,
  reconnectOnUnauthorized,
} from "@/lib/page-utils";

export const metadata: Metadata = {
  title: "Destek kayıtlarım",
  robots: { index: false, follow: false },
};

interface AccountSupportPageProps {
  searchParams: Promise<{ page?: string | string[] }>;
}

export default async function AccountSupportPage({ searchParams }: AccountSupportPageProps) {
  const query = await searchParams;
  const page = pageNumber(query.page);
  const nextPath = "/account/support";
  const api = await protectedClient(nextPath);

  try {
    const result = await api.supportTickets({ page, perPage: 30 });
    return (
      <div className="stack">
        <section className="hero">
          <h1>Destek kayıtlarım</h1>
          <p>Yalnızca bağlanan Forwext hesabının destek kayıtları.</p>
        </section>
        {result.items.length === 0 ? (
          <div className="empty">Destek kaydı bulunmuyor.</div>
        ) : (
          <div className="stack">
            {result.items.map((ticket) => (
              <ForwextSurface heading={ticket.subject} key={ticket.id}>
                <div className="meta">
                  <span>{ticket.category}</span>
                  <span>{ticket.priority}</span>
                  <span>{ticket.status}</span>
                  <time dateTime={ticket.updated_at}>{formatDate(ticket.updated_at)}</time>
                </div>
              </ForwextSurface>
            ))}
          </div>
        )}
        <Pager hasMore={result.pagination.has_more} page={page} path={nextPath} />
      </div>
    );
  } catch (error) {
    reconnectOnUnauthorized(error, nextPath);
  }
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("tr-TR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
