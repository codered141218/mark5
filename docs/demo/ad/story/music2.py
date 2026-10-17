"""Music bed for the long ad: two sections (warm intro / driving body), soft pad, plucked arpeggio, light drums.
Usage: python3 music2.py <seconds> <out.wav> [drums_from_seconds]"""
import numpy as np, wave, sys
SR = 44100; BPM = 108; beat = 60 / BPM
dur = float(sys.argv[1]); out = sys.argv[2]; drums_from = float(sys.argv[3]) if len(sys.argv) > 3 else 6.0
t = np.arange(int(SR * dur)) / SR
mix = np.zeros_like(t)
note = lambda m: 440 * 2 ** ((m - 69) / 12)
def env(n, a, d, s, r):
    e = np.ones(n) * s; A = min(int(a * SR), n); D = min(int(d * SR), max(n - A, 0)); R = min(int(r * SR), n)
    e[:A] = np.linspace(0, 1, A); e[A:A + D] = np.linspace(1, s, D)
    if R > 0: e[-R:] = np.linspace(e[-R], 0, R)
    return e
# D major-ish warm progression: D - Bm - G - A, then F#m - G - D/A - A
progA = [[50, 54, 57, 62], [47, 50, 54, 57], [43, 47, 50, 55], [45, 49, 52, 57]]
progB = [[42, 45, 49, 54], [43, 47, 50, 55], [45, 50, 54, 57], [45, 49, 52, 57]]
bar = 4 * beat; chord_len = 2 * bar
i = 0; pos = 0.0
while pos < dur:
    section = int(pos // (8 * chord_len)) % 2
    chord = (progA if section == 0 else progB)[i % 4]
    s0 = int(pos * SR); n = min(int(chord_len * SR), len(t) - s0)
    if n <= 0: break
    tt = np.arange(n) / SR
    pad = np.zeros(n)
    for m in chord:
        f = note(m)
        pad += (np.sin(2 * np.pi * f * tt) + 0.45 * np.sin(2 * np.pi * 2 * f * tt + 0.3) + 0.2 * np.sin(2 * np.pi * 3 * f * tt)) * 0.11
        pad += 0.05 * np.sin(2 * np.pi * (f * 1.003) * tt)
    pad *= env(n, 0.5, 0.6, 0.8, 0.7)
    mix[s0:s0 + n] += pad * 0.55
    # arpeggio: 8ths, octave up, gets busier in section B
    step = beat / 2 if section == 0 else beat / 4
    k = 0; p2 = 0.0
    while p2 < chord_len - 0.01:
        m = chord[k % 4] + 12 + (12 if (k // 4) % 2 else 0)
        f = note(m); ln = int(step * 1.8 * SR); a0 = s0 + int(p2 * SR)
        if a0 + ln > len(t): ln = len(t) - a0
        if ln <= 0: break
        ta = np.arange(ln) / SR
        mix[a0:a0 + ln] += (np.sin(2 * np.pi * f * ta) + 0.3 * np.sin(2 * np.pi * 2 * f * ta)) * np.exp(-ta * 8) * (0.16 if section == 0 else 0.13)
        p2 += step; k += 1
    pos += chord_len; i += 1
# drums from drums_from on: kick 1&3, clap 2&4, hats 8ths; a fill-free, steady groove
for b in range(int(dur / beat)):
    tb = b * beat
    if tb < drums_from: continue
    s0 = int(tb * SR)
    if b % 2 == 0:
        ln = int(0.25 * SR); tk = np.arange(ln) / SR
        kick = np.sin(2 * np.pi * (52 + 90 * np.exp(-tk * 30)) * tk) * np.exp(-tk * 12) * 0.85
        mix[s0:s0 + ln] += kick[:len(mix) - s0]
    else:
        ln = int(0.12 * SR)
        mix[s0:s0 + ln] += (np.random.default_rng(b).standard_normal(ln) * np.exp(-np.arange(ln) / SR * 40) * 0.16)[:len(mix) - s0]
    for h in (0, 0.5):
        hs = int((tb + h * beat) * SR); ln = int(0.05 * SR)
        if hs + ln > len(mix): continue
        hat = np.diff(np.random.default_rng(1000 + b * 2 + int(h * 2)).standard_normal(ln) * np.exp(-np.arange(ln) / SR * 120) * 0.06, prepend=0)
        mix[hs:hs + ln] += hat
mix = np.tanh(mix * 1.2) * 0.8
fade = int(1.0 * SR); mix[:fade] *= np.linspace(0, 1, fade)
tail = int(3.0 * SR); mix[-tail:] *= np.linspace(1, 0, tail)
with wave.open(out, 'wb') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR); w.writeframes((mix * 32767).astype(np.int16).tobytes())
print('wrote', out, dur, 's')
