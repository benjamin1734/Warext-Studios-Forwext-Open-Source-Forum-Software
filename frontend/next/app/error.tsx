"use client";

import { ForwextButton } from "@forwext/react-ui";
import { useEffect } from "react";

interface ErrorPageProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function ErrorPage({ error, reset }: ErrorPageProps) {
  useEffect(() => {
    // Do not render raw exception details. Deployment logging may collect the opaque digest.
    if (process.env.NODE_ENV !== "production") {
      console.error(error);
    }
  }, [error]);

  return (
    <section className="hero" role="alert">
      <h1>Sayfa yüklenemedi</h1>
      <p>İstek tamamlanamadı. Yeniden deneyebilir veya başka bir sayfaya geçebilirsiniz.</p>
      <div>
        <ForwextButton onClick={reset} type="button">
          Yeniden dene
        </ForwextButton>
      </div>
    </section>
  );
}
