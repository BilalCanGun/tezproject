/**
 * Minimal JSON client for non-Inertia endpoints (kiosk API, live monitor).
 * Laravel sets the XSRF-TOKEN cookie on every web response; echoing it in the
 * X-XSRF-TOKEN header satisfies CSRF protection without a form token.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(status, body) {
        super(body?.message || `İstek başarısız (${status})`);
        this.status = status;
        this.body = body;
    }
}

export async function request(method, url, data = undefined, { keepalive = false, signal } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        keepalive,
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    const body = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        throw new HttpError(response.status, body);
    }

    return body;
}

export const http = {
    get: (url, options) => request('GET', url, undefined, options),
    post: (url, data, options) => request('POST', url, data, options),
    patch: (url, data, options) => request('PATCH', url, data, options),
};
