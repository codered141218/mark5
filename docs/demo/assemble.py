#!/usr/bin/env python3
"""Assemble the recorded scenes (rec/scenes.json) into one captioned MP4 with title and end cards."""
import json, os, subprocess, sys, textwrap

HERE = os.path.dirname(os.path.abspath(__file__))
REC = os.path.join(HERE, 'rec')
BUILD = os.path.join(HERE, 'build')
OUT = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, 'mark5-demo.mp4')
W, H, FPS = 1440, 900, 30
FONT = '/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'
FONT_B = '/usr/share/fonts/opentype/inter/InterDisplay-Bold.otf'
FONT_R = '/usr/share/fonts/opentype/inter/Inter-Medium.otf'
BG = '0x111827'
SPEED = {'01-login': 1.3, '02-pos': 1.2, '03-inventory': 1.35, '04-reports': 1.4, '05-finance': 1.3, '06-admin': 1.3, '07-tablet': 1.25, '08-phone': 1.25}
ENC = ['-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart']
os.makedirs(BUILD, exist_ok=True)


def run(cmd):
    subprocess.run(cmd, check=True)


def duration(path):
    out = subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path])
    return float(out.strip())


def textfile(name, text):
    p = os.path.join(BUILD, name + '.txt')
    with open(p, 'w') as f:
        f.write(text)
    return p


def drawtext(file, size, color, x, y, extra='', font=FONT, enable=None):
    s = f"drawtext=fontfile={font}:textfile={file}:expansion=none:fontsize={size}:fontcolor={color}:x={x}:y={y}:line_spacing=8{extra}"
    if enable:
        s += f":enable='{enable}'"
    return s


def caption_filters(scene, speed, stretch):
    """One drawtext per caption, shown from its time until the next caption. Playwright's video runs a little
    longer than the scripted wall time, so script times are scaled by stretch (video length / script length)."""
    caps = scene['caps']
    filters = []
    for i, c in enumerate(caps):
        start = c['t'] * stretch / speed
        end = (caps[i + 1]['t'] * stretch / speed) if i + 1 < len(caps) else 9999
        text = '\n'.join(textwrap.wrap(c['text'], 92))
        f = textfile(f"{scene['name']}-{i}", text)
        filters.append(drawtext(f, 28, 'white', '(w-text_w)/2', 'h-text_h-36', ':box=1:boxcolor=0x111827@0.78:boxborderw=16', enable=f'between(t,{start:.2f},{end:.2f})'))
    return filters


def card(name, lines, dur, fade=0.5):
    """Title / end card: dark background, big heading, smaller lines."""
    filters = [f"color=c={BG}:s={W}x{H}:d={dur}:r={FPS}[bg]"]
    chain = '[bg]'
    y = 300 if len(lines) <= 3 else 230
    for i, (text, size, color, font) in enumerate(lines):
        f = textfile(f'{name}-{i}', text)
        filters.append(f"{chain}{drawtext(f, size, color, '(w-text_w)/2', y, font=font)}[t{i}]")
        chain = f'[t{i}]'
        y += int(size * 1.9)
    filters.append(f"{chain}fade=t=in:st=0:d={fade},fade=t=out:st={dur - fade}:d={fade},format=yuv420p[v]")
    out = os.path.join(BUILD, name + '.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'nullsrc=s=16x16:d=1', '-filter_complex', ';'.join(filters), '-map', '[v]', '-t', str(dur), *ENC, out])
    return out


def clip(scene):
    speed = SPEED.get(scene['name'], 1.0)
    src = scene['file']
    w, h = scene['w'], scene['h']
    vf = []
    if (w, h) != (W, H):
        # keep the aspect ratio, letterbox on the dark background
        vf.append(f"scale=-2:{H}:flags=lanczos,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:color={BG}")
    vf.append(f"setpts=PTS/{speed}")
    stretch = duration(src) / scene['dur']
    vf += caption_filters(scene, speed, stretch)
    dur = duration(src) / speed
    vf.append(f"fade=t=in:st=0:d=0.35,fade=t=out:st={max(dur - 0.45, 0):.2f}:d=0.45")
    vf.append(f"fps={FPS},format=yuv420p")
    out = os.path.join(BUILD, scene['name'] + '.mp4')
    run(['ffmpeg', '-loglevel', 'error', '-y', '-i', src, '-vf', ','.join(vf), '-an', *ENC, out])
    return out


def main():
    scenes = json.load(open(os.path.join(REC, 'scenes.json')))
    parts = []
    parts.append(card('00-title', [
        ('Mark5 Restaurant Suite', 72, 'white', FONT_B),
        ('POS · recipe-based inventory · double-entry accounting', 34, '0xfdba74', FONT),
        ('One plain PHP + MySQL app for Philippine restaurants', 28, '0xd1d5db', FONT_R),
    ], 4.5))
    for s in scenes:
        if s.get('error'):
            print('WARNING: scene recorded with an error:', s['name'], s['error'][:120])
        parts.append(clip(s))
        print('clip', s['name'], f"{duration(parts[-1]):.1f}s")
    parts.append(card('99-end', [
        ('Every sale, delivery, spoilage and payout', 40, 'white', FONT_B),
        ('is posted to the books automatically.', 40, 'white', FONT_B),
        (' ', 20, 'white', FONT_R),
        ('Plain PHP 8.1 + MySQL / MariaDB — no framework, no build step', 28, '0xd1d5db', FONT_R),
        ('Copy the folder, open it in the browser, run the installer.', 28, '0xd1d5db', FONT_R),
        ('github.com/codered141218/mark5', 30, '0xfdba74', FONT),
    ], 6))
    lst = os.path.join(BUILD, 'concat.txt')
    with open(lst, 'w') as f:
        for p in parts:
            f.write(f"file '{p}'\n")
    run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', lst, '-c', 'copy', '-movflags', '+faststart', OUT])
    print('wrote', OUT, f"{duration(OUT):.1f}s", f"{os.path.getsize(OUT) / 1e6:.1f} MB")


if __name__ == '__main__':
    main()
