import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import { getForum } from "@/lib/data";
import { createForwextClient } from "@/lib/forwext";
import { notFoundOn404, pageNumber } from "@/lib/page-utils";

interface ForumPageProps {
  params: Promise<{ forumId: string }>;
  searchParams: Promise<{ page?: string | string[] }>;
}

export async function generateMetadata({ params }: ForumPageProps): Promise<Metadata> {
  const { forumId } = await params;
  try {
    const forum = await getForum(forumId);
    return {
      title: forum.title,
      description: forum.description || "Forwext forumu.",
    };
  } catch (error) {
    notFoundOn404(error);
  }
}

export default async function ForumPage({ params, searchParams }: ForumPageProps) {
  const [{ forumId }, query] = await Promise.all([params, searchParams]);
  const page = pageNumber(query.page);

  try {
    const [forum, threads] = await Promise.all([
      getForum(forumId),
      createForwextClient().forumThreads(forumId, { page, perPage: 30 }),
    ]);

    return (
      <div className="stack">
        <section className="hero">
          <h1>{forum.title}</h1>
          <p>{forum.description || "Bu forum için açıklama bulunmuyor."}</p>
        </section>
        {threads.items.length === 0 ? (
          <div className="empty">Bu sayfada konu bulunmuyor.</div>
        ) : (
          <div className="stack">
            {threads.items.map((thread) => (
              <Link className="card-link" href={"/threads/" + thread.id} key={thread.id}>
                <ForwextSurface heading={thread.title}>
                  <div className="meta">
                    <span>{thread.type}</span>
                    {thread.sticky ? <span>Sabit</span> : null}
                    {thread.featured ? <span>Öne çıkan</span> : null}
                    {thread.locked ? <span>Kilitli</span> : null}
                  </div>
                </ForwextSurface>
              </Link>
            ))}
          </div>
        )}
        <Pager
          hasMore={threads.pagination.has_more}
          page={page}
          path={"/forums/" + forum.id}
        />
      </div>
    );
  } catch (error) {
    notFoundOn404(error);
  }
}
