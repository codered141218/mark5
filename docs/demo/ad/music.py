"""Synthesize a short upbeat music bed (no samples needed): soft pad chords, plucked arpeggio, kick/hat pattern."""
import numpy as np, wave, sys
SR = 44100
BPM = 112
beat = 60 / BPM
dur = float(sys.argv[1]) if len(sys.argv) > 1 else 62
out = sys.argv[2] if len(sys.argv) > 2 else 'music.wav'
t = np.arange(int(SR * dur)) / SR
mix = np.zeros_like(t)

def note(f):  # hz from midi
    return 440 * 2 ** ((f - 69) / 12)

def env(n, a, d, s, r, length):
    e = np.ones(n) * s
    A = int(a * SR); D = int(d * SR); R = int(r * SR)
    e[:A] = np.linspace(0, 1, A)
    e[A:A + D] = np.linspace(1, s, D)
    if R < n: e[-R:] = np.linspace(e[-R], 0, R)
    return e

# chord progression (A major-ish, uplifting): A - F#m - D - E  (midi roots 57, 54, 50, 52), each 2 bars
prog = [[57, 61, 64, 68], [54, 57, 61, 64], [50, 54, 57, 61], [52, 56, 59, 64]]
bar = 4 * beat
chord_len = 2 * bar
n_chords = int(np.ceil(dur / chord_len))
for i in range(n_chords):
    chord = prog[i % 4]
    s0 = int(i * chord_len * SR); n = int(chord_len * SR)
    if s0 >= len(t): break
    n = min(n, len(t) - s0)
    tt = np.arange(n) / SR
    pad = np.zeros(n)
    for m in chord:
        f = note(m)
        pad += (np.sin(2 * np.pi * f * tt) + 0.5 * np.sin(2 * np.pi * 2 * f * tt + 0.3) + 0.25 * np.sin(2 * np.pi * 3 * f * tt)) * 0.12
        pad += 0.06 * np.sin(2 * np.pi * (f * 1.004) * tt)  # slight detune for warmth
    pad *= env(n, 0.4, 0.5, 0.8, 0.6, chord_len)
    mix[s0:s0 + n] += pad * 0.5
    # arpeggio: 8th notes through the chord, two octaves up
    step = beat / 2
    k = 0
    pos = 0.0
    while pos < chord_len - 0.01:
        m = chord[k % 4] + 12 + (12 if (k // 4) % 2 else 0)
        f = note(m)
        ln = int(step * 1.6 * SR)
        a0 = s0 + int(pos * SR)
        if a0 + ln > len(t): ln = len(t) - a0
        if ln <= 0: break
        ta = np.arange(ln) / SR
        pluck = (np.sin(2 * np.pi * f * ta) + 0.3 * np.sin(2 * np.pi * 2 * f * ta)) * np.exp(-ta * 9) * 0.22
        mix[a0:a0 + ln] += pluck
        pos += step; k += 1

# drums: kick on 1 & 3, hats on 8ths, soft clap on 2 & 4 (start after the first bar, fade-in of groove)
n_beats = int(dur / beat)
for b in range(n_beats):
    s0 = int(b * beat * SR)
    if b % 2 == 0:  # kick
        ln = int(0.25 * SR); tk = np.arange(ln) / SR
        kick = np.sin(2 * np.pi * (55 + 90 * np.exp(-tk * 30)) * tk) * np.exp(-tk * 12) * 0.9
        mix[s0:s0 + ln] += kick[:len(mix) - s0]
    else:  # clap/snare-ish noise burst
        ln = int(0.12 * SR)
        noise = np.random.default_rng(b).standard_normal(ln) * np.exp(-np.arange(ln) / SR * 40) * 0.18
        mix[s0:s0 + ln] += noise[:len(mix) - s0]
    for h in (0, 0.5):  # hats
        hs = int((b + h) * beat * SR); ln = int(0.05 * SR)
        if hs + ln > len(mix): continue
        hat = np.random.default_rng(1000 + b * 2 + int(h * 2)).standard_normal(ln) * np.exp(-np.arange(ln) / SR * 120) * 0.07
        # crude high-pass: difference
        hat = np.diff(hat, prepend=0)
        mix[hs:hs + ln] += hat

# gentle master: soft clip, fade in/out
mix = np.tanh(mix * 1.2) * 0.8
fade = int(1.0 * SR)
mix[:fade] *= np.linspace(0, 1, fade)
mix[-int(2.5 * SR):] *= np.linspace(1, 0, int(2.5 * SR))
pcm = (mix * 32767).astype(np.int16)
with wave.open(out, 'wb') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR); w.writeframes(pcm.tobytes())
print('wrote', out, dur, 's')
