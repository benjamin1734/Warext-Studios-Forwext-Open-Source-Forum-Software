import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import { getThread } from "@/lib/data";
import { createForwextClient } from "@/lib/forwext";
import { notFoundOn404, pageNumber } from "@/lib/page-utils";

interface ThreadPageProps {
  params: Promise<{ threadId: string }>;
  searchParams: Promise<{ page?: string | string[] }>;
}

export async function generateMetadata({ params }: ThreadPageProps): Promise<Metadata> {
  const { threadId } = await params;
  try {
    const thread = await getThread(threadId);
    return {
      title: thread.title,
      description: "Forwext tartışma konusu.",
    };
  } catch (error) {
    notFoundOn404(error);
  }
}

export default async function ThreadPage({ params, searchParams }: ThreadPageProps) {
  const [{ threadId }, query] = await Promise.all([params, searchParams]);
  const page = pageNumber(query.page);

  try {
    const [thread, posts] = await Promise.all([
      getThread(threadId),
      createForwextClient().threadPosts(threadId, { page, perPage: 30 }),
    ]);

    return (
      <div className="stack">
        <section className="hero">
          <h1>{thread.title}</h1>
          <div className="meta">
            <Link href={"/forums/" + thread.forum_id}>Foruma dön</Link>
            <span>{thread.type}</span>
            {thread.locked ? <span>Kilitli</span> : null}
          </div>
        </section>
        {posts.items.length === 0 ? (
          <div className="empty">Bu sayfada mesaj bulunmuyor.</div>
        ) : (
          <div className="stack">
            {posts.items.map((post) => (
              <ForwextSurface
                heading={
                  post.author_user_id === null ? (
                    "Silinmiş kullanıcı"
                  ) : (
                    <Link href={"/members/" + post.author_user_id}>
                      Kullanıcı {post.author_user_id.slice(0, 8)}
                    </Link>
                  )
                }
                key={post.id}
              >
                <div className="post-body">{post.body_source}</div>
                <div className="meta">
                  <span>#{post.position}</span>
                  <time dateTime={post.created_at}>{formatDate(post.created_at)}</time>
                </div>
              </ForwextSurface>
            ))}
          </div>
        )}
        <Pager
          hasMore={posts.pagination.has_more}
          page={page}
          path={"/threads/" + thread.id}
        />
      </div>
    );
  } catch (error) {
    notFoundOn404(error);
  }
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("tr-TR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
