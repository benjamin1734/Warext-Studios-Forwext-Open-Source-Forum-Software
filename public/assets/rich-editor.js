(() => {
    'use strict';

    let unicodeWords = null;
    try {
        unicodeWords = new RegExp("[\\p{L}\\p{N}]+(?:['’_-][\\p{L}\\p{N}]+)*", 'gu');
    } catch (_error) {
        unicodeWords = /[A-Za-z0-9]+(?:['_-][A-Za-z0-9]+)*/g;
    }

    const byteLength = (value) => {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(value).length;
        }
        return new Blob([value]).size;
    };

    const metrics = (value) => {
        const characters = Array.from(value).length;
        const matches = value.match(unicodeWords);
        return {
            characters,
            words: matches === null ? 0 : matches.length,
            bytes: byteLength(value),
        };
    };

    const number = (root, name, fallback = 0) => {
        const raw = root.dataset[name];
        if (raw === undefined || raw === '') {
            return fallback;
        }
        const parsed = Number.parseInt(raw, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const optionalNumber = (root, name) => {
        const raw = root.dataset[name];
        if (raw === undefined || raw === '') {
            return null;
        }
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

    const replaceSelection = (source, replacement, cursorOffset = replacement.length) => {
        const start = source.selectionStart ?? source.value.length;
        const end = source.selectionEnd ?? start;
        source.setRangeText(replacement, start, end, 'end');
        const cursor = start + cursorOffset;
        source.setSelectionRange(cursor, cursor);
        source.dispatchEvent(new Event('input', { bubbles: true }));
        source.focus();
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

    const resolveMention = async (root, source) => {
        const username = window.prompt('Kullanıcı adı');
        if (username === null || username.trim() === '') return;
        const endpoint = root.dataset.mentionUrl;
        if (!endpoint) return;

        setStatus(root, 'Kullanıcı aranıyor…', 'loading');
        try {
            const response = await fetch(`${endpoint}?username=${encodeURIComponent(username.trim())}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            const payload = await response.json();
            if (!response.ok || typeof payload.id !== 'string') {
                setStatus(root, 'Kullanıcı bulunamadı.');
                return;
            }
            replaceSelection(source, `[mention=${payload.id}]`);
            setStatus(root, `${payload.label ?? '@kullanıcı'} eklendi.`);
        } catch (_error) {
            setStatus(root, 'Kullanıcı araması başarısız oldu.');
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
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
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
        if (command === 'embed') {
            const url = window.prompt('Embed bağlantısı');
            if (url === null || url.trim() === '') return;
            replaceSelection(source, `[embed]${url.trim()}[/embed]`);
            return;
        }
        if (command === 'mention') {
            await resolveMention(root, source);
        }
    };

    const init = (root) => {
        if (!(root instanceof HTMLElement) || root.dataset.fxEditorReady === '1') return;
        const source = root.querySelector('[data-fx-editor-source]');
        if (!(source instanceof HTMLTextAreaElement)) return;
        root.dataset.fxEditorReady = '1';

        source.addEventListener('input', () => updateMetrics(root, source));
        root.querySelectorAll('[data-fx-editor-command]').forEach((button) => {
            button.addEventListener('click', () => void handleToolbar(root, source, button));
        });
        const previewButton = root.querySelector('[data-fx-editor-preview-button]');
        if (previewButton) {
            previewButton.addEventListener('click', () => void requestPreview(root, source));
        }
        updateMetrics(root, source);
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-fx-editor]').forEach(init);
    });

    window.ForwextRichEditor = Object.freeze({
        init,
        insertMention(root, userId) {
            if (!(root instanceof HTMLElement) || !/^[a-f0-9]{32}$/.test(userId)) return false;
            const source = root.querySelector('[data-fx-editor-source]');
            if (!(source instanceof HTMLTextAreaElement)) return false;
            replaceSelection(source, `[mention=${userId}]`);
            return true;
        },
    });
})();
