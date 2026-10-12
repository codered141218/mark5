#!/usr/bin/env python3
"""Synthesize the voice-over lines in vo.json with Piper (https://github.com/rhasspy/piper): vo-<key>-<voice>.wav
Usage: python3 voice.py /path/to/voices [en-us-ryan-high] [vo.json|vo30.json]   (voice .onnx + .onnx.json files live in that folder)"""
import json, os, subprocess, sys
here = os.path.dirname(os.path.abspath(__file__))
voices, voice = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else 'en-us-ryan-high')
lines = sys.argv[3] if len(sys.argv) > 3 else 'vo.json'   # vo.json (1-minute ad) or vo30.json (30-second spot)
for key, text in json.load(open(os.path.join(here, lines))):
    out = os.path.join(here, f'vo30-{key}.wav' if lines == 'vo30.json' else f'vo-{key}-{voice}.wav')
    subprocess.run(['python3', '-m', 'piper', '-m', os.path.join(voices, voice + '.onnx'), '-f', out, '--sentence-silence', '0.25'], input=text.encode(), check=True)
    print('wrote', out)
