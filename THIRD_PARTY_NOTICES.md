# Third-party notices

PSGFX includes the following components. Their full license texts are in the headers of the files listed.

| Component | Files | License |
|---|---|---|
| bc7enc / bc7decomp by Richard Geldreich, Jr. | `src/lib/bc7enc.*`, `src/lib/bc7decomp.*` | MIT or public domain |
| stb_image, stb_image_write, stb_image_resize2 by Sean Barrett | `src/lib/stb_*.h` | MIT or public domain |
| BLAKE3 reference implementation | `src/lib/blake3*` | CC0 1.0 or Apache 2.0 |
| JSON for Modern C++ by Niels Lohmann | `src/lib/json.hpp` | MIT |
| PS5 AT9 Converter by BlackBearReloaded (ATRAC9 encoder and audio processing, ported to C++) | `src/lib/at9/` | GPL-3.0-or-later |
| LibAtrac9 by Alex Barney (ATRAC9 tables) | `src/lib/at9/` | MIT |
| minimp3, dr_wav, dr_flac (audio decoding) | `src/lib/at9/`, see `src/lib/at9/NOTICES.md` | CC0 / public domain or MIT-0 |
| Sora typeface, The Sora Project Authors | `assets/fonts/*.woff2` (embedded in `src/assets_embed.cpp`) | SIL Open Font License 1.1, see `assets/fonts/OFL.txt` |

PSGFX talks to the payload of [PS5 Upload](https://github.com/phantomptr/ps5upload) over the network. It does not include any PS5 Upload code.
