import { reactive } from 'vue';

/**
 * WebSocket client for bridge/lsl_bridge.py running on the lab computer. The
 * bridge pushes each marker into an LSL outlet and returns the LSL timestamp,
 * and answers periodic clock-sync pings so browser time can be mapped to the
 * LSL clock used by LabRecorder.
 */
export class LslBridge {
    constructor(url, { onAck, onSync } = {}) {
        this.url = url;
        this.onAck = onAck;
        this.onSync = onSync;
        this.syncId = 0;
        this.status = reactive({ connected: false, outlet: null, streams: [], offset_s: null, rtt_ms: null });
        this.closed = false;
        this.connect();
        this.syncTimer = setInterval(() => this.sync(), 15000);
    }

    connect() {
        if (this.closed || !this.url) {
            return;
        }

        try {
            this.socket = new WebSocket(this.url);
        } catch {
            this.scheduleReconnect();

            return;
        }

        this.socket.onopen = () => {
            this.status.connected = true;
            this.sync();
        };

        this.socket.onclose = () => {
            this.status.connected = false;
            this.scheduleReconnect();
        };

        this.socket.onerror = () => this.socket.close();

        this.socket.onmessage = (message) => {
            let data;

            try {
                data = JSON.parse(message.data);
            } catch {
                return;
            }

            if (data.type === 'hello') {
                this.status.outlet = data.outlet;
            } else if (data.type === 'ack') {
                this.onAck?.(data.seq, data.lsl_time);
            } else if (data.type === 'streams') {
                this.status.streams = data.streams;
            } else if (data.type === 'sync') {
                const t1 = performance.now();
                const rtt = t1 - data.t0;
                const midpoint = (data.t0 + t1) / 2;

                this.status.rtt_ms = rtt;
                this.status.offset_s = data.lsl - midpoint / 1000;
                this.onSync?.({ t0: data.t0, t1, lsl: data.lsl, rtt_ms: rtt, offset_s: this.status.offset_s });
            }
        };
    }

    scheduleReconnect() {
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.connect(), 3000);
    }

    send(payload) {
        if (this.socket?.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify(payload));

            return true;
        }

        return false;
    }

    marker(seq, code, label) {
        return this.send({ type: 'marker', seq, code, label });
    }

    sync() {
        this.send({ type: 'sync', id: ++this.syncId, t0: performance.now() });
    }

    snapshot() {
        return {
            connected: this.status.connected,
            outlet: this.status.outlet,
            offset_s: this.status.offset_s,
            rtt_ms: this.status.rtt_ms,
            streams: this.status.streams.slice(0, 20),
        };
    }

    close() {
        this.closed = true;
        clearInterval(this.syncTimer);
        clearTimeout(this.reconnectTimer);
        this.socket?.close();
    }
}
