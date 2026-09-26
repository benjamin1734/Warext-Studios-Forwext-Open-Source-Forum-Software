import type { Metadata, Viewport } from "next";
import Link from "next/link";
import type { ReactNode } from "react";

import "@forwext/react-ui/styles.css";
import "./globals.css";

import { authBridgeConfigured, bridgedAuthCredential } from "@/lib/auth-bridge";
import { forwextPublicUrl } from "@/lib/runtime-config";

export const metadata: Metadata = {
  metadataBase: forwextPublicUrl(),
  title: {
    default: "Forwext",
    template: "%s | Forwext",
  },
  description: "Forwext resmi modern forum arayüzü.",
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  colorScheme: "dark light",
};

export default async function RootLayout({ children }: Readonly<{ children: ReactNode }>) {
  const auth = await bridgedAuthCredential();

  return (
    <html lang="tr">
      <body>
        <header className="site-header">
          <div className="shell header-row">
            <Link className="brand" href="/">Forwext</Link>
            <nav aria-label="Ana navigasyon" className="main-nav">
              <Link href="/forums">Forumlar</Link>
              <Link href="/marketplace">Marketplace</Link>
              <Link href="/modules">Modüller</Link>
              <Link href="/support">Destek</Link>
            </nav>
            <div className="account-nav">
              {auth === undefined ? (
                authBridgeConfigured() ? <Link href="/auth/connect">Hesabı bağla</Link> : <span>Misafir</span>
              ) : (
                <Link href="/account/notifications">Hesabım</Link>
              )}
            </div>
          </div>
        </header>
        <main className="shell page-shell">{children}</main>
        <footer className="site-footer">
          <div className="shell">Forwext modern frontend · Native PHP arayüzü bağımsız olarak çalışmaya devam eder.</div>
        </footer>
      </body>
    </html>
  );
}
