/**
 * Runs Python in a dedicated worker. An infinite loop cannot be interrupted
 * inside Pyodide without cross-origin isolation, so on timeout the worker is
 * terminated and a fresh one is started in the background.
 */
export class PythonRunner {
    constructor(indexURL, timeoutSeconds = 5) {
        this.indexURL = indexURL;
        this.timeoutMs = timeoutSeconds * 1000;
        this.nextId = 1;
        this.pending = new Map();
        this.ready = false;
        this.spawn();
    }

    spawn() {
        this.worker?.terminate();
        this.worker = new Worker('/js/pyodide-worker.js');
        this.worker.onmessage = (event) => {
            const pending = this.pending.get(event.data.id);

            if (pending) {
                this.pending.delete(event.data.id);
                clearTimeout(pending.timer);
                pending.resolve(event.data);
            }
        };
        this.ready = false;
        this.warmup = this.send({ type: 'warmup' }, 120000).then((reply) => {
            this.ready = reply.ok;

            return reply;
        });
    }

    send(message, timeoutMs) {
        const id = this.nextId++;

        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                resolve({ id, ok: false, timeout: true });
            }, timeoutMs);

            this.pending.set(id, { resolve, timer });
            this.worker.postMessage({ id, indexURL: this.indexURL, ...message });
        });
    }

    /**
     * @returns {Promise<{output: string, error: ?string, results: Array, passed: number, total: number, timedOut: boolean}>}
     */
    async run(code, tests) {
        await this.warmup;

        // Vue props are reactive proxies, which structured cloning rejects.
        const plainTests = JSON.parse(JSON.stringify(tests ?? []));
        const reply = await this.send({ type: 'run', code: String(code ?? ''), tests: plainTests }, this.timeoutMs);

        if (reply.timeout) {
            this.spawn();

            return {
                output: '',
                error: `Kod ${this.timeoutMs / 1000} saniye içinde bitmedi. Sonsuz döngü veya durmayan özyineleme olabilir.`,
                results: [],
                passed: 0,
                total: tests.length,
                timedOut: true,
            };
        }

        if (!reply.ok) {
            return { output: '', error: `Çalıştırma ortamı hatası: ${reply.error}`, results: [], passed: 0, total: tests.length, timedOut: false };
        }

        const results = reply.result.results;

        return {
            output: reply.result.output,
            error: reply.result.error,
            results,
            passed: results.filter((row) => row.ok).length,
            total: tests.length,
            timedOut: false,
        };
    }

    destroy() {
        this.worker?.terminate();
    }
}

export function hashCode(text) {
    let hash = 0x811c9dc5;

    for (let i = 0; i < text.length; i++) {
        hash ^= text.charCodeAt(i);
        hash = Math.imul(hash, 0x01000193);
    }

    return (hash >>> 0).toString(16).padStart(8, '0');
}
