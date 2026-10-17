#!/usr/bin/env python3
"""Assemble the long ad from a timeline JSON:
{
  "out": "m5-story.mp4",
  "music": {"file": "music.wav", "drums_from": 6},
  "items": [
    {"kind": "card", "file": "cards/intro.png", "seconds": 5.0, "vo": [{"file": "v/n0.wav", "at": 0.6, "text": "...", "who": null}]},
    {"kind": "clip", "scene": "w1", "file": "rec/w1.webm", "scripted": 12.4, "seconds": 12.0, "w": 390, "h": 844,
     "caption": "ORDERS FROM THE FLOOR", "vo": [{"file": "v/n1.wav", "at": 0.4, "text": "...", "who": null}, {"file": "v/r1.wav", "at": 6.2, "text": "...", "who": "Ramon · waiter"}]}
  ]
}
Segments are crossfaded (xfade); every VO is placed on the compressed timeline; music is ducked under speech."""
import json, os, subprocess, sys, textwrap, wave
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
W, H, FPS, XF = 1440, 900, 30, 0.8
BG = '0x0f172a'
FONT = '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'
FONT_B = '/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf'
FONT_I = next((f for f in ['/usr/share/fonts/opentype/inter/Inter-SemiBoldItalic.otf', '/usr/share/fonts/opentype/inter/Inter-MediumItalic.otf', '/usr/share/fonts/opentype/inter/Inter-Italic.otf', '/usr/share/fonts/opentype/inter/Inter-BoldItalic.otf'] if os.path.exists(f)), FONT)
ENC = ['-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', str(FPS)]


def run(cmd):
    subprocess.run(cmd, check=True)


def duration(p):
    return float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', p]).strip())


def textfile(build, name, text):
    p = os.path.join(build, name + '.txt')
    open(p, 'w').write(text)
    return p


def dt(file, size, color, x, y, extra='', font=FONT, enable=None):
    s = f"drawtext=fontfile={font}:textfile={file}:expansion=none:fontsize={size}:fontcolor={color}:x={x}:y={y}:line_spacing=9{extra}"
    return s + (f":enable='{enable}'" if enable else '')


def subtitle_filters(build, name, vos, seconds, card=False):
    """Narrator lines -> bottom subtitles split by sentence over the line's duration; character lines -> italic chip with the name."""
    out = []
    k = 0
    for v in vos:
        text = (v.get('text') or '').strip()
        if not text:
            continue
        start, dur = v['at'], v.get('seconds', 3.0)
        who = v.get('who')
        if who:
            f = textfile(build, f'{name}-sub{k}', '\n'.join(textwrap.wrap('“' + text + '”', 64)) + f'\n— {who}')
            out.append(dt(f, 30, 'white', '(w-text_w)/2', 'h-text_h-70' if card else 'h-text_h-150', ':box=1:boxcolor=0xc2410c@0.88:boxborderw=18', font=FONT_I, enable=f'between(t,{start:.2f},{min(start + dur + 0.4, seconds - 0.2):.2f})'))
            k += 1
            continue
        # split narration into sentence chunks, each shown for its share of the audio
        import re
        chunks = [c.strip() for c in re.split(r'(?<=[.!?])\s+', text) if c.strip()]
        words = sum(len(c.split()) for c in chunks) or 1
        t = start
        for c in chunks:
            share = dur * len(c.split()) / words
            f = textfile(build, f'{name}-sub{k}', '\n'.join(textwrap.wrap(c, 72)))
            last = c is chunks[-1]
            end = min(t + share + (0.3 if last else 0.0), seconds - 0.1)   # chunks never overlap; only the last one lingers
            out.append(dt(f, 28, 'white', '(w-text_w)/2', 'h-text_h-48', ':box=1:boxcolor=0x0f172a@0.80:boxborderw=14', enable=f'between(t,{t:.2f},{end:.2f})'))
            t += share
            k += 1
    return out


def card_segment(build, i, it):
    secs = it['seconds']
    vf = [f"scale={W * 2}:{H * 2}:flags=lanczos", f"zoompan=z='min(zoom+0.00035,1.07)':d={int(secs * FPS) + 5}:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s={W}x{H}:fps={FPS}"]
    vf += subtitle_filters(build, f'seg{i}', it.get('vo', []), secs, card=True)
    vf.append('format=yuv420p')
    out = os.path.join(build, f'seg{i:02d}.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-loop', '1', '-t', str(secs), '-i', it['file'], '-vf', ','.join(vf), '-t', str(secs), '-an', *ENC, out])
    return out


def clip_segment(build, i, it):
    src = it['file']
    stretch = (duration(src) / it['scripted'] if it.get('scripted') else 1.0) * float(it.get('speed', 1.0))
    avail = duration(src) / stretch - 0.15
    if it['seconds'] > avail:
        print(f"WARNING {it.get('scene')}: slot {it['seconds']}s but only {avail:.1f}s of footage; shortening")
        it['seconds'] = round(avail, 1)
    secs = it['seconds']
    w, h = it['w'], it['h']
    vf = [f"setpts=PTS/{stretch:.4f}"]
    if (w, h) == (W, H):
        # slow push-in on desktop footage
        vf.append(f"scale=w='trunc(iw*(1+0.045*t/{secs})/2)*2':h='trunc(ih*(1+0.045*t/{secs})/2)*2':eval=frame:flags=bicubic,crop={W}:{H}")
    else:
        # phone / tablet on the dark backdrop with a bezel
        vh = 820 if w < h else 760
        vf.append(f"scale=-2:{vh}:flags=lanczos,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:color={BG}")
        bezel = it.get('bezel')
        if bezel:
            vf = [f"setpts=PTS/{stretch:.4f}", f"scale=-2:{vh}:flags=lanczos"]
    logo = os.path.join(HERE, 'cards', 'logo.png')
    if it.get('caption'):
        f = textfile(build, f'seg{i}-chip', it['caption'])
        chip_y = '14' if (w, h) != (W, H) and w > h else '40'   # tablet footage starts lower, keep the chip off its top bar
        vf.append(dt(f, 30, 'white', '48', chip_y, ':box=1:boxcolor=0xc2410c@0.95:boxborderw=14', font=FONT_B, enable='gte(t,0.3)'))
    vf += subtitle_filters(build, f'seg{i}', it.get('vo', []), secs)
    vf.append(f"fps={FPS},format=yuv420p")
    out = os.path.join(build, f'seg{i:02d}.mp4')
    if (w, h) == (W, H) and os.path.exists(logo):
        # desktop footage: small M5 logo bug top-right (the phone / tablet backdrops already carry it)
        graph = f"[in]{','.join(vf)}[tmp];movie={logo}[lg];[tmp][lg]overlay=W-w-40:28[out]"
        run(['ffmpeg', '-loglevel', 'error', '-y', '-i', src, '-vf', graph, '-t', str(secs), '-an', *ENC, out])
        return out
    if (w, h) != (W, H) and it.get('bezel'):
        # overlay the scaled phone video on the bezel background image
        vh = 820 if w < h else 760
        bug = f";movie={logo}[lg];[v0][lg]overlay=W-w-40:28[v]" if (w > h and os.path.exists(logo)) else ""
        fc = f"[1:v]setpts=PTS/{stretch:.4f},scale=-2:{vh}:flags=lanczos[ph];[0:v][ph]overlay=(W-w)/2:(H-h)/2:shortest=1[base];[base]" + ','.join(vf[2:]) + ("[v0]" + bug if bug else "[v]")
        run(['ffmpeg', '-loglevel', 'error', '-y', '-loop', '1', '-i', it['bezel'], '-i', src, '-filter_complex', fc, '-map', '[v]', '-t', str(secs), '-an', *ENC, out])
    else:
        run(['ffmpeg', '-loglevel', 'error', '-y', '-i', src, '-vf', ','.join(vf), '-t', str(secs), '-an', *ENC, out])
    return out


def main():
    tl = json.load(open(sys.argv[1]))
    build = os.path.join(HERE, tl.get('build', 'build2'))
    os.makedirs(build, exist_ok=True)
    segs, starts, t = [], [], 0.0
    for i, it in enumerate(tl['items']):
        seg = card_segment(build, i, it) if it['kind'] == 'card' else clip_segment(build, i, it)
        segs.append(seg)
        starts.append(t)
        t += it['seconds'] - (XF if i < len(tl['items']) - 1 else 0)
        print(f"seg{i:02d} {it.get('scene', it['kind']):14s} start {starts[-1]:6.1f}s  len {it['seconds']:5.1f}s")
    total = t
    # ---- video: xfade chain
    inputs = []
    for s in segs:
        inputs += ['-i', s]
    fc, prev, off = [], '[0:v]', 0.0
    for i in range(1, len(segs)):
        off += tl['items'][i - 1]['seconds'] - XF
        out = f'[v{i}]' if i < len(segs) - 1 else '[v]'
        fc.append(f"{prev}[{i}:v]xfade=transition=fade:duration={XF}:offset={off:.3f}{out}")
        prev = out
    silent = os.path.join(build, 'video.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', *inputs, '-filter_complex', ';'.join(fc), '-map', '[v]', *ENC, '-movflags', '+faststart', silent])
    vdur = duration(silent)
    # ---- voice track
    SR = 22050
    mix = np.zeros(int(SR * (vdur + 1)), dtype=np.float32)
    for i, it in enumerate(tl['items']):
        for v in it.get('vo', []):
            with wave.open(v['file']) as wv:
                assert wv.getframerate() == SR, (v['file'], wv.getframerate())
                a = np.frombuffer(wv.readframes(wv.getnframes()), dtype=np.int16).astype(np.float32) / 32767
            s0 = int((starts[i] + v['at']) * SR)
            gain = 0.95 if v.get('who') else 1.0
            mix[s0:s0 + len(a)] += a[:len(mix) - s0] * gain
    vo = os.path.join(build, 'vo.wav')
    with wave.open(vo, 'wb') as wv:
        wv.setnchannels(1); wv.setsampwidth(2); wv.setframerate(SR)
        wv.writeframes((np.clip(mix, -1, 1) * 32767).astype(np.int16).tobytes())
    # ---- music
    music = os.path.join(build, 'music.wav')
    run(['python3', os.path.join(HERE, 'music2.py'), f'{vdur:.1f}', music, str(tl.get('music', {}).get('drums_from', 6))])
    af = ('[2:a]volume=0.5,aformat=sample_rates=44100[m];[1:a]aformat=sample_rates=44100,asplit[v1][v2];'
          '[m][v1]sidechaincompress=threshold=0.02:ratio=9:attack=25:release=600:makeup=1[md];'
          '[md][v2]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000[a]')
    out = os.path.join(HERE, tl['out'])
    run(['ffmpeg', '-loglevel', 'error', '-y', '-i', silent, '-i', vo, '-i', music, '-filter_complex', af, '-map', '0:v', '-map', '[a]',
         '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart', out])
    print('wrote', out, f'{duration(out):.1f}s', f'{os.path.getsize(out) / 1e6:.1f} MB')
    json.dump({'starts': starts, 'total': total}, open(os.path.join(build, 'timeline-out.json'), 'w'))


if __name__ == '__main__':
    main()
