import type { Metadata } from "next";
import Link from "next/link";
import { ForwextSurface } from "@forwext/react-ui";

import { getMarketplaceListing } from "@/lib/data";
import { notFoundOn404 } from "@/lib/page-utils";

interface ListingPageProps {
  params: Promise<{ listingId: string }>;
}

export async function generateMetadata({ params }: ListingPageProps): Promise<Metadata> {
  const { listingId } = await params;
  try {
    const listing = await getMarketplaceListing(listingId);
    return {
      title: listing.title,
      description: listing.description.slice(0, 180),
    };
  } catch (error) {
    notFoundOn404(error);
  }
}

export default async function ListingPage({ params }: ListingPageProps) {
  const { listingId } = await params;

  try {
    const listing = await getMarketplaceListing(listingId);
    return (
      <div className="stack">
        <section className="hero">
          <h1>{listing.title}</h1>
          <div className="meta">
            <span>{formatMoney(listing.price_minor, listing.currency)}</span>
            <span>{listing.state}</span>
          </div>
        </section>
        <ForwextSurface heading="İlan açıklaması">
          <div className="post-body">{listing.description}</div>
        </ForwextSurface>
        <ForwextSurface heading="Satıcı">
          <Link href={"/users/" + listing.seller_user_id}>
            Satıcı profilini görüntüle
          </Link>
        </ForwextSurface>
      </div>
    );
  } catch (error) {
    notFoundOn404(error);
  }
}

function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat("tr-TR", {
    style: "currency",
    currency,
  }).format(minor / 100);
}
