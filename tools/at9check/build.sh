#!/bin/sh
# Build the at9check harness. Usage: tools/at9check/build.sh [g++|x86_64-w64-mingw32-g++]
set -e
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
cxx=${1:-${CXX:-g++}}
out="$here/at9check"
extra="-pthread"
case "$cxx" in
  *mingw*) out="$here/at9check.exe"; extra="-static -pthread" ;;
esac
"$cxx" -std=c++17 -O2 -Wall -Wextra -Wno-unused-function \
  "$here/at9check.cpp" "$root"/src/lib/at9/at9_encoder.cpp "$root"/src/lib/at9/at9_snd0.cpp \
  "$root"/src/lib/at9/at9_decode.cpp "$root"/src/lib/at9/at9_tables.cpp \
  -o "$out" $extra
echo "built $out"
