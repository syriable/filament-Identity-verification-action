# Artwork sources

The images and the explainer video in `art/` are rendered from these HTML scenes with headless Chromium (Playwright) and ffmpeg.

```bash
node art/source/render.mjs stills                    # banner, how-it-works, security, usage PNGs (2x)
node art/source/render.mjs video                     # 1920x1080 MP4 (33 s, 30 fps) + README GIF
node art/source/render.mjs preview 6.5 12.1 22       # single frames into art/preview/ for checking
```

- `video.html`: the animation. `render(t)` draws the frame at `t` seconds, so the output is deterministic.
- `stills.html`: the still images, selected with `?s=banner|how-it-works|security|usage`.
- `theme.css`: shared colors and components.

Set `CHROMIUM_PATH` to use a specific Chromium binary.
