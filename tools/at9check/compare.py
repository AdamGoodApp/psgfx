#!/usr/bin/env python3
# Compare the C++ snd0.at9 maker (tools/at9check) against the Python reference
# (PS5 AT9 Converter, https://github.com/blackbearreloaded/ps5-at9-converter).
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Usage: tools/at9check/compare.py /path/to/ps5-at9-converter [WORKDIR]
#
# For each generated input it runs the Python CLI and the C++ harness with the
# same options, then reports:
#   - whether the .at9 files are byte-identical, and the first differing offset;
#   - header (first 168 bytes / chunk layout) equality;
#   - processed-PCM difference (C++ vs Python pipeline before encoding);
#   - encoder-only identity: both encoders fed the very same PCM;
#   - SNR of each decoded file (Python reference decoder) vs the processed input,
#     and of the two decoded files vs each other;
#   - file size vs the 2 MiB limit, and timings.
import json
import math
import os
import shutil
import subprocess
import sys
import time

import numpy as np

REF = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else "/tmp/claude-0/at9")
WORK = os.path.abspath(sys.argv[2] if len(sys.argv) > 2 else "at9check-work")
HERE = os.path.dirname(os.path.abspath(__file__))
EXE = os.path.join(HERE, "at9check")
sys.path.insert(0, REF)

from ps5at9 import at9file, atrac9, audio_io, dsp, wav  # noqa: E402
from ps5at9.atrac9_encoder import Encoder  # noqa: E402

RATE = 48000


def write_wav_any(path, x, rate, kind):
    """x: (channels, frames) float in [-1, 1]. kind: pcm16 | pcm24 | float32."""
    x = np.atleast_2d(x)
    ch, n = x.shape
    inter = x.T
    if kind == "float32":
        payload, tag, bits = inter.astype("<f4").tobytes(), 3, 32
    else:
        bits = 16 if kind == "pcm16" else 24
        scale = float(1 << (bits - 1))
        ints = np.clip(np.round(inter * scale), -scale, scale - 1).astype("<i4")
        payload = ints.astype("<i2").tobytes() if bits == 16 else \
            np.ascontiguousarray(ints).view(np.uint8).reshape(n, ch, 4)[:, :, :3].tobytes()
        tag = 1
    import struct
    w = bits // 8
    fmt = struct.pack("<HHIIHH", tag, ch, rate, rate * ch * w, ch * w, bits)
    body = b"WAVE" + b"fmt " + struct.pack("<I", len(fmt)) + fmt + b"data" + struct.pack("<I", len(payload)) + payload
    if len(payload) & 1:
        body += b"\0"
    with open(path, "wb") as f:
        f.write(b"RIFF" + struct.pack("<I", len(body)) + body)


def make_inputs():
    os.makedirs(WORK, exist_ok=True)
    rng = np.random.default_rng(12345)
    items = []

    def add(name, x, rate, kind, args=(), seconds=None):
        p = os.path.join(WORK, name)
        write_wav_any(p, x, rate, kind)
        items.append((name, p, list(args), seconds))

    t = np.arange(20 * RATE) / RATE
    f0, f1 = 30.0, 18000.0
    sweep = 0.5 * np.sin(2 * np.pi * f0 * 20 / math.log(f1 / f0) * (np.exp(t / 20 * math.log(f1 / f0)) - 1))
    add("sweep-48k-16.wav", np.vstack([sweep, sweep]), RATE, "pcm16")
    add("noise-48k-f32.wav", np.clip(rng.standard_normal((2, 16 * RATE)) * 0.25, -1, 1), RATE, "float32")
    t44 = np.arange(20 * 44100) / 44100
    tones = sum(a * np.sin(2 * np.pi * f * t44 + p) for a, f, p in
                ((0.2, 110, 0), (0.15, 220.5, 1), (0.1, 440, 2), (0.08, 1318.5, 0.3), (0.05, 3520, 0.7)))
    env = 0.6 + 0.4 * np.sin(2 * np.pi * 0.5 * t44) ** 2
    noise = rng.standard_normal((2, len(t44))) * 0.03
    music = np.vstack([tones * env, np.roll(tones, 300) * env]) + noise
    add("music-44k-24.wav", music, 44100, "pcm24")
    add("silence-48k-16.wav", np.zeros((2, 10 * RATE)), RATE, "pcm16")
    t2 = np.arange(2 * RATE) / RATE
    add("short-2s-48k-16.wav", np.vstack([0.4 * np.sin(2 * np.pi * 660 * t2), 0.4 * np.sin(2 * np.pi * 990 * t2)]),
        RATE, "pcm16")
    t12 = np.arange(12 * RATE) / RATE
    add("stereo-diff-48k-16.wav", np.vstack([0.5 * np.sin(2 * np.pi * 523.25 * t12),
                                             np.clip(rng.standard_normal(len(t12)) * 0.2, -1, 1)]), RATE, "pcm16")
    add("mono-22k-16.wav", 0.3 * np.sin(2 * np.pi * 300 * np.arange(8 * 22050) / 22050)[None, :], 22050, "pcm16")
    # Loud music: exercises the true-peak limiter and an excerpt start.
    add("loud-start-48k-16.wav", np.clip(np.vstack([music[0], music[1]])[:, :18 * 44100] * 3.0, -1, 1),
        44100, "pcm16", args=["--start", "2.5"])
    # Long input for the timing of an 85 s excerpt.
    tl = np.arange(90 * RATE) / RATE
    long = 0.3 * np.sin(2 * np.pi * 220 * tl) * (0.5 + 0.5 * np.sin(2 * np.pi * 0.1 * tl)) + \
        rng.standard_normal((2, len(tl))) * 0.02
    add("long-90s-48k-16.wav", long, RATE, "pcm16", seconds="85")
    ff = shutil.which("ffmpeg")
    if ff:
        src = os.path.join(WORK, "music-44k-24.wav")
        mp3 = os.path.join(WORK, "music.mp3")
        subprocess.run([ff, "-loglevel", "error", "-y", "-i", src, "-c:a", "libmp3lame", "-b:a", "192k", mp3],
                       check=True)
        items.append(("music.mp3", mp3, [], None))
        flac = os.path.join(WORK, "music.flac")
        subprocess.run([ff, "-loglevel", "error", "-y", "-i", src, "-c:a", "flac", flac], check=True)
        items.append(("music.flac", flac, [], None))
        aiff = os.path.join(WORK, "music.aiff")
        subprocess.run([ff, "-loglevel", "error", "-y", "-i", src, "-c:a", "pcm_s16be", aiff], check=True)
        items.append(("music.aiff", aiff, [], None))
    for name in ("tone-44k-stereo.mp3", "tone-44k.flac"):
        p = os.path.join(REF, "tests", "data", name)
        if os.path.exists(p):
            items.append((name, p, [], None))
    return items


def py_processed(path, start=0.0, duration=15.0, bitrate=192, loop=True):
    """The Python CLI pipeline up to the encoder (mirrors ps5at9/cli.py run())."""
    max_samples = (2 * 1024 * 1024 - at9file.header_size(loop)) // (bitrate * 1000 * 256 // (8 * RATE) * 4) \
        * 1024 - 256
    pcm, rate, offset = audio_io.load(path, start, duration)
    pcm = dsp.resample(dsp.to_stereo(pcm), rate, RATE)
    skip = int(round(offset * RATE))
    available = max(0, pcm.shape[1] - skip)
    count = min(available, max_samples) if duration is None else min(available, int(round(duration * RATE)))
    pcm = np.ascontiguousarray(pcm[:, skip:skip + count])
    lufs = dsp.integrated_loudness(pcm)
    if math.isfinite(lufs):
        pcm = pcm * 10.0 ** ((-28.0 - lufs) / 20.0)
    pcm, _ = dsp.limit_true_peak(pcm, -2.0, RATE)
    return dsp.apply_fades(pcm, RATE, 0.01, 0.01)


def decode(blob):
    info = at9file.read_at9(blob)
    out = atrac9.Decoder(info.config).decode(info.data)
    return info, out[:, 256:256 + info.sample_count] / 32768.0


def snr(ref, test):
    num = float(np.sum(ref ** 2))
    den = float(np.sum((ref - test) ** 2))
    if den == 0:
        return math.inf
    if num == 0:
        return -math.inf
    return 10 * math.log10(num / den)


def chunks(blob):
    out, pos = [], 12
    import struct
    while pos + 8 <= len(blob):
        cid, size = blob[pos:pos + 4], struct.unpack_from("<I", blob, pos + 4)[0]
        out.append((cid.decode("latin-1"), size))
        pos += 8 + size + (size & 1)
    return out


def main():
    if not os.path.exists(EXE):
        sys.exit("build the harness first: tools/at9check/build.sh")
    results = []
    all_ok = True
    for name, path, args, seconds in make_inputs():
        stem = os.path.join(WORK, name.replace(".", "_"))
        py_out, cpp_out = stem + ".py.at9", stem + ".cpp.at9"
        dur = seconds or "15"
        py_args = ["-q", "--duration", dur] + args
        t0 = time.time()
        subprocess.run([sys.executable, os.path.join(REF, "ps5_at9.py"), "-i", path, "-o", py_out] + py_args,
                       check=True)
        py_time = time.time() - t0
        cpp = subprocess.run([EXE, path, cpp_out, dur, "--dump-pcm", stem + ".cpp.f64"] + args,
                             check=True, capture_output=True, text=True)
        meta = json.loads(cpp.stdout)
        a, b = open(py_out, "rb").read(), open(cpp_out, "rb").read()
        r = {"input": name, "bytes": len(b), "py_bytes": len(a), "identical": a == b,
             "cpp_seconds": meta["make_snd0_seconds"], "py_seconds": round(py_time, 2),
             "excerpt_s": meta["seconds"], "loudness_param": meta["loudness_param"]}
        if a != b:
            diff = next((i for i in range(min(len(a), len(b))) if a[i] != b[i]), min(len(a), len(b)))
            r["first_diff"] = diff
            r["differing_superframes"] = int(sum(a[168 + i * 512:168 + (i + 1) * 512] != b[168 + i * 512:168 + (i + 1) * 512]
                                                 for i in range((len(b) - 168) // 512)))
        r["header_identical"] = a[:168 - 4] == b[:168 - 4] and chunks(a) == chunks(b)
        r["size_ok"] = len(b) <= 2 * 1024 * 1024
        # processed PCM: C++ vs Python pipeline
        start = float(args[args.index("--start") + 1]) if "--start" in args else 0.0
        ref = py_processed(path, start, float(dur))
        raw = np.fromfile(stem + ".cpp.f64", dtype="<f8")
        cpp_pcm = raw.reshape(2, -1)
        r["pcm_max_abs_diff"] = float(np.max(np.abs(cpp_pcm - ref))) if cpp_pcm.shape == ref.shape else "shape"
        # Encoder only: both encoders on the C++-processed PCM.
        enc = Encoder(2, 192)
        py_data = enc.encode(cpp_pcm * 32768.0)
        py_blob = at9file.build_at9(enc.config, 2, cpp_pcm.shape[1], py_data)
        raw_out = stem + ".raw.at9"
        subprocess.run([EXE, "--encode-raw", stem + ".cpp.f64", raw_out], check=True, capture_output=True)
        raw_blob = open(raw_out, "rb").read()
        r["encoder_only_identical"] = raw_blob == py_blob
        if raw_blob != py_blob:
            # Same input PCM: the streams must at least decode to the same audio
            # (different but equivalent parameter choices; see README notes).
            _i, d1 = decode(raw_blob)
            _i, d2 = decode(py_blob)
            r["encoder_only_decoded_max_diff"] = float(np.max(np.abs(d1 - d2)))
        # Decode both with the Python reference decoder.
        info_c, dec_c = decode(b)
        _info_p, dec_p = decode(a)
        r["snr_cpp_vs_input_db"] = round(snr(cpp_pcm, dec_c), 2)
        r["snr_py_vs_input_db"] = round(snr(ref, dec_p), 2) if ref.shape == dec_p.shape else None
        r["snr_cpp_vs_py_decoded_db"] = round(snr(dec_p, dec_c), 2) if dec_p.shape == dec_c.shape else None
        ok = r["header_identical"] and r["size_ok"] and (
            r["encoder_only_identical"] or r["encoder_only_decoded_max_diff"] < 1e-6)
        if not r["identical"] and r["snr_cpp_vs_py_decoded_db"] != math.inf:
            # Must stay well below the coding noise.
            ok = ok and r["snr_cpp_vs_py_decoded_db"] is not None and \
                r["snr_cpp_vs_py_decoded_db"] > r["snr_cpp_vs_input_db"] + 10
        r["ok"] = ok
        all_ok &= ok
        results.append(r)
        print(json.dumps(r), flush=True)
    print("ALL OK" if all_ok else "SOME CHECKS FAILED")
    return 0 if all_ok else 1


if __name__ == "__main__":
    sys.exit(main())
