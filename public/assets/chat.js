(function () {
    const renameInput = document.querySelector('.chat-thread-tools input[name="title"]');
    renameInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.isComposing) {
            return;
        }
        event.preventDefault();
        renameInput.form?.requestSubmit();
    });

    const chatLog = document.querySelector('.chat-log[data-poll-url]');
    if (!chatLog) {
        return;
    }

    const intervalMilliseconds = 2000;
    const knownIds = new Set(
        Array.from(chatLog.querySelectorAll('[data-message-id]'))
            .map((message) => message.dataset.messageId)
            .filter(Boolean),
    );
    let cursorId = chatLog.dataset.cursorId || '';
    let polling = false;
    let timerId = null;

    function scrollToLatestMessage() {
        chatLog.scrollTop = chatLog.scrollHeight;
    }

    function schedule() {
        window.clearTimeout(timerId);
        timerId = window.setTimeout(poll, intervalMilliseconds);
    }

    function appendMessage(message) {
        if (!message || typeof message.id !== 'string' || knownIds.has(message.id)) {
            return;
        }

        const allowedRoles = new Set(['frank', 'paw', 'system', 'external']);
        const role = allowedRoles.has(message.role) ? message.role : 'external';
        const article = document.createElement('article');
        article.className = `chat-message ${role}`;
        article.dataset.messageId = message.id;

        const meta = document.createElement('span');
        meta.textContent = `${role} / ${message.observed_at || ''}`;
        const text = document.createElement('pre');
        text.textContent = typeof message.text === 'string' ? message.text : '';

        article.append(meta, text);
        chatLog.querySelector('[data-chat-empty]')?.remove();
        chatLog.append(article);
        knownIds.add(message.id);
    }

    async function poll() {
        if (polling || document.hidden) {
            schedule();
            return;
        }

        polling = true;
        try {
            const url = new URL(chatLog.dataset.pollUrl, window.location.href);
            if (cursorId) {
                url.searchParams.set('after', cursorId);
            }
            const response = await window.fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
            });
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) {
                window.console.warn(`OpenPaw chat polling failed with HTTP ${response.status}`);
                return;
            }

            const data = await response.json();
            const messages = Array.isArray(data.messages) ? data.messages : [];
            const nearBottom = chatLog.scrollHeight - chatLog.scrollTop - chatLog.clientHeight < 80;
            messages.forEach((message) => {
                appendMessage(message);
                if (message && typeof message.id === 'string') {
                    cursorId = message.id;
                    chatLog.dataset.cursorId = cursorId;
                }
            });
            if (nearBottom && messages.length > 0) {
                chatLog.scrollTop = chatLog.scrollHeight;
            }
        } catch (error) {
            window.console.warn('OpenPaw chat polling failed', error);
            return;
        } finally {
            polling = false;
            schedule();
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            void poll();
        }
    });
    window.requestAnimationFrame(scrollToLatestMessage);
    window.addEventListener('load', scrollToLatestMessage, {once: true});
    void poll();
})();
