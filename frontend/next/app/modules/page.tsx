import type { Metadata } from "next";
import { ForwextSurface } from "@forwext/react-ui";

import { createForwextClient } from "@/lib/forwext";

export const metadata: Metadata = {
  title: "Modüller",
  description: "Forwext kurulumundaki birinci taraf modüllerin durumu.",
};

export default async function ModulesPage() {
  const result = await createForwextClient().modules({ perPage: 100 });

  return (
    <div className="stack">
      <section className="hero">
        <h1>Modüller</h1>
        <p>Bu Forwext kurulumunda yayınlanan birinci taraf modül durumları.</p>
      </section>
      {result.items.length === 0 ? (
        <div className="empty">Yayınlanan modül bulunmuyor.</div>
      ) : (
        <div className="grid">
          {result.items.map((module) => (
            <ForwextSurface heading={module.key} key={module.key}>
              <span className="muted">{module.state}</span>
            </ForwextSurface>
          ))}
        </div>
      )}
    </div>
  );
}
