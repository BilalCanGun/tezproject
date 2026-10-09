import { http } from '@/lib/http';

const IMMEDIATE_TYPES = new Set(['step_start', 'step_end', 'task_submit', 'task_timeout', 'task_give_up', 'session_end', 'focus_lost']);

/**
 * Ordered, durable event log for one kiosk session. Every event gets a
 * monotonically increasing sequence number and two clocks (wall-clock epoch
 * and high-resolution performance time). Unsent events survive a reload in
 * localStorage; the server ignores sequences it has already stored.
 */
export class EventLog {
    constructor({ sessionId, endpoint, bridge, markerCodes, lastSequence = 0 }) {
        this.endpoint = endpoint;
        this.bridge = bridge;
        this.markerCodes = markerCodes;
        this.storageKey = `kiosk-events-${sessionId}`;
        this.seqKey = `kiosk-seq-${sessionId}`;
        this.queue = this.load();
        this.seq = Math.max(lastSequence, Number(localStorage.getItem(this.seqKey) || 0), ...this.queue.map((e) => e.seq));
        this.flushing = false;
        this.timer = setInterval(() => this.flush(), 1500);
        this.lateAcks = new Map();
    }

    load() {
        try {
            return JSON.parse(localStorage.getItem(this.storageKey) || '[]');
        } catch {
            return [];
        }
    }

    persist() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(this.queue));
            localStorage.setItem(this.seqKey, String(this.seq));
        } catch {
            // Storage full or disabled: events still flush from memory.
        }
    }

    record(type, payload = null, attemptId = null) {
        const perf = performance.now();
        const event = {
            seq: ++this.seq,
            type,
            epoch_ms: Math.round(performance.timeOrigin + perf),
            perf_ms: Math.round(perf * 1000) / 1000,
            attempt_id: attemptId,
            lsl_time: null,
            payload,
        };

        this.queue.push(event);
        this.persist();

        const code = this.markerCodes[type];

        if (code !== null && code !== undefined) {
            this.bridge?.marker(event.seq, code, type);
        }

        // Browsers throttle timers in background tabs; boundary events are sent
        // immediately so a step never ends without its record on the server.
        if (IMMEDIATE_TYPES.has(type) || this.queue.length >= 50) {
            setTimeout(() => this.flush(), 0);
        }

        return event;
    }

    /**
     * Called by the bridge when LSL confirms a marker. If the event already
     * left the queue, the timestamp is sent later as a marker_ack event.
     */
    acknowledge(seq, lslTime) {
        const queued = this.queue.find((event) => event.seq === seq);

        if (queued) {
            queued.lsl_time = lslTime;
            this.persist();
        } else {
            this.record('marker_ack', { target_seq: seq, lsl_time: lslTime });
        }
    }

    async flush({ keepalive = false } = {}) {
        if (this.flushing || !this.queue.length) {
            return;
        }

        this.flushing = true;
        const batch = this.queue.slice(0, 200);

        try {
            const response = await http.post(this.endpoint, { events: batch }, { keepalive });
            const acknowledged = new Set(response.acknowledged);
            this.queue = this.queue.filter((event) => !acknowledged.has(event.seq));
            this.persist();
        } catch {
            // Network or server error: keep the queue and retry on the next tick.
        } finally {
            this.flushing = false;
        }
    }

    get pending() {
        return this.queue.length;
    }

    destroy() {
        clearInterval(this.timer);
    }
}
