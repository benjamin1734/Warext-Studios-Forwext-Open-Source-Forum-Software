(() => {
    'use strict';

    const form = document.querySelector('[data-auth-passkey-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const button = form.querySelector('[data-auth-passkey-button]');
    const optionsInput = form.querySelector('[data-auth-passkey-options]');
    const responseInput = form.querySelector('[data-auth-passkey-response]');
    const status = form.querySelector('[data-auth-passkey-status]');

    if (!(button instanceof HTMLButtonElement)
        || !(optionsInput instanceof HTMLInputElement)
        || !(responseInput instanceof HTMLInputElement)
    ) {
        return;
    }

    const showStatus = (message) => {
        if (!(status instanceof HTMLElement)) {
            return;
        }
        status.hidden = false;
        status.textContent = message;
    };

    const bytesToBase64Url = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (const byte of bytes) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/u, '');
    };

    const base64UrlToBytes = (value) => {
        const normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
        const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        return bytes;
    };

    const decodeOptions = () => {
        const binary = atob(optionsInput.value);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        return JSON.parse(new TextDecoder().decode(bytes));
    };

    const requestOptions = (json) => {
        if (typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function') {
            return PublicKeyCredential.parseRequestOptionsFromJSON(json);
        }

        const publicKey = { ...json, challenge: base64UrlToBytes(json.challenge) };
        if (Array.isArray(json.allowCredentials)) {
            publicKey.allowCredentials = json.allowCredentials.map((credential) => ({
                ...credential,
                id: base64UrlToBytes(credential.id),
            }));
        }
        return publicKey;
    };

    const credentialJson = (credential) => {
        const assertion = credential.response;
        return {
            id: credential.id,
            rawId: bytesToBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: bytesToBase64Url(assertion.clientDataJSON),
                authenticatorData: bytesToBase64Url(assertion.authenticatorData),
                signature: bytesToBase64Url(assertion.signature),
                userHandle: assertion.userHandle === null
                    ? null
                    : bytesToBase64Url(assertion.userHandle),
            },
            clientExtensionResults: credential.getClientExtensionResults(),
            authenticatorAttachment: credential.authenticatorAttachment ?? null,
        };
    };

    if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.get !== 'function') {
        button.disabled = true;
        showStatus('Bu tarayıcı passkey doğrulamasını desteklemiyor. Başka bir MFA yöntemi kullan.');
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;
        showStatus('Passkey doğrulaması başlatılıyor…');

        try {
            const credential = await navigator.credentials.get({
                publicKey: requestOptions(decodeOptions()),
            });
            if (!(credential instanceof PublicKeyCredential)) {
                throw new Error('Passkey credential was not returned.');
            }

            responseInput.value = JSON.stringify(credentialJson(credential));
            showStatus('Passkey doğrulandı. Oturum tamamlanıyor…');
            form.submit();
        } catch (error) {
            responseInput.value = '';
            button.disabled = false;

            if (error instanceof DOMException && error.name === 'NotAllowedError') {
                showStatus('Passkey doğrulaması iptal edildi veya zaman aşımına uğradı.');
                return;
            }

            showStatus('Passkey doğrulaması tamamlanamadı. Yeniden deneyebilir veya başka bir MFA yöntemi kullanabilirsin.');
        }
    });
})();
