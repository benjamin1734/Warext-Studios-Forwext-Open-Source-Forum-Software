import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { Pager } from "@/components/Pager";
import { createForwextClient } from "@/lib/forwext";
import { pageNumber } from "@/lib/page-utils";

export const metadata: Metadata = {
  title: "Marketplace",
  description: "Forwext Marketplace ilanları.",
};

interface MarketplacePageProps {
  searchParams: Promise<{ page?: string | string[] }>;
}

export default async function MarketplacePage({ searchParams }: MarketplacePageProps) {
  const query = await searchParams;
  const page = pageNumber(query.page);
  const result = await createForwextClient().marketplace({ page, perPage: 24 });

  return (
    <div className="stack">
      <section className="hero">
        <h1>Marketplace</h1>
        <p>Topluluk tarafından yayınlanan herkese açık ilanlar.</p>
      </section>
      {result.items.length === 0 ? (
        <div className="empty">Bu sayfada ilan bulunmuyor.</div>
      ) : (
        <div className="grid">
          {result.items.map((listing) => (
            <Link className="card-link" href={"/marketplace/listings/" + listing.id} key={listing.id}>
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
      )}
      <Pager hasMore={result.pagination.has_more} page={page} path="/marketplace" />
    </div>
  );
}

function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat("tr-TR", {
    style: "currency",
    currency,
  }).format(minor / 100);
}
