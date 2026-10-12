#!/usr/bin/env python3
"""Synthesize the voice-over lines in vo.json with Piper (https://github.com/rhasspy/piper): vo-<key>-<voice>.wav
Usage: python3 voice.py /path/to/voices [en-us-ryan-high]   (voice .onnx + .onnx.json files live in that folder)"""
import json, os, subprocess, sys
here = os.path.dirname(os.path.abspath(__file__))
voices, voice = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else 'en-us-ryan-high')
for key, text in json.load(open(os.path.join(here, 'vo.json'))):
    out = os.path.join(here, f'vo-{key}-{voice}.wav')
    subprocess.run(['python3', '-m', 'piper', '-m', os.path.join(voices, voice + '.onnx'), '-f', out, '--sentence-silence', '0.25'], input=text.encode(), check=True)
    print('wrote', out)
