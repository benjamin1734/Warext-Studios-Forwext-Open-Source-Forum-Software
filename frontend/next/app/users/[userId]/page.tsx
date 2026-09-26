import type { Metadata } from "next";
import { ForwextSurface } from "@forwext/react-ui";

import { getUser } from "@/lib/data";
import { notFoundOn404 } from "@/lib/page-utils";

interface MemberPageProps {
  params: Promise<{ userId: string }>;
}

export async function generateMetadata({ params }: MemberPageProps): Promise<Metadata> {
  const { userId } = await params;
  try {
    const user = await getUser(userId);
    return {
      title: user.username,
      description: user.username + " Forwext üye profili.",
    };
  } catch (error) {
    notFoundOn404(error);
  }
}

export default async function MemberPage({ params }: MemberPageProps) {
  const { userId } = await params;

  try {
    const user = await getUser(userId);
    return (
      <div className="stack">
        <section className="hero">
          <h1>{user.username}</h1>
          <p>Herkese açık Forwext üye özeti.</p>
        </section>
        <ForwextSurface heading="Üyelik">
          <div className="meta">
            <span>Kayıt: {formatDate(user.created_at)}</span>
            <span>Güncelleme: {formatDate(user.updated_at)}</span>
          </div>
        </ForwextSurface>
      </div>
    );
  } catch (error) {
    notFoundOn404(error);
  }
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "medium" }).format(new Date(value));
}
