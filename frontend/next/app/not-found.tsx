import Link from "next/link";

export default function NotFound() {
  return (
    <section className="hero">
      <h1>Sayfa bulunamadı</h1>
      <p>İstenen Forwext kaynağı bulunamadı veya artık erişilebilir değil.</p>
      <Link href="/">Ana sayfaya dön</Link>
    </section>
  );
}
