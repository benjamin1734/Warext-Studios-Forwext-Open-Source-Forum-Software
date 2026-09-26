import type { Metadata } from "next";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import {
  pageNumber,
  protectedClient,
  reconnectOnUnauthorized,
} from "@/lib/page-utils";

export const metadata: Metadata = {
  title: "Bildirimler",
  robots: { index: false, follow: false },
};

interface NotificationsPageProps {
  searchParams: Promise<{ page?: string | string[] }>;
}

export default async function NotificationsPage({ searchParams }: NotificationsPageProps) {
  const query = await searchParams;
  const page = pageNumber(query.page);
  const nextPath = "/account/notifications";
  const api = await protectedClient(nextPath);

  try {
    const result = await api.notifications({ page, perPage: 30 });
    return (
      <div className="stack">
        <section className="hero">
          <h1>Bildirimler</h1>
          <p>Yalnızca bağlanan Forwext hesabına ait bildirimler.</p>
        </section>
        {result.items.length === 0 ? (
          <div className="empty">Bildirim bulunmuyor.</div>
        ) : (
          <div className="stack">
            {result.items.map((notification) => (
              <ForwextSurface heading={notification.title} key={notification.id}>
                <p>{notification.body}</p>
                <div className="meta">
                  <span>{notification.category}</span>
                  <span>{notification.read_at === null ? "Okunmadı" : "Okundu"}</span>
                  <time dateTime={notification.created_at}>{formatDate(notification.created_at)}</time>
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
