(() => {
    'use strict';

    let unicodeWords = null;
    let mentionToken = null;
    try {
        unicodeWords = new RegExp("[\\p{L}\\p{N}]+(?:['’_-][\\p{L}\\p{N}]+)*", 'gu');
        mentionToken = new RegExp('(?:^|\\s)@([\\p{L}\\p{N}._-]{1,32})$', 'u');
    } catch (_error) {
        unicodeWords = /[A-Za-z0-9]+(?:['_-][A-Za-z0-9]+)*/g;
        mentionToken = /(?:^|\s)@([A-Za-z0-9._-]{1,32})$/;
    }

    const byteLength = (value) => {
        if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(value).length;
        return new Blob([value]).size;
    };

    const metrics = (value) => {
        const characters = Array.from(value).length;
        const matches = value.match(unicodeWords);
        return { characters, words: matches === null ? 0 : matches.length, bytes: byteLength(value) };
    };

    const number = (root, name, fallback = 0) => {
        const raw = root.dataset[name];
        if (raw === undefined || raw === '') return fallback;
        const parsed = Number.parseInt(raw, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const optionalNumber = (root, name) => {
        const raw = root.dataset[name];
        if (raw === undefined || raw === '') return null;
        const parsed = Number.parseInt(raw, 10);
        return Number.isFinite(parsed) ? parsed : null;
    };

    const localViolations = (root, current) => {
        const violations = [];
        const minCharacters = number(root, 'minCharacters', 0);
        const maxCharacters = number(root, 'maxCharacters', Number.MAX_SAFE_INTEGER);
        const maxBytes = number(root, 'maxBytes', Number.MAX_SAFE_INTEGER);
        const minWords = number(root, 'minWords', 0);
        const maxWords = optionalNumber(root, 'maxWords');
        if (current.characters < minCharacters) violations.push('characters.minimum');
        if (current.characters > maxCharacters) violations.push('characters.maximum');
        if (current.bytes > maxBytes) violations.push('bytes.maximum');
        if (current.words < minWords) violations.push('words.minimum');
        if (maxWords !== null && current.words > maxWords) violations.push('words.maximum');
        return violations;
    };

    const validityMessage = (violations) => {
        if (violations.length === 0) return '';
        if (violations.includes('bytes.maximum')) return 'İçerik byte sınırını aşıyor.';
        if (violations.includes('characters.maximum')) return 'İçerik karakter sınırını aşıyor.';
        if (violations.includes('characters.minimum')) return 'İçerik minimum karakter sınırının altında.';
        if (violations.includes('words.maximum')) return 'İçerik kelime sınırını aşıyor.';
        if (violations.includes('words.minimum')) return 'İçerik minimum kelime sınırının altında.';
        return 'İçerik sınırları karşılamıyor.';
    };

    const updateMetrics = (root, source) => {
        const current = metrics(source.value);
        const violations = localViolations(root, current);
        const characterNode = root.querySelector('[data-fx-editor-characters]');
        const wordNode = root.querySelector('[data-fx-editor-words]');
        const byteNode = root.querySelector('[data-fx-editor-bytes]');
        const status = root.querySelector('[data-fx-editor-status]');
        if (characterNode) characterNode.textContent = `Karakter: ${current.characters}`;
        if (wordNode) wordNode.textContent = `Kelime: ${current.words}`;
        if (byteNode) byteNode.textContent = `Byte: ${current.bytes}`;
        source.setCustomValidity(validityMessage(violations));
        root.classList.toggle('fx-editor--invalid', violations.length > 0);
        if (status && status.dataset.previewState !== 'loading') {
            status.textContent = violations.length === 0 ? 'Sınırlar uygun.' : validityMessage(violations);
        }
        return { current, violations };
    };

    const replaceRange = (source, start, end, replacement, cursorOffset = replacement.length) => {
        source.setRangeText(replacement, start, end, 'end');
        const cursor = start + cursorOffset;
        source.setSelectionRange(cursor, cursor);
        source.dispatchEvent(new Event('input', { bubbles: true }));
        source.focus();
    };

    const replaceSelection = (source, replacement, cursorOffset = replacement.length) => {
        const start = source.selectionStart ?? source.value.length;
        const end = source.selectionEnd ?? start;
        replaceRange(source, start, end, replacement, cursorOffset);
    };

    const wrapSelection = (source, open, close) => {
        const start = source.selectionStart ?? 0;
        const end = source.selectionEnd ?? start;
        const selected = source.value.slice(start, end);
        const replacement = `${open}${selected}${close}`;
        source.setRangeText(replacement, start, end, 'end');
        if (selected === '') {
            const cursor = start + open.length;
            source.setSelectionRange(cursor, cursor);
        } else {
            source.setSelectionRange(start + open.length, start + open.length + selected.length);
        }
        source.dispatchEvent(new Event('input', { bubbles: true }));
        source.focus();
    };

    const setStatus = (root, message, state = '') => {
        const status = root.querySelector('[data-fx-editor-status]');
        if (!status) return;
        status.textContent = message;
        status.dataset.previewState = state;
    };

    const hideMentionMenu = (root) => {
        const menu = root.querySelector('[data-fx-editor-mention-menu]');
        if (!(menu instanceof HTMLElement)) return;
        menu.replaceChildren();
        menu.hidden = true;
    };

    const mentionContext = (source) => {
        const cursor = source.selectionStart ?? 0;
        if (cursor !== (source.selectionEnd ?? cursor)) return null;
        const before = source.value.slice(0, cursor);
        const match = before.match(mentionToken);
        if (!match || typeof match[1] !== 'string') return null;
        const query = match[1];
        return { query, start: cursor - query.length - 1, end: cursor };
    };

    const fetchMentionItems = async (root, query) => {
        const endpoint = root.dataset.mentionUrl;
        if (!endpoint) return [];
        const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}&limit=8`, {
            method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok || !Array.isArray(payload.items)) return [];
        return payload.items.filter((item) => item && typeof item.id === 'string'
            && /^[a-f0-9]{32}$/.test(item.id) && typeof item.label === 'string');
    };

    const renderMentionMenu = (root, source, context, items) => {
        const menu = root.querySelector('[data-fx-editor-mention-menu]');
        if (!(menu instanceof HTMLElement)) return;
        menu.replaceChildren();
        if (items.length === 0) {
            menu.hidden = true;
            return;
        }
        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.className = 'fx-editor__mention-option';
            button.textContent = item.label;
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                replaceRange(source, context.start, context.end, `[mention=${item.id}]`);
                hideMentionMenu(root);
                setStatus(root, `${item.label} eklendi.`);
            });
            menu.append(button);
        });
        menu.hidden = false;
    };

    const scheduleMentionAutocomplete = (root, source) => {
        window.clearTimeout(root._fxMentionTimer);
        root._fxMentionSerial = (root._fxMentionSerial ?? 0) + 1;
        const serial = root._fxMentionSerial;
        const context = mentionContext(source);
        if (context === null) {
            hideMentionMenu(root);
            return;
        }
        root._fxMentionTimer = window.setTimeout(async () => {
            try {
                const items = await fetchMentionItems(root, context.query);
                if (serial !== root._fxMentionSerial) return;
                const current = mentionContext(source);
                if (current === null || current.start !== context.start || current.query !== context.query) return;
                renderMentionMenu(root, source, current, items);
            } catch (_error) {
                if (serial === root._fxMentionSerial) hideMentionMenu(root);
            }
        }, 150);
    };

    const resolveMentionPrompt = async (root, source) => {
        const username = window.prompt('Kullanıcı adı');
        if (username === null || username.trim() === '') return;
        setStatus(root, 'Kullanıcı aranıyor…', 'loading');
        try {
            const items = await fetchMentionItems(root, username.trim());
            const lowered = username.trim().toLocaleLowerCase();
            const item = items.find((candidate) => (candidate.username ?? '').toLocaleLowerCase() === lowered) ?? items[0];
            if (!item) {
                setStatus(root, 'Kullanıcı bulunamadı.');
                return;
            }
            replaceSelection(source, `[mention=${item.id}]`);
            setStatus(root, `${item.label} eklendi.`);
        } catch (_error) {
            setStatus(root, 'Kullanıcı araması başarısız oldu.');
        }
    };

    const requestQuote = async (root, source, postId) => {
        const endpoint = root.dataset.quoteUrl;
        if (!endpoint || !/^[a-f0-9]{32}$/.test(postId)) return false;
        setStatus(root, 'Alıntı hazırlanıyor…', 'loading');
        try {
            const response = await fetch(`${endpoint}?post_id=${encodeURIComponent(postId)}`, {
                method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            if (!response.ok || typeof payload.bbcode !== 'string') {
                setStatus(root, 'Mesaj alıntılanamıyor.');
                return false;
            }
            replaceSelection(source, payload.bbcode);
            setStatus(root, 'Alıntı eklendi.');
            return true;
        } catch (_error) {
            setStatus(root, 'Alıntı isteği başarısız oldu.');
            return false;
        }
    };

    const renderLinkPreview = (root, preview) => {
        const container = root.querySelector('[data-fx-editor-link-preview]');
        if (!(container instanceof HTMLElement)) return;
        container.replaceChildren();
        const card = document.createElement('div');
        card.className = 'fx-editor__link-card';
        const heading = document.createElement('strong');
        heading.textContent = preview.title || preview.host || 'Bağlantı';
        const host = document.createElement('span');
        host.textContent = preview.host || '';
        card.append(heading, host);
        if (preview.description) {
            const description = document.createElement('p');
            description.textContent = preview.description;
            card.append(description);
        }
        const actions = document.createElement('div');
        actions.className = 'fx-editor__link-actions';
        const embed = document.createElement('button');
        embed.type = 'button';
        embed.textContent = 'Embed olarak ekle';
        embed.addEventListener('click', () => {
            const source = root.querySelector('[data-fx-editor-source]');
            if (source instanceof HTMLTextAreaElement && typeof preview.url === 'string') {
                replaceSelection(source, `[embed]${preview.url}[/embed]`);
                container.hidden = true;
            }
        });
        actions.append(embed);
        card.append(actions);
        container.append(card);
        container.hidden = false;
    };

    const requestLinkPreview = async (root, url) => {
        const endpoint = root.dataset.linkPreviewUrl;
        if (!endpoint) return false;
        setStatus(root, 'Link önizlemesi hazırlanıyor…', 'loading');
        try {
            const body = new URLSearchParams();
            body.set('url', url);
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Forwext-Editor': '1',
                },
                body: body.toString(),
            });
            const payload = await response.json();
            if (!response.ok || typeof payload.url !== 'string') {
                setStatus(root, 'Link önizlemesi oluşturulamadı.');
                return false;
            }
            renderLinkPreview(root, payload);
            setStatus(root, 'Link önizlemesi hazır.');
            return true;
        } catch (_error) {
            setStatus(root, 'Link önizlemesi isteği başarısız oldu.');
            return false;
        }
    };

    const codePointIndexToCodeUnit = (value, index) => Array.from(value).slice(0, Math.max(0, index)).join('').length;

    const hideSpellcheck = (root) => {
        const container = root.querySelector('[data-fx-editor-spellcheck]');
        if (!(container instanceof HTMLElement)) return;
        container.replaceChildren();
        container.hidden = true;
    };

    const renderSpellcheck = (root, source, payload, snapshot) => {
        const container = root.querySelector('[data-fx-editor-spellcheck]');
        if (!(container instanceof HTMLElement)) return;
        container.replaceChildren();

        const issues = Array.isArray(payload.issues) ? payload.issues : [];
        const heading = document.createElement('strong');
        heading.textContent = issues.length === 0
            ? 'Yazım denetimi: sorun bulunamadı.'
            : 'Yazım denetimi: ' + issues.length + ' öneri';
        container.append(heading);

        if (issues.length === 0) {
            container.hidden = false;
            return;
        }

        const snapshotCharacters = Array.from(snapshot);
        issues.forEach((issue) => {
            if (!issue || typeof issue.word !== 'string'
                || !Number.isInteger(issue.start) || issue.start < 0
                || !Number.isInteger(issue.length) || issue.length < 1
                || !Array.isArray(issue.suggestions)
            ) return;

            const item = document.createElement('div');
            item.className = 'fx-editor__spellcheck-item';

            const context = document.createElement('button');
            context.type = 'button';
            context.className = 'fx-editor__spellcheck-context';
            const contextStart = Math.max(0, issue.start - 18);
            const contextEnd = Math.min(snapshotCharacters.length, issue.start + issue.length + 18);
            context.append(document.createTextNode(snapshotCharacters.slice(contextStart, issue.start).join('')));
            const mark = document.createElement('mark');
            mark.textContent = snapshotCharacters.slice(issue.start, issue.start + issue.length).join('');
            context.append(mark);
            context.append(document.createTextNode(snapshotCharacters.slice(issue.start + issue.length, contextEnd).join('')));
            context.addEventListener('click', () => {
                if (source.value !== snapshot) {
                    setStatus(root, 'Metin değişti; yazım denetimini yeniden çalıştır.');
                    hideSpellcheck(root);
                    return;
                }
                const start = codePointIndexToCodeUnit(snapshot, issue.start);
                const end = codePointIndexToCodeUnit(snapshot, issue.start + issue.length);
                source.setSelectionRange(start, end);
                source.focus();
            });
            item.append(context);

            const suggestions = document.createElement('div');
            suggestions.className = 'fx-editor__spellcheck-suggestions';
            issue.suggestions
                .filter((value) => typeof value === 'string' && value.length > 0)
                .slice(0, 8)
                .forEach((suggestion) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = suggestion;
                    button.addEventListener('click', () => {
                        if (source.value !== snapshot) {
                            setStatus(root, 'Metin değişti; yazım denetimini yeniden çalıştır.');
                            hideSpellcheck(root);
                            return;
                        }
                        const start = codePointIndexToCodeUnit(snapshot, issue.start);
                        const end = codePointIndexToCodeUnit(snapshot, issue.start + issue.length);
                        replaceRange(source, start, end, suggestion);
                        hideSpellcheck(root);
                        setStatus(root, 'Öneri uygulandı. Denetimi yeniden çalıştırabilirsin.');
                    });
                    suggestions.append(button);
                });
            item.append(suggestions);
            container.append(item);
        });

        container.hidden = false;
    };

    const requestSpellcheck = async (root, source) => {
        const endpoint = root.dataset.spellcheckUrl;
        if (!endpoint) return false;
        const snapshot = source.value;
        if (snapshot.trim() === '') {
            hideSpellcheck(root);
            setStatus(root, 'Yazım denetimi için metin gerekli.');
            return false;
        }

        setStatus(root, 'Yazım denetleniyor…', 'loading');
        try {
            const body = new URLSearchParams();
            body.set('source', snapshot);
            body.set('language', root.dataset.spellcheckLanguage || 'tr-tr');
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                body: body.toString(),
            });
            const payload = await response.json();
            if (!response.ok || !Array.isArray(payload.issues)) {
                hideSpellcheck(root);
                setStatus(root, response.status === 403
                    ? 'Yazım denetimi için yetkin yok.'
                    : 'Yazım denetimi tamamlanamadı.');
                return false;
            }
            if (source.value !== snapshot) {
                hideSpellcheck(root);
                setStatus(root, 'Metin denetim sırasında değişti; tekrar çalıştır.');
                return false;
            }
            renderSpellcheck(root, source, payload, snapshot);
            setStatus(root, payload.issues.length === 0
                ? 'Yazım denetimi tamamlandı; öneri yok.'
                : payload.issues.length + ' yazım önerisi bulundu.');
            return true;
        } catch (_error) {
            hideSpellcheck(root);
            setStatus(root, 'Yazım denetimi isteği başarısız oldu.');
            return false;
        }
    };
    const requestPreview = async (root, source) => {
        const endpoint = root.dataset.previewUrl;
        const preview = root.querySelector('[data-fx-editor-preview]');
        if (!endpoint || !preview) return;
        setStatus(root, 'Önizleme hazırlanıyor…', 'loading');
        try {
            const body = new URLSearchParams();
            body.set('source', source.value);
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString(),
            });
            const payload = await response.json();
            if (!response.ok || typeof payload.html !== 'string') {
                preview.hidden = true;
                setStatus(root, 'Önizleme oluşturulamadı.');
                return;
            }
            preview.innerHTML = payload.html;
            preview.hidden = false;
            setStatus(root, payload.valid === false ? 'Önizleme hazır; içerik limitleri karşılanmıyor.' : 'Önizleme hazır.');
        } catch (_error) {
            preview.hidden = true;
            setStatus(root, 'Önizleme isteği başarısız oldu.');
        }
    };

    const toggleEmoji = (root, button) => {
        const palette = root.querySelector('[data-fx-editor-emoji-palette]');
        if (!(palette instanceof HTMLElement)) return;
        palette.hidden = !palette.hidden;
        button.setAttribute('aria-expanded', palette.hidden ? 'false' : 'true');
    };

    const handleToolbar = async (root, source, button) => {
        const command = button.dataset.fxEditorCommand;
        if (command === 'wrap') {
            wrapSelection(source, button.dataset.open ?? '', button.dataset.close ?? '');
            return;
        }
        if (command === 'url') {
            const url = window.prompt('HTTPS veya site içi bağlantı');
            if (url === null || url.trim() === '') return;
            wrapSelection(source, `[url=${url.trim()}]`, '[/url]');
            return;
        }
        if (command === 'link-preview') {
            const url = window.prompt('Önizlenecek HTTPS bağlantısı');
            if (url !== null && url.trim() !== '') await requestLinkPreview(root, url.trim());
            return;
        }
        if (command === 'embed') {
            const url = window.prompt('Embed bağlantısı');
            if (url === null || url.trim() === '') return;
            replaceSelection(source, `[embed]${url.trim()}[/embed]`);
            return;
        }
        if (command === 'mention') {
            await resolveMentionPrompt(root, source);
            return;
        }
        if (command === 'quote-post') {
            const postId = window.prompt('Alıntılanacak mesaj kimliği');
            if (postId !== null) await requestQuote(root, source, postId.trim());
            return;
        }
        if (command === 'emoji') toggleEmoji(root, button);
    };

    const init = (root) => {
        if (!(root instanceof HTMLElement) || root.dataset.fxEditorReady === '1') return;
        const source = root.querySelector('[data-fx-editor-source]');
        if (!(source instanceof HTMLTextAreaElement)) return;
        root.dataset.fxEditorReady = '1';

        source.addEventListener('input', () => {
            updateMetrics(root, source);
            scheduleMentionAutocomplete(root, source);
            hideSpellcheck(root);
        });
        source.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') hideMentionMenu(root);
        });
        source.addEventListener('blur', () => window.setTimeout(() => hideMentionMenu(root), 120));
        root.querySelectorAll('[data-fx-editor-command]').forEach((button) => {
            button.addEventListener('click', () => void handleToolbar(root, source, button));
        });
        root.querySelectorAll('[data-fx-editor-emoji]').forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.getAttribute('data-fx-editor-emoji');
                if (key && /^[a-z0-9_-]{1,32}$/.test(key)) replaceSelection(source, `[emoji=${key}]`);
            });
        });
        const previewButton = root.querySelector('[data-fx-editor-preview-button]');
        if (previewButton) previewButton.addEventListener('click', () => void requestPreview(root, source));
        const spellcheckButton = root.querySelector('[data-fx-editor-spellcheck-button]');
        if (spellcheckButton) spellcheckButton.addEventListener('click', () => void requestSpellcheck(root, source));
        updateMetrics(root, source);
    };

    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-fx-editor]').forEach(init));

    window.ForwextRichEditor = Object.freeze({
        init,
        insertMention(root, userId) {
            if (!(root instanceof HTMLElement) || !/^[a-f0-9]{32}$/.test(userId)) return false;
            const source = root.querySelector('[data-fx-editor-source]');
            if (!(source instanceof HTMLTextAreaElement)) return false;
            replaceSelection(source, `[mention=${userId}]`);
            return true;
        },
        quotePost(root, postId) {
            if (!(root instanceof HTMLElement) || !/^[a-f0-9]{32}$/.test(postId)) return Promise.resolve(false);
            const source = root.querySelector('[data-fx-editor-source]');
            if (!(source instanceof HTMLTextAreaElement)) return Promise.resolve(false);
            return requestQuote(root, source, postId);
        },
        previewLink(root, url) {
            if (!(root instanceof HTMLElement) || typeof url !== 'string' || url.trim() === '') return Promise.resolve(false);
            return requestLinkPreview(root, url.trim());
        },
        spellcheck(root) {
            if (!(root instanceof HTMLElement)) return Promise.resolve(false);
            const source = root.querySelector('[data-fx-editor-source]');
            if (!(source instanceof HTMLTextAreaElement)) return Promise.resolve(false);
            return requestSpellcheck(root, source);
        },
    });
})();
