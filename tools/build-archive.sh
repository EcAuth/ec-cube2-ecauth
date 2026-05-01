#!/bin/bash
#
# EcAuthLogin2 プラグインの配布用 tar.gz アーカイブをビルドする。
#
# EC-CUBE 2 プラグイン仕様書 (§3-3) に従い、フォルダごとではなくフォルダ内の
# ファイルを直接アーカイブする。tools/ など開発専用ファイルは含めない。
#
# 使い方:
#   ./tools/build-archive.sh                  # 既定で dist/EcAuthLogin2-<version>.tar.gz を生成
#   ./tools/build-archive.sh /path/to/out.tar.gz
#
# 生成された tar.gz は管理画面「オーナーズストア > プラグインを追加する」から
# アップロードしてインストールできる。

set -e

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}/plugin/EcAuthLogin2"

if [ ! -f "${PLUGIN_DIR}/plugin_info.php" ]; then
    echo "[build-archive] plugin_info.php not found at ${PLUGIN_DIR}" >&2
    exit 1
fi

VERSION=$(grep -oE "PLUGIN_VERSION\s*=\s*'[^']+'" "${PLUGIN_DIR}/plugin_info.php" | sed -E "s/.*'([^']+)'/\1/")
if [ -z "${VERSION}" ]; then
    echo "[build-archive] Failed to read PLUGIN_VERSION from plugin_info.php" >&2
    exit 1
fi

OUTPUT="${1:-${REPO_ROOT}/dist/EcAuthLogin2-${VERSION}.tar.gz}"
# 相対パス指定時は絶対パスに変換する
# (cd ${STAGE} 後に tar すると、相対 OUTPUT は trap で削除される STAGE 配下に出力されてしまう)
if [[ "${OUTPUT}" != /* ]]; then
    OUTPUT="${PWD}/${OUTPUT}"
fi
mkdir -p "$(dirname "${OUTPUT}")"

# 一時ステージングディレクトリで開発専用ファイルを除外してから固める
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

cp -R "${PLUGIN_DIR}/." "${STAGE}/"

# 開発環境専用ファイルは配布アーカイブから除外する
rm -rf "${STAGE}/tools"

# tar -C で cwd を変えずにアーカイブ。`-- .` でグロブ展開を避け SC2035 も回避
tar -C "${STAGE}" -czf "${OUTPUT}" -- .

echo "[build-archive] Built: ${OUTPUT}"
echo "[build-archive] Contents:"
tar tzf "${OUTPUT}" | sed 's/^/  /'
