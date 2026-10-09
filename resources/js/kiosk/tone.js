let context;

/**
 * Short sine beep used as an auditory cue. The AudioContext is created lazily
 * after the first user gesture, as browsers require.
 */
export function playTone(frequency = 880, seconds = 0.15) {
    context ??= new (window.AudioContext || window.webkitAudioContext)();

    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const now = context.currentTime;

    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.25, now + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + seconds);
    oscillator.connect(gain).connect(context.destination);
    oscillator.start(now);
    oscillator.stop(now + seconds + 0.02);
}
