// Renders the explainer video and still images with headless Chromium.
//
//   node art/source/render.mjs preview 2.5 7 12.2   # PNG frames at the given seconds
//   node art/source/render.mjs video                # art/identity-verification-action.mp4
//   node art/source/render.mjs stills               # art/*.png
//
// Requires Playwright (Chromium) and ffmpeg on the PATH.

import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
let playwright;
try {
    playwright = require('playwright');
} catch {
    playwright = require(join(execFileSync('npm', ['root', '-g']).toString().trim(), 'playwright'));
}

const here = dirname(fileURLToPath(import.meta.url));
const out = resolve(here, '..');
const [mode = 'video', ...rest] = process.argv.slice(2);
const FPS = 30;

const launchOptions = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};
const browser = await playwright.chromium.launch(launchOptions);

async function open(file, width, height, query = '', scale = 1) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: scale });
    await page.goto(pathToFileURL(join(here, file)).href + query);
    await page.evaluate(() => document.fonts.ready);
    return page;
}

if (mode === 'preview') {
    const page = await open('video.html', 1920, 1080);
    const dir = join(out, 'preview');
    mkdirSync(dir, { recursive: true });
    for (const t of rest.map(Number)) {
        await page.evaluate((time) => window.render(time), t);
        await page.screenshot({ path: join(dir, `t-${t}.png`) });
        console.log(join(dir, `t-${t}.png`));
    }
}

if (mode === 'video') {
    const page = await open('video.html', 1920, 1080);
    const duration = await page.evaluate(() => window.DURATION);
    const frames = mkdtempSync(join(tmpdir(), 'iva-frames-'));
    const total = Math.round(duration * FPS);
    for (let i = 0; i < total; i++) {
        await page.evaluate((time) => window.render(time), i / FPS);
        await page.screenshot({ path: join(frames, `${String(i).padStart(5, '0')}.png`) });
        if (i % 90 === 0) console.log(`frame ${i}/${total}`);
    }
    const mp4 = join(out, 'identity-verification-action.mp4');
    execFileSync('ffmpeg', [
        '-y', '-loglevel', 'error', '-framerate', String(FPS), '-i', join(frames, '%05d.png'),
        '-c:v', 'libx264', '-preset', 'slow', '-crf', '20', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', mp4,
    ]);
    execFileSync('ffmpeg', [
        '-y', '-loglevel', 'error', '-i', mp4,
        '-vf', 'fps=12,scale=800:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=bayer:bayer_scale=5:diff_mode=rectangle',
        join(out, 'identity-verification-action.gif'),
    ]);
    rmSync(frames, { recursive: true, force: true });
    console.log(mp4);
}

if (mode === 'stills') {
    const stills = [
        ['banner', 1280, 640],
        ['how-it-works', 1600, 900],
        ['security', 1600, 900],
        ['usage', 1600, 900],
    ];
    for (const [name, width, height] of stills) {
        const page = await open('stills.html', width, height, `?s=${name}`, 2);
        await page.screenshot({ path: join(out, `${name}.png`) });
        await page.close();
        console.log(join(out, `${name}.png`));
    }
}

await browser.close();
