"""Synthesize narrator + character lines for the long ad with Piper.
Usage: python3 voice2.py lines.json out-dir   (lines.json: [{id, voice: 'narrator'|'ramon'|'maria'|'nena'|'mara', text}])
Narrator = ryan-high. Characters: maria/nena/mara = lessac-medium (female); ramon = ryan-high pitched up a little so he is not the narrator."""
import json, os, subprocess, sys
V = os.environ.get('PIPER_VOICES', os.path.expanduser('~/piper-voices'))
VOICES = {'narrator': ('en-us-ryan-high', 0.86, None), 'ramon': ('en-us-ryan-high', 0.86, 1.07), 'juan': ('en-us-ryan-high', 0.86, 0.95),
          'maria': ('en-us-lessac-medium', 0.9, 1.04), 'nena': ('en-us-lessac-medium', 0.98, 0.96), 'mara': ('en-us-lessac-medium', 0.88, 1.0)}
lines = json.load(open(sys.argv[1])); out = sys.argv[2]; os.makedirs(out, exist_ok=True)
import wave
SR = 22050
for l in lines:
    model, scale, pitch = VOICES[l['voice']]
    raw = os.path.join(out, f"{l['id']}.raw.wav"); fin = os.path.join(out, f"{l['id']}.wav")
    subprocess.run(['python3', '-m', 'piper', '-m', f'{V}/{model}.onnx', '-f', raw, '--length-scale', str(scale), '--sentence-silence', '0.18'], input=l['text'].encode(), check=True, capture_output=True)
    with wave.open(raw) as w: rate = w.getframerate()
    # every line ends up mono 22050 Hz; a pitch shift = retag the rate, resample, then restore the tempo
    af = f'asetrate={int(rate * pitch)},aresample={SR},atempo={1 / pitch:.4f}' if pitch else f'aresample={SR}'
    subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', raw, '-af', af, '-ac', '1', '-ar', str(SR), fin], check=True)
    os.remove(raw)
    with wave.open(fin) as w: d = w.getnframes() / w.getframerate()
    l['seconds'] = round(d, 2)
    print(l['id'], l['voice'], f"{d:.1f}s", len(l['text'].split()), 'w')
json.dump(lines, open(os.path.join(out, 'lines.json'), 'w'), indent=1)
