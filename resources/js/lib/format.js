const dateTime = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium', timeStyle: 'short' });
const dateOnly = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'medium' });
const timeOnly = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

export function formatDateTime(value) {
    return value ? dateTime.format(new Date(value)) : '—';
}

export function formatDate(value) {
    return value ? dateOnly.format(new Date(value)) : '—';
}

export function formatTime(value) {
    return value ? timeOnly.format(new Date(value)) : '—';
}

export function formatDuration(totalSeconds) {
    if (totalSeconds === null || totalSeconds === undefined) {
        return '—';
    }

    const seconds = Math.max(0, Math.round(totalSeconds));
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return `${m}:${String(s).padStart(2, '0')}`;
}

export function formatPercent(ratio, digits = 1) {
    if (ratio === null || ratio === undefined) {
        return '—';
    }

    return `%${(ratio * 100).toLocaleString('tr-TR', { maximumFractionDigits: digits, minimumFractionDigits: digits })}`;
}

export function formatNumber(value, digits = 2) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString('tr-TR', { maximumFractionDigits: digits });
}

export function formatBytes(bytes) {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

    return `${(bytes / 1024 ** exponent).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} ${units[exponent]}`;
}

export const conditionStyles = {
    direct_ai: 'bg-amber-50 text-cond-direct ring-1 ring-amber-200',
    socratic_ai: 'bg-indigo-50 text-cond-socratic ring-1 ring-indigo-200',
    standard_resource: 'bg-lime-50 text-cond-resource ring-1 ring-lime-200',
};

export const sessionStatusStyles = {
    scheduled: 'bg-stone-100 text-stone-700',
    preparing: 'bg-sky-50 text-sky-800',
    running: 'bg-emerald-50 text-emerald-800',
    completed: 'bg-stone-800 text-white',
    aborted: 'bg-red-50 text-red-800',
};
