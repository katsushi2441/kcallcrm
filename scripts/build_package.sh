#!/usr/bin/env bash
# 配布物 zip（main の git archive。設定・データ・outputs は .gitignore で入らない）。  bash scripts/build_package.sh [出力先]
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-/home/kojima/work/kcallcrm-sales/store/kcallcrm-kit.zip}"
php scripts/check.php >/dev/null
git archive --format=zip --prefix=kcallcrm/ -o "$OUT" HEAD
unzip -l "$OUT" | tail -1; echo "$OUT"
