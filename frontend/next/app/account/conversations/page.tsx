import type { Metadata } from "next";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import {
  pageNumber,
  protectedClient,
  reconnectOnUnauthorized,
} from "@/lib/page-utils";

export const metadata: Metadata = {
  title: "Konuşmalar",
  robots: { index: false, follow: false },
};

interface ConversationsPageProps {
  searchParams: Promise<{ page?: string | string[] }>;
}

export default async function ConversationsPage({ searchParams }: ConversationsPageProps) {
  const query = await searchParams;
  const page = pageNumber(query.page);
  const nextPath = "/account/conversations";
  const api = await protectedClient(nextPath);

  try {
    const result = await api.conversations({ page, perPage: 30 });
    return (
      <div className="stack">
        <section className="hero">
          <h1>Konuşmalar</h1>
          <p>Destek ve hata bildirimi konuşma özetleri.</p>
        </section>
        {result.items.length === 0 ? (
          <div className="empty">Konuşma bulunmuyor.</div>
        ) : (
          <div className="stack">
            {result.items.map((conversation) => (
              <ForwextSurface heading={conversation.title} key={conversation.id}>
                <div className="meta">
                  <span>{conversation.type}</span>
                  <span>{conversation.status}</span>
                  <time dateTime={conversation.updated_at}>{formatDate(conversation.updated_at)}</time>
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
