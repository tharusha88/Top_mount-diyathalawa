"""
Top Mountain Resort - media build.

Reads tools/media.config.json, turns the original photos and drone footage into
web-ready files, and writes js/data/media.js: the manifest the site reads.
No page or script refers to a media file by path; everything goes through a key.

  python tools/build_media.py                 # build what is missing or out of date
  python tools/build_media.py --force         # rebuild everything
  python tools/build_media.py --only images   # or: --only videos
  python tools/build_media.py --ffmpeg C:/path/to/ffmpeg.exe

Needs Pillow (pip install pillow). Videos and video stills also need ffmpeg:
on PATH, in the FFMPEG environment variable, passed with --ffmpeg, or from
the imageio-ffmpeg package.
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parent.parent
CONFIG = ROOT / "tools" / "media.config.json"


# ---------------------------------------------------------------- helpers

def find_ffmpeg(explicit):
    for cand in (explicit, os.environ.get("FFMPEG"), shutil.which("ffmpeg")):
        if cand and Path(cand).exists():
            return str(cand)
    try:
        import imageio_ffmpeg  # type: ignore
        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        return None


def resolve(ref, sources):
    prefix, _, rel = ref.partition(":")
    if prefix not in sources:
        raise SystemExit(f"Unknown source prefix '{prefix}' in '{ref}'")
    path = Path(sources[prefix]) / rel
    if not path.exists():
        raise SystemExit(f"Missing source file: {path}")
    return path


def stale(outputs, inputs, force):
    if force:
        return True
    outs = [Path(o) for o in outputs]
    if not all(o.exists() for o in outs):
        return True
    newest_in = max(Path(i).stat().st_mtime for i in inputs)
    return min(o.stat().st_mtime for o in outs) < newest_in


def run(cmd):
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        sys.stderr.write(res.stderr[-3000:])
        raise SystemExit(f"ffmpeg failed: {' '.join(map(str, cmd[:6]))} ...")


def rel(p):
    return Path(p).resolve().relative_to(ROOT).as_posix()


# ---------------------------------------------------------------- images

def grab_frame(ffmpeg, video, t, dest):
    run([ffmpeg, "-hide_banner", "-loglevel", "error", "-y", "-ss", str(t), "-i", str(video),
         "-frames:v", "1", "-q:v", "1", str(dest)])


def build_image(key, spec, cfg, sources, ffmpeg, force, tmpdir):
    out_dir = ROOT / cfg["output"]["images"]
    out_dir.mkdir(parents=True, exist_ok=True)

    if "frame" in spec:
        if not ffmpeg:
            raise SystemExit(f"'{key}' is a video still and needs ffmpeg")
        video = resolve(spec["frame"], sources)
        src = Path(tmpdir) / f"{key}.png"
        grab_frame(ffmpeg, video, spec.get("time", 0), src)
        inputs = [video, CONFIG]
    else:
        src = resolve(spec["src"], sources)
        inputs = [src, CONFIG]

    im = Image.open(src)
    im = ImageOps.exif_transpose(im).convert("RGB")
    if spec.get("crop"):
        x0, y0, x1, y1 = spec["crop"]
        w, h = im.size
        im = im.crop((round(x0 * w), round(y0 * h), round(x1 * w), round(y1 * h)))
    w, h = im.size

    widths = [x for x in cfg["imageWidths"] if x < w] + [min(w, cfg["imageWidths"][-1])]
    widths = sorted(set(widths))
    fb_w = min(cfg["fallbackWidth"], w)

    outputs = [out_dir / f"{key}-{x}.webp" for x in widths] + [out_dir / f"{key}-{fb_w}.jpg"]
    if stale(outputs, inputs, force):
        for x in widths:
            r = im if x == w else im.resize((x, round(h * x / w)), Image.LANCZOS)
            r.save(out_dir / f"{key}-{x}.webp", "WEBP", quality=cfg["webpQuality"], method=6)
        r = im if fb_w == w else im.resize((fb_w, round(h * fb_w / w)), Image.LANCZOS)
        r.save(out_dir / f"{key}-{fb_w}.jpg", "JPEG", quality=cfg["jpgQuality"], optimize=True, progressive=True)
        print(f"  img  {key:20s} {w}x{h} -> {widths}")
    else:
        print(f"  ok   {key}")

    avg = im.resize((1, 1), Image.BOX).getpixel((0, 0))
    entry = {
        "base": rel(out_dir / key),
        "widths": widths,
        "fallback": rel(out_dir / f"{key}-{fb_w}.jpg"),
        "w": w, "h": h,
        "alt": spec.get("alt", ""),
        "color": "#%02x%02x%02x" % avg,
    }
    if spec.get("pos"):
        entry["pos"] = spec["pos"]
    return entry


# ---------------------------------------------------------------- videos

def video_filter(spec, var):
    """Build the filter graph for one output variant."""
    w, h = var["w"], var["h"]
    if var.get("role") == "portrait" and w < h:
        fx = var.get("focusX", 0.5)
        frame = f"crop=ih*{w}/{h}:ih:(iw-ih*{w}/{h})*{fx}:0,scale={w}:{h}:flags=lanczos,setsar=1"
    else:
        frame = f"scale={w}:{h}:flags=lanczos,setsar=1"

    segs = spec.get("segments")
    if not segs:
        return f"[0:v]{frame},format=yuv420p[v]"

    xf = spec.get("xfade", 0)
    parts = []
    for i, (a, b) in enumerate(segs):
        parts.append(f"[0:v]trim={a}:{b},setpts=PTS-STARTPTS,{frame},fps=30[s{i}]")
    if xf <= 0:
        parts.append("".join(f"[s{i}]" for i in range(len(segs))) + f"concat=n={len(segs)}:v=1:a=0,format=yuv420p[v]")
        return ";".join(parts)
    last, offset = "s0", 0.0
    for i in range(1, len(segs)):
        offset += (segs[i - 1][1] - segs[i - 1][0]) - xf
        out = f"x{i}"
        parts.append(f"[{last}][s{i}]xfade=transition=fade:duration={xf}:offset={offset:.3f}[{out}]")
        last = out
    parts.append(f"[{last}]format=yuv420p[v]")
    return ";".join(parts)


def build_video(key, spec, cfg, sources, ffmpeg, force):
    out_dir = ROOT / cfg["output"]["videos"]
    out_dir.mkdir(parents=True, exist_ok=True)
    src = resolve(spec["src"], sources)
    entry = {"label": spec.get("label", ""), "poster": spec.get("poster"), "sources": []}
    if spec.get("posterPortrait"):
        entry["posterPortrait"] = spec["posterPortrait"]

    for var in spec["variants"]:
        dest = out_dir / f"{key}-{var['id']}.mp4"
        if stale([dest], [src, CONFIG], force):
            if not ffmpeg:
                raise SystemExit(f"Video '{key}' needs ffmpeg")
            cmd = [ffmpeg, "-hide_banner", "-loglevel", "error", "-y", "-i", str(src),
                   "-filter_complex", video_filter(spec, var), "-map", "[v]"]
            if spec.get("audio") and not spec.get("segments"):
                cmd += ["-map", "0:a?", "-c:a", "aac", "-b:a", "128k"]
            else:
                cmd += ["-an"]
            cmd += ["-c:v", "libx264", "-preset", "slow", "-crf", str(var.get("crf", 26)),
                    "-profile:v", "high", "-pix_fmt", "yuv420p", "-tag:v", "avc1",
                    "-movflags", "+faststart"]
            if var.get("maxrate"):
                rate = var["maxrate"]
                buf = f"{float(rate.rstrip('M')) * 2:g}M"
                cmd += ["-maxrate", rate, "-bufsize", buf]
            cmd.append(str(dest))
            print(f"  vid  {dest.name} ...", flush=True)
            run(cmd)
            print(f"       {dest.stat().st_size / 1e6:.1f} MB")
        else:
            print(f"  ok   {dest.name}")
        entry["sources"].append({"src": rel(dest), "role": var.get("role", "landscape"),
                                 "w": var["w"], "h": var["h"], "type": "video/mp4"})
    return entry


# ---------------------------------------------------------------- main

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--only", choices=["images", "videos"])
    ap.add_argument("--ffmpeg")
    args = ap.parse_args()

    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    sources = cfg["sources"]
    ffmpeg = find_ffmpeg(args.ffmpeg)

    manifest_path = ROOT / cfg["output"]["manifest"]
    previous = {}
    if manifest_path.exists():
        txt = manifest_path.read_text(encoding="utf-8")
        start = txt.find("{")
        try:
            previous = json.loads(txt[start:txt.rstrip().rstrip(";").rfind("}") + 1])
        except Exception:
            previous = {}

    images = previous.get("images", {})
    videos = previous.get("videos", {})

    if args.only != "videos":
        print("Images")
        images = {}
        with tempfile.TemporaryDirectory() as tmp:
            for key, spec in cfg["images"].items():
                images[key] = build_image(key, spec, cfg, sources, ffmpeg, args.force, tmp)

    if args.only != "images":
        print("Videos")
        videos = {}
        for key, spec in cfg["videos"].items():
            videos[key] = build_video(key, spec, cfg, sources, ffmpeg, args.force)

    manifest = {"images": images, "videos": videos}
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(
        "/* Generated by tools/build_media.py from tools/media.config.json. Do not edit by hand. */\n"
        "window.TMR_MEDIA = " + json.dumps(manifest, indent=1, ensure_ascii=False) + ";\n",
        encoding="utf-8")
    print(f"Manifest -> {rel(manifest_path)} ({len(images)} images, {len(videos)} videos)")


if __name__ == "__main__":
    main()
