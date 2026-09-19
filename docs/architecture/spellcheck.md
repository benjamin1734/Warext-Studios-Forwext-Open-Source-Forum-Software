# Spellcheck Architecture

Roadmap step 12.04 replaces the common content pipeline's spellcheck placeholder with an optional first-party spelling-assistance system. Spellcheck is advisory: it never silently rewrites submitted content and never blocks a valid post solely because a spelling suggestion exists.

## Provider abstraction

`SpellcheckProvider` is language-provider neutral. Providers declare the language tags they support and return typed `SpellcheckResult` / `SpellcheckIssue` values with Unicode code-point offsets and bounded suggestion lists. `SpellcheckProviderRegistry` resolves a provider by normalized language tag, allowing future languages or external engines without changing the editor or pipeline contracts.

The first-party `TurkishSpellcheckProvider` is intentionally conservative. It detects a curated set of common Turkish misspellings and produces deterministic corrections instead of treating every unknown domain term as an error. This avoids large false-positive rates on usernames, game names, product names and technical vocabulary.

## User and site dictionaries

Two database-backed dictionaries are provided:

- `forwext_spellcheck_user_dictionary`: per-user words, deleted with the owning user;
- `forwext_spellcheck_site_dictionary`: site-wide accepted words, with the last managing actor retained when possible.

Dictionary words are Unicode validated and normalized with Turkish-aware handling for I/İ/ı/i plus the other Turkish uppercase letters. User and site dictionary entries suppress matching provider findings.

Permissions are backend authoritative:

- `spellcheck.use` — run spelling assistance;
- `spellcheck.dictionary.manage_own` — manage the current user's dictionary;
- `spellcheck.dictionary.manage_site` — manage the shared site dictionary.

Built-in templates allow spellcheck and own-dictionary management for normal accounts. Site dictionary management is denied by default except to the administrator template.

## Editor integration

`POST /editor/spellcheck` requires an authenticated viewer and `spellcheck.use`. Content is sent in the POST body rather than the URL and responses are private/no-store. The endpoint returns issue offsets and suggestions only; it does not persist or modify the source.

The rich editor adds an explicit **Yazımı denetle** action. Findings are rendered with marked context. Selecting a finding focuses/selects the original word; selecting a suggestion replaces only that word. The browser refuses to apply a stale suggestion when the textarea has changed since the check was performed.

`GET|POST /account/spellcheck-dictionary` provides a CSRF-protected dictionary management surface. The editor exposes a base-path-aware link to that page.

## Content pipeline behavior

`SpellcheckPipelineProcessor` occupies the canonical spellcheck stage. When the actor lacks `spellcheck.use`, the stage degrades to a no-op and records `spellcheck.enabled=false`. When enabled, it records only provider/language/issue-count scalar metadata and leaves the original content untouched. This preserves the pipeline order without converting writing assistance into moderation.

## Security and runtime profile

- Input is UTF-8/control-character/size validated before checking.
- Suggestion counts and lengths are bounded.
- Dictionary mutations are permission checked and protected by CSRF at the native web surface.
- UI highlighting is created with DOM text nodes/`mark`; provider text is never inserted as raw HTML.
- The first-party Turkish provider has no network dependency and requires no additional PHP extension, Composer package, Node service or daemon.
- If no `SpellcheckService` is supplied to `ForumContentPipelineFactory`, the existing pass-through spellcheck stage remains available for graceful degradation.
