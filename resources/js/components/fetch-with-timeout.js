// Shared wrapper around fetch() that aborts a request which never resolves —
// a hung reverse proxy, a stalled connection, a server that never responds —
// so an Alpine `saving`/`processing` flag (and the button it disables) can
// never get stuck forever. Without this, a plain fetch() has no timeout at
// all; the browser will happily wait indefinitely.
//
// Rejects with a DOMException named 'AbortError' on timeout — the same
// error shape a fetch() call already produces when *the caller* aborts it,
// so existing `catch (e)` blocks only need one extra `e.name === 'AbortError'`
// branch to show a "this is taking too long" message instead of the generic
// network-error one.
export async function fetchWithTimeout(url, options = {}, timeoutMs = 30000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } finally {
        clearTimeout(timeoutId);
    }
}
