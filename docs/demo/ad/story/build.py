#!/usr/bin/env python3
"""Fuse the final script (script.json), the recorded scenes (rec2/scenes.json) and the voices into timeline.json.

mapping.json is an ordered list, one entry per script scene (by id):
  {"scene": "s1", "use": "card:intro"}                               -> title card (fields from script)
  {"scene": "s2", "use": "card:pov", "kicker": "...", "name": "...", "role": "...", "when": "...", "problem": "..."}
  {"scene": "s3", "use": "clip:w1-order", "char_at": "mark:sent"|3.2, "seconds": 14}   -> recorded clip
Steps:  python3 build.py lines   -> voices/lines.json (then run voice2.py voices/lines.json voices)
        python3 build.py timeline -> timeline.json (then run assemble2.py timeline.json)"""
import json, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
script = json.load(open(os.path.join(HERE, 'script.json')))
mapping = json.load(open(os.path.join(HERE, 'mapping.json')))
scenes = {s['id']: s for s in script['scenes']}
VOICE_OF = {'ramon': 'ramon', 'maria': 'maria', 'nena': 'nena', 'mara': 'mara', 'juan': 'juan', 'grace': 'maria'}


def voice_for(who):
    w = (who or '').lower()
    for k, v in VOICE_OF.items():
        if k in w:
            return v
    return 'maria'


def lines():
    out = []
    for m in mapping:
        if m.get('line'):
            out.append({'id': f"k-{m['id']}", 'voice': voice_for(m['line']['who']), 'text': m['line']['text'].strip(), 'who': m['line']['who']})
        if not m.get('scene'):
            continue
        sc = scenes[m['scene']]
        if sc.get('narrator', '').strip():
            out.append({'id': f"n-{sc['id']}", 'voice': 'narrator', 'text': sc['narrator'].strip()})
        for i, c in enumerate(sc.get('character_lines') or []):
            out.append({'id': f"c-{sc['id']}-{i}", 'voice': voice_for(c['who']), 'text': c['line'].strip(), 'who': c['who']})
    os.makedirs(os.path.join(HERE, 'voices'), exist_ok=True)
    json.dump(out, open(os.path.join(HERE, 'voices', 'lines.json'), 'w'), indent=1)
    print(len(out), 'lines ->', os.path.join(HERE, 'voices', 'lines.json'))


def timeline():
    rec = {s['name']: s for s in json.load(open(os.path.join(HERE, 'rec2', 'scenes.json')))}
    voiced = {l['id']: l for l in json.load(open(os.path.join(HERE, 'voices', 'lines.json')))}
    items, cards = [], []
    for m in mapping:
        sc = scenes[m['scene']] if m.get('scene') else {'id': m['id'], 'seconds': m.get('seconds', 4), 'caption': m.get('caption', '')}
        kind, _, ref = m['use'].partition(':')
        vo = []
        n = voiced.get(f"n-{sc['id']}")
        t_end = 0.4
        if m.get('line'):
            v = voiced[f"k-{m['id']}"]
            vo.append({'file': os.path.join(HERE, 'voices', v['id'] + '.wav'), 'at': 0.5, 'seconds': v['seconds'], 'text': v['text'], 'who': m['line']['who']})
            t_end = 0.5 + v['seconds']
        if n:
            vo.append({'file': os.path.join(HERE, 'voices', n['id'] + '.wav'), 'at': 0.3, 'seconds': n['seconds'], 'text': n['text'], 'who': None})
            t_end = 0.3 + n['seconds']
        for i, c in enumerate(sc.get('character_lines') or []):
            v = voiced[f"c-{sc['id']}-{i}"]
            at = m.get('char_at')
            speed = float(m.get('speed', 1.0))
            if isinstance(at, str) and at.startswith('mark:'):
                at = rec[ref]['marks'].get(at[5:], None) if kind == 'clip' else None
                at = (at / speed + 0.2) if at is not None else None
            if at is None:
                at = t_end + 0.25
            # never talk over the narrator: push the character line after the narration if it would overlap
            if n and at < t_end and at + v['seconds'] > 0.3:
                at = t_end + 0.25
            # and never past the end of the footage
            if kind == 'clip':
                avail = rec[ref]['dur'] / speed - 0.4
                if at + v['seconds'] > avail:
                    at = max(t_end + 0.25, avail - v['seconds'])
            vo.append({'file': os.path.join(HERE, 'voices', v['id'] + '.wav'), 'at': round(at, 2), 'seconds': v['seconds'], 'text': v['text'], 'who': f"{c['who']}"})
            t_end = max(t_end, at + v['seconds'])
        need = round(max(min(float(sc.get('seconds', 0)), t_end + 1.5), t_end + 0.6, 3.5), 1)
        if kind == 'clip' and need > rec[ref]['dur'] / float(m.get('speed', 1.0)) - 0.2:
            print(f"  ! {ref}: voice needs {need:.1f}s, footage {rec[ref]['dur'] / float(m.get('speed', 1.0)):.1f}s")
        if m.get('seconds'):
            need = max(need, float(m['seconds']))
        if kind == 'card':
            if ref == 'intro':
                card = {'file': f"card-{sc['id']}", 'kind': 'title', 'title': script.get('title', 'M5 Restaurant Suite'), 'sub': script.get('tagline', ''), 'small': m.get('small', 'POS · inventory · accounting · payroll')}
            elif ref == 'outro':
                card = {'file': f"card-{sc['id']}", 'kind': 'outro', 'title': 'M5 Restaurant Suite', 'sub': m.get('sub', 'Plain PHP + MySQL · installs in minutes'), 'tags': ['POS', 'Inventory', 'Accounting', 'Payroll'], 'url': 'github.com/codered141218/mark5'}
            elif ref == 'quote':
                card = {'file': f"card-{sc['id']}", 'kind': 'quote', 'quote': m['quote'], 'who': m['who']}
            else:
                card = {'file': f"card-{sc['id']}", 'kind': 'pov', 'kicker': m.get('kicker', ''), 'name': m.get('name', ''), 'role': m.get('role', ''), 'when': m.get('when', ''), 'problem': m.get('problem', sc.get('problem', ''))}
            cards.append(card)
            items.append({'kind': 'card', 'scene': sc['id'], 'file': os.path.join(HERE, 'cards', card['file'] + '.png'), 'seconds': need, 'vo': vo})
        else:
            r = rec[ref]
            bezel = os.path.join(HERE, 'cards', 'frame-phone.png') if r['w'] < r['h'] else (os.path.join(HERE, 'cards', 'frame-tablet.png') if r['w'] < 1440 else None)
            it = {'kind': 'clip', 'scene': ref, 'file': r['file'], 'scripted': r['dur'], 'seconds': need, 'w': r['w'], 'h': r['h'], 'caption': sc.get('caption', '').upper(), 'vo': vo, 'speed': float(m.get('speed', 1.0))}
            if bezel:
                it['bezel'] = bezel
            items.append(it)
    json.dump(cards, open(os.path.join(HERE, 'cards.json'), 'w'), indent=1)
    subprocess.run(['node', os.path.join(HERE, 'cards.js'), os.path.join(HERE, 'cards.json'), os.path.join(HERE, 'cards')], check=True, env={**os.environ, 'NODE_PATH': '/opt/node22/lib/node_modules'}, capture_output=True)
    total = sum(i['seconds'] for i in items) - 0.5 * (len(items) - 1)
    json.dump({'out': 'm5-story.mp4', 'build': 'build2', 'music': {'drums_from': 5}, 'items': items}, open(os.path.join(HERE, 'timeline.json'), 'w'), indent=1)
    for i in items:
        print(f"{i['scene']:14s} {i['seconds']:5.1f}s  vo={len(i['vo'])}")
    print(f'total ~{total:.1f}s  ->', os.path.join(HERE, 'timeline.json'))


if __name__ == '__main__':
    {'lines': lines, 'timeline': timeline}[sys.argv[1]]()
