# Top Mountain Resort: botanical contemporary redesign

A static site (HTML, CSS, vanilla JS), the same stack as the current
`top-mountain` site, so it deploys to GitHub Pages as-is. Motion uses
GSAP + ScrollTrigger from cdnjs, as before.

## Run locally

Double-click `start-site.bat`, or run this from anywhere:

```bash
python D:/Projects/top-mountain-redesign/serve.py
```

It always serves this folder, opens the browser at http://localhost:5175
(or the next free port if 5175 is busy, as printed in the window), and sends
the right file types for `.webp` and `.mp4`. Press Ctrl+C to stop.

A plain `python -m http.server` also works, but only when started *inside*
this folder. Started anywhere else, it serves the wrong files.

## How it's organised

| File | What it holds |
| --- | --- |
| `index.html` | Page structure and editorial copy for every section |
| `css/style.css` | Design tokens (colours, type scale, spacing) and all layouts |
| `js/data/content.js` | Stays, prices, experiences, dining, gallery, nearby places, contacts, reviews |
| `js/data/media.js` | **Generated** media manifest. Don't edit by hand |
| `js/media.js` | Turns a media key into a responsive `<picture>` or a viewport-aware `<video>` |
| `js/render.js` | Builds the data-driven sections from `content.js` |
| `js/main.js` | Navigation, menu, booking enquiry, lightbox, rail, and all animation |
| `tools/media.config.json` | Every photo and video the site uses: source file, alt text, focal point |
| `tools/build_media.py` | Builds optimised images and videos plus the manifest |

No page or script refers to a media file path directly. Everything goes through
a key defined in `tools/media.config.json`.

## Changing photos or videos

1. Put the original in `D:/Projects/New folder/Resourses` (or the old site's
   `assets` folder). Both locations are listed under `sources` in the config.
2. Add or edit an entry in `tools/media.config.json`: a key, `src` (or `frame` +
   `time` to use a still from a video), `alt`, and optionally `pos` (focal point).
3. Run the build. It needs Pillow, plus ffmpeg for videos and video stills
   (on PATH, via `--ffmpeg`, or `pip install imageio-ffmpeg`):

```bash
python tools/build_media.py
```

Only missing or out-of-date files are rebuilt. Add `--force` to rebuild everything.

4. To use the new key, reference it in `content.js` (stays, gallery and so on)
   or in `index.html` with `data-img="key"`.

## Content to fill in

- **Guest reviews:** `reviews` in `js/data/content.js` is empty on purpose. The
  section stays hidden until real, verified reviews are added there.
- **Social links:** `contact.socials` is empty. The footer's "Follow" column
  appears once a link is added.
- **WhatsApp:** `contact.whatsapp` uses the hotline number, as in the resort's
  other site build. Remove it if that number isn't on WhatsApp.
- **Possible stock photos:** `campfire` and `bbq` (from the old site's
  `campfire.jpg` and `family-meal.jpg`) look like stock images. If they aren't
  the resort's own, swap their `src` in the media config and rebuild.

## Booking

The enquiry form has no backend, the same as the current site. It validates
the dates, then gives the guest a summary with a call button and a prefilled
WhatsApp message.
