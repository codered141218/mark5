#!/usr/bin/env python3
"""Build the 1-minute ad: title card, recorded scenes (trimmed to their target length, headline chip overlaid),
end card; voice-over lines placed at each scene's start over a ducked music bed."""
import json, os, subprocess, sys, wave
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
REC = os.path.join(HERE, 'rec')
BUILD = os.path.join(HERE, 'build')
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'mark5-ad.mp4')
VOICE = os.environ.get('VOICE', 'en-us-ryan-high')
W, H, FPS = 1440, 900, 30
FONT = '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'
FONT_B = '/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf'
FONT_R = '/usr/share/fonts/opentype/inter/Inter-Medium.otf'
BG = '0x111827'
ENC = ['-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', str(FPS)]
os.makedirs(BUILD, exist_ok=True)

# scene order, headline chip, VO key, length of the title/end cards
PLAN = [
    ('A-title', None, 'A', 6.5),
    ('B-mobile', 'MOBILE ORDER TAKER', 'B', None),
    ('C-slips', 'SLIPS FOR EVERY STATION', 'C', None),
    ('D-dashboard', 'LIVE DASHBOARD', 'D', None),
    ('E-finance', 'FULL FINANCIALS', 'E', None),
    ('F-advances', 'CASH ADVANCES & SALARY DEDUCTIONS', 'F', None),
    ('G-end', None, 'G', 6.0),
]


def run(cmd):
    subprocess.run(cmd, check=True)


def duration(path):
    return float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path]).strip())


def textfile(name, text):
    p = os.path.join(BUILD, name + '.txt')
    open(p, 'w').write(text)
    return p


def drawtext(file, size, color, x, y, extra='', font=FONT, enable=None):
    s = f"drawtext=fontfile={font}:textfile={file}:expansion=none:fontsize={size}:fontcolor={color}:x={x}:y={y}:line_spacing=10{extra}"
    if enable:
        s += f":enable='{enable}'"
    return s


def card(name, lines, dur, fade=0.5):
    filters = [f"color=c={BG}:s={W}x{H}:d={dur}:r={FPS}[bg]"]
    chain = '[bg]'
    total = sum(int(sz * 1.75) for _, sz, _, _ in lines)
    y = (H - total) // 2
    for i, (text, size, color, font) in enumerate(lines):
        f = textfile(f'{name}-{i}', text)
        filters.append(f"{chain}{drawtext(f, size, color, '(w-text_w)/2', y, font=font)}[t{i}]")
        chain = f'[t{i}]'
        y += int(size * 1.75)
    filters.append(f"{chain}fade=t=in:st=0:d={fade},fade=t=out:st={dur - fade}:d={fade},format=yuv420p[v]")
    out = os.path.join(BUILD, name + '.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'nullsrc=s=16x16:d=1', '-filter_complex', ';'.join(filters), '-map', '[v]', '-t', str(dur), *ENC, out])
    return out, dur


def clip(scene, headline):
    """Trim to the target length, letterbox phone/tablet, add the headline chip, fade."""
    src, w, h, target = scene['file'], scene['w'], scene['h'], scene['target']
    vf = []
    # Playwright's recording runs a little longer than the scripted wall time: bring it back to real time first
    stretch = duration(src) / scene['dur']
    vf.append(f"setpts=PTS/{stretch:.4f}")
    if (w, h) != (W, H):
        vf.append(f"scale=-2:{H}:flags=lanczos,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:color={BG}")
    f = textfile(scene['name'] + '-chip', headline)
    vf.append(drawtext(f, 30, 'white', '48', '40', ':box=1:boxcolor=0xc2410c@0.95:boxborderw=14', font=FONT_B, enable='gte(t,0.4)'))
    vf.append(f"fade=t=in:st=0:d=0.3,fade=t=out:st={target - 0.35:.2f}:d=0.35")
    vf.append(f"fps={FPS},format=yuv420p")
    out = os.path.join(BUILD, scene['name'] + '.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-i', src, '-t', str(target), '-vf', ','.join(vf), '-an', *ENC, out])
    return out, duration(out)


def voice_track(starts, total):
    """One wav with every VO line placed at its scene start (+0.5 s)."""
    SR = 22050
    mix = np.zeros(int(SR * (total + 1)), dtype=np.float32)
    for key, start in starts.items():
        p = os.path.join(HERE, f'vo-{key}-{VOICE}.wav')
        with wave.open(p) as wv:
            assert wv.getframerate() == SR, wv.getframerate()
            a = np.frombuffer(wv.readframes(wv.getnframes()), dtype=np.int16).astype(np.float32) / 32767
        s0 = int((start + 0.5) * SR)
        mix[s0:s0 + len(a)] += a[:len(mix) - s0]
    out = os.path.join(BUILD, 'vo.wav')
    with wave.open(out, 'wb') as wv:
        wv.setnchannels(1); wv.setsampwidth(2); wv.setframerate(SR)
        wv.writeframes((np.clip(mix, -1, 1) * 32767).astype(np.int16).tobytes())
    return out


def main():
    scenes = {s['name']: s for s in json.load(open(os.path.join(REC, 'scenes.json')))}
    parts, starts, t = [], {}, 0.0
    for name, headline, vo, card_len in PLAN:
        if name == 'A-title':
            p, d = card(name, [('M5 Restaurant Suite', 76, 'white', FONT_B), ('POS · kitchen · inventory · accounting', 36, '0xfdba74', FONT),
                               ('One app. One database. Plain PHP + MySQL.', 30, '0xd1d5db', FONT_R)], card_len)
        elif name == 'G-end':
            p, d = card(name, [('M5 Restaurant Suite', 76, 'white', FONT_B), ('Plain PHP 8.1 + MySQL · no framework · installs in minutes', 32, '0xd1d5db', FONT_R),
                               ('github.com/codered141218/mark5', 34, '0xfdba74', FONT)], card_len)
        else:
            s = scenes[name]
            if s.get('error'):
                print('WARNING: scene recorded with an error:', name, s['error'][:160])
            p, d = clip(s, headline)
        starts[vo] = t
        parts.append(p)
        print(f'{name:12s} {t:5.1f}s + {d:4.1f}s')
        t += d
    lst = os.path.join(BUILD, 'concat.txt')
    open(lst, 'w').write(''.join(f"file '{p}'\n" for p in parts))
    silent = os.path.join(BUILD, 'video.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', lst, '-c', 'copy', silent])
    total = duration(silent)
    vo = voice_track(starts, total)
    music = os.path.join(HERE, 'music.wav')
    if abs(duration(music) - total) > 1.5:
        run(['python3', os.path.join(HERE, 'music.py'), f'{total:.1f}', music])
    # music ducked under the voice, then loudness-normalised to a broadcast-ish level
    af = ('[2:a]volume=0.55,aformat=sample_rates=44100[m];[1:a]aformat=sample_rates=44100,volume=1.0,asplit[v1][v2];'
          '[m][v1]sidechaincompress=threshold=0.03:ratio=8:attack=30:release=500:makeup=1[md];'
          '[md][v2]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000[a]')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-i', silent, '-i', vo, '-i', music, '-filter_complex', af, '-map', '0:v', '-map', '[a]',
         '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', OUT])
    print('wrote', OUT, f'{duration(OUT):.1f}s', f'{os.path.getsize(OUT) / 1e6:.1f} MB')


if __name__ == '__main__':
    main()
