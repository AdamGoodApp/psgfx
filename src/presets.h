// Preset icons built into PSGFX, by title ID (assets/presets, embedded by tools/embed_presets.py).
#pragma once
#include <cstddef>

struct Preset { const char *app; const char *title_id; const unsigned char *data; size_t len; };
extern const Preset PRESETS[];
extern const size_t PRESETS_COUNT;
