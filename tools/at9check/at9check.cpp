// at9check: command-line harness for src/lib/at9 (the C++ snd0.at9 maker).
// SPDX-License-Identifier: GPL-3.0-or-later
//
//   at9check IN OUT.at9 [SECONDS] [options]
//     SECONDS          excerpt length (default 15; "max" or 0 = longest that fits)
//     --start S        excerpt start (seconds)
//     --bitrate K      72..192 kb/s
//     --fade-in S / --fade-out S / --loudness L / --true-peak D
//     --no-normalize / --no-loop
//     --threads N      encoder threads (0 = all cores)
//     --dump-pcm F     write the processed excerpt as raw float64 (left block, then right block)
//     --preview F      write the processed excerpt as 16-bit WAV (wav_bytes)
//   at9check --encode-raw PCM.f64 OUT.at9 [--bitrate K] [--no-loop] [--threads N]
//     encode raw planar float64 stereo 48 kHz PCM (left block, then right block) directly.
#include "../../src/lib/at9/at9.h"

#include <chrono>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <fstream>
#include <iterator>
#include <stdexcept>
#include <string>
#include <vector>

static std::vector<uint8_t> read_file(const std::string &p) {
    std::ifstream f(p, std::ios::binary);
    if (!f) throw std::runtime_error("cannot read " + p);
    return std::vector<uint8_t>((std::istreambuf_iterator<char>(f)), std::istreambuf_iterator<char>());
}

static void write_file(const std::string &p, const void *d, size_t n) {
    std::ofstream f(p, std::ios::binary);
    f.write((const char *)d, (std::streamsize)n);
    if (!f) throw std::runtime_error("cannot write " + p);
}

int main(int argc, char **argv) {
    try {
        std::vector<std::string> pos;
        at9::Options o;
        unsigned threads = 0;
        std::string dump, preview, raw;
        for (int i = 1; i < argc; i++) {
            std::string a = argv[i];
            auto next = [&]() -> std::string {
                if (i + 1 >= argc) throw std::runtime_error("missing value for " + a);
                return argv[++i];
            };
            if (a == "--start") o.start_sec = std::atof(next().c_str());
            else if (a == "--bitrate") o.bitrate_kbps = std::atoi(next().c_str());
            else if (a == "--fade-in") o.fade_in = std::atof(next().c_str());
            else if (a == "--fade-out") o.fade_out = std::atof(next().c_str());
            else if (a == "--loudness") o.lufs = std::atof(next().c_str());
            else if (a == "--true-peak") o.true_peak_db = std::atof(next().c_str());
            else if (a == "--no-normalize") o.normalize = false;
            else if (a == "--no-loop") o.loop = false;
            else if (a == "--threads") threads = (unsigned)std::atoi(next().c_str());
            else if (a == "--dump-pcm") dump = next();
            else if (a == "--preview") preview = next();
            else if (a == "--encode-raw") raw = next();
            else pos.push_back(a);
        }
        if (!raw.empty()) {
            if (pos.size() != 1) throw std::runtime_error("usage: at9check --encode-raw PCM.f64 OUT.at9");
            std::vector<uint8_t> b = read_file(raw);
            size_t n = b.size() / 16;
            std::vector<double> l(n), r(n);
            std::memcpy(l.data(), b.data(), n * 8);
            std::memcpy(r.data(), b.data() + n * 8, n * 8);
            auto t0 = std::chrono::steady_clock::now();
            std::vector<uint8_t> data = at9::detail::encode_atrac9(l, r, o.bitrate_kbps, threads);
            std::vector<uint8_t> file = at9::detail::build_at9(o.bitrate_kbps, (uint32_t)n, data, o.loop);
            double dt = std::chrono::duration<double>(std::chrono::steady_clock::now() - t0).count();
            write_file(pos[0], file.data(), file.size());
            std::printf("{\"bytes\": %zu, \"samples\": %zu, \"encode_seconds\": %.3f}\n", file.size(), n, dt);
            return 0;
        }
        if (pos.size() < 2 || pos.size() > 3) {
            std::fprintf(stderr, "usage: at9check IN OUT.at9 [SECONDS|max] [options]\n");
            return 2;
        }
        if (pos.size() == 3) o.duration_sec = pos[2] == "max" ? 0 : std::atof(pos[2].c_str());
        auto t0 = std::chrono::steady_clock::now();
        std::vector<uint8_t> bytes = read_file(pos[0]);
        at9::Audio audio;
        std::string err;
        if (!at9::decode_file(bytes, audio, err)) throw std::runtime_error(err);
        auto t1 = std::chrono::steady_clock::now();
        std::vector<double> l, r;
        at9::Result res = at9::detail::make_snd0_ex(audio, o, &l, &r, threads);
        auto t2 = std::chrono::steady_clock::now();
        write_file(pos[1], res.at9.data(), res.at9.size());
        if (!dump.empty()) {
            std::vector<double> both(l);
            both.insert(both.end(), r.begin(), r.end());
            write_file(dump, both.data(), both.size() * 8);
        }
        if (!preview.empty()) {
            std::vector<uint8_t> w = at9::wav_bytes(res.processed);
            write_file(preview, w.data(), w.size());
        }
        std::printf("{\"input_rate\": %d, \"input_channels\": %d, \"input_frames\": %zu, \"bytes\": %zu, "
                    "\"seconds\": %.6f, \"loudness_param\": \"%s\", \"decode_seconds\": %.3f, "
                    "\"make_snd0_seconds\": %.3f}\n",
                    audio.rate, audio.channels, audio.pcm.size() / (audio.channels ? audio.channels : 1),
                    res.at9.size(), res.seconds, res.loudness_param.c_str(),
                    std::chrono::duration<double>(t1 - t0).count(), std::chrono::duration<double>(t2 - t1).count());
        return 0;
    } catch (const std::exception &e) {
        std::fprintf(stderr, "error: %s\n", e.what());
        return 1;
    }
}
