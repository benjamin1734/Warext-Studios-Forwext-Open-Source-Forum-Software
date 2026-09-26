import { ForwextAlert, ForwextButton, ForwextSurface } from "@forwext/react-ui";
import { redirect } from "next/navigation";

import { connectCredential, disconnectCredential } from "@/app/auth/connect/actions";
import {
  authBridgeConfigured,
  bridgedAuthCredential,
} from "@/lib/auth-bridge";

interface ConnectPageProps {
  searchParams: Promise<{
    error?: string;
    next?: string;
  }>;
}

export default async function ConnectPage({ searchParams }: ConnectPageProps) {
  const params = await searchParams;
  const current = await bridgedAuthCredential();

  if (current !== undefined && params.error === undefined) {
    redirect("/account/notifications");
  }

  const configured = authBridgeConfigured();

  return (
    <div className="stack">
      <section className="hero">
        <h1>Forwext hesabını bağla</h1>
        <p>
          API kimlik bilgin yalnızca sunucu tarafında doğrulanır ve AES-256-GCM ile mühürlenmiş,
          HttpOnly oturum çerezinde tutulur.
        </p>
      </section>

      {!configured ? (
        <ForwextAlert tone="warning" title="Auth bridge yapılandırılmamış">
          Sunucuda FORWEXT_NEXT_AUTH_SECRET tanımlanmadan hesap bağlantısı kullanılamaz.
        </ForwextAlert>
      ) : null}

      {params.error === "credential" ? (
        <ForwextAlert tone="danger" title="Kimlik bilgisi reddedildi">
          Girilen API kimlik bilgisi Forwext API tarafından doğrulanamadı.
        </ForwextAlert>
      ) : null}

      {params.error === "configuration" ? (
        <ForwextAlert tone="danger" title="Sunucu yapılandırması eksik">
          Auth bridge güvenlik anahtarı tanımlı değil.
        </ForwextAlert>
      ) : null}

      <ForwextSurface heading="API kimlik bilgisi">
        <form action={connectCredential} className="form-grid">
          <input name="next" type="hidden" value={safeNext(params.next)} />
          <label className="field">
            <span>Kimlik türü</span>
            <select defaultValue="bearer" name="type">
              <option value="bearer">Personal/OAuth Bearer Token</option>
              <option value="apiKey">API Key</option>
            </select>
          </label>
          <label className="field">
            <span>Kimlik bilgisi</span>
            <input
              autoComplete="off"
              disabled={!configured}
              maxLength={256}
              minLength={10}
              name="credential"
              required
              type="password"
            />
          </label>
          <ForwextButton disabled={!configured} type="submit">Hesabı bağla</ForwextButton>
        </form>
      </ForwextSurface>

      {current === undefined ? null : (
        <form action={disconnectCredential}>
          <ForwextButton tone="neutral" type="submit">Mevcut bağlantıyı kaldır</ForwextButton>
        </form>
      )}
    </div>
  );
}

function safeNext(value: string | undefined): string {
  if (
    value !== undefined &&
    value.startsWith("/") &&
    !value.startsWith("//") &&
    value.length <= 256
  ) {
    return value;
  }
  return "/account/notifications";
}
