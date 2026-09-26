import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { createForwextClient } from "@/lib/forwext";

export const metadata: Metadata = {
  title: "Destek",
  description: "Forwext destek kategorileri ve hesap destek alanı.",
};

export default async function SupportPage() {
  const categories = await createForwextClient().supportCategories({ perPage: 100 });

  return (
    <div className="stack">
      <section className="hero">
        <h1>Destek</h1>
        <p>Uygun kategoriyi inceleyin veya kendi destek kayıtlarınıza geçin.</p>
      </section>
      <Link href="/account/support">Destek kayıtlarım →</Link>
      {categories.items.length === 0 ? (
        <div className="empty">Destek kategorisi bulunmuyor.</div>
      ) : (
        <div className="grid">
          {categories.items.map((category) => (
            <ForwextSurface heading={category.label} key={category.key}>
              <p className="muted">{category.description}</p>
              <div className="meta">
                <span>{category.key}</span>
                <span>Öncelik: {category.default_priority}</span>
              </div>
            </ForwextSurface>
          ))}
        </div>
      )}
    </div>
  );
}
