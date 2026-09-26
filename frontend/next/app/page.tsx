import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { createForwextClient } from "@/lib/forwext";

export const dynamic = "force-dynamic";

export default async function HomePage() {
  const api = createForwextClient();
  const [forums, marketplace, modules] = await Promise.allSettled([
    api.forums({ perPage: 6 }),
    api.marketplace({ perPage: 6 }),
    api.modules({ perPage: 12 }),
  ]);

  return (
    <div className="stack">
      <section className="hero">
        <h1>Topluluğa tek yerden bağlan.</h1>
        <p>Forumlar, Marketplace ve etkin modüller resmi Forwext API v1 üzerinden sunulur.</p>
      </section>

      <section className="stack" aria-labelledby="forums-heading">
        <h2 className="section-title" id="forums-heading">Forumlar</h2>
        {forums.status === "fulfilled" && forums.value.items.length > 0 ? (
          <div className="grid">
            {forums.value.items.map((forum) => (
              <Link className="card-link" href={"/forums/" + forum.id} key={forum.id}>
                <ForwextSurface heading={forum.title}>
                  <p className="muted">{forum.description || "Forum açıklaması bulunmuyor."}</p>
                </ForwextSurface>
              </Link>
            ))}
          </div>
        ) : (
          <div className="empty">Forum verisi şu anda alınamıyor.</div>
        )}
        <Link href="/forums">Tüm forumları görüntüle →</Link>
      </section>

      <section className="stack" aria-labelledby="market-heading">
        <h2 className="section-title" id="market-heading">Marketplace</h2>
        {marketplace.status === "fulfilled" && marketplace.value.items.length > 0 ? (
          <div className="grid">
            {marketplace.value.items.map((listing) => (
              <Link className="card-link" href={"/marketplace/" + listing.id} key={listing.id}>
                <ForwextSurface heading={listing.title}>
                  <p className="muted">{listing.description}</p>
                  <div className="meta">
                    <span>{formatMoney(listing.price_minor, listing.currency)}</span>
                    <span>{listing.state}</span>
                  </div>
                </ForwextSurface>
              </Link>
            ))}
          </div>
        ) : (
          <div className="empty">Marketplace verisi şu anda alınamıyor.</div>
        )}
        <Link href="/marketplace">Marketplace'i aç →</Link>
      </section>

      <section className="stack" aria-labelledby="modules-heading">
        <h2 className="section-title" id="modules-heading">Etkin modüller</h2>
        {modules.status === "fulfilled" && modules.value.items.length > 0 ? (
          <div className="meta">
            {modules.value.items.map((module) => (
              <span key={module.key}>{module.key}: {module.state}</span>
            ))}
          </div>
        ) : (
          <div className="empty">Modül durumu şu anda alınamıyor.</div>
        )}
      </section>
    </div>
  );
}

function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat("tr-TR", {
    style: "currency",
    currency,
  }).format(minor / 100);
}
