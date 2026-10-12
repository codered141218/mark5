# Demo video

[`mark5-demo.mp4`](mark5-demo.mp4) (3 min, 1280×800) walks through the suite: sign-in and dashboard, the POS (orders board,
taking an order, table assignment, kitchen send, Senior Citizen discount with manager PIN, split payment, receipts,
X-reading and the end-of-day count), inventory and recipes, reports, cash & finance, administration, and the tablet / phone layouts.

## Re-recording it

The video is recorded from the real app running on demo data, so it can be regenerated after UI changes.
Needs PHP 8.1+, MySQL/MariaDB, Node with Playwright (`npm i -g playwright`, Chromium installed) and ffmpeg.

```bash
cp config/config.example.php config/config.php      # point it at an EMPTY database used only for the demo
php database/install.php                            # tables + sample menu
php docs/demo/seed.php                              # demo business, users and three weeks of transactions (fresh install only!)
php -S 127.0.0.1:8000 -t public public/index.php &  # the recorder expects http://127.0.0.1:8000
node docs/demo/record.js                            # records docs/demo/rec/*.webm + scenes.json (captions with timestamps)
python3 docs/demo/assemble.py docs/demo/mark5-demo.mp4   # title / end cards, captions, speed-ups, concat
```

`node docs/demo/record.js 02-pos` re-records only the named scene(s) and keeps the rest. The recorder logs in as
`admin / admin123` (back office) and `maria / cashier123` (POS); the manager PIN typed in the discount step is `2468`.

## 1-minute ad (`mark5-ad.mp4`)

A 57-second spot with voice-over and music: mobile order taker, kitchen slips with copies per station, live dashboard,
financial statements, and employee cash advances with salary deductions. Everything lives in `ad/`:

```bash
pip install piper-tts && python3 ad/voice.py /path/to/piper-voices   # vo.json -> vo-*.wav (voice files: rhasspy/piper releases)
python3 ad/music.py 60 ad/music.wav                                   # synthesized music bed (numpy, no samples)
node ad/ad-record.js                                                  # five scenes, paced to their target lengths
python3 ad/ad-assemble.py docs/demo/mark5-ad.mp4                     # cards + headline chips + ducked music + VO
```
