#!/usr/bin/env bash
# flatten-skopeo.sh — Aplatit les images mikhmon (arm64/armv7) pour MikroTik RouterOS 7
#
# Cibles :
#   armv7 → MikroTik hAP ax lite (L002-1nD)  — 16 MB flash, 64 MB RAM
#   arm64 → MikroTik hAP ax²  (D52G)         — 128 MB flash, 256 MB RAM
#
# Usage : ./tools/flatten-skopeo.sh [--push <registry/repo>]
#
# Dépendances : docker, skopeo

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
OUT="$PROJECT_DIR/docker-output"
mkdir -p "$OUT"

REPO="local/mikhmon-safelink"
TAG_FLAT_ARM64="flat-mikrotik-arm64"
TAG_FLAT_ARMV7="flat-mikrotik-armv7"

PUSH_REGISTRY=""
if [[ "${1:-}" == "--push" && -n "${2:-}" ]]; then
  PUSH_REGISTRY="$2"
fi

# ─── Couleurs ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error() { echo -e "${RED}[ERR]${NC}   $*" >&2; exit 1; }

# ─── Vérifications ─────────────────────────────────────────────────────────────
command -v docker  >/dev/null || error "docker requis"
command -v skopeo  >/dev/null || error "skopeo requis"

docker info >/dev/null 2>&1 || error "Docker daemon inaccessible"

info "Skopeo version : $(skopeo --version)"
info "Répertoire sortie : $OUT"

# ─── Étape 1 : Charger les tars source dans le daemon Docker ───────────────────
load_image() {
  local tar="$1" platform="$2"
  info "Chargement $tar ($platform)…"
  if [[ ! -f "$tar" ]]; then
    error "Tar introuvable : $tar"
  fi
  docker load < "$tar"
}

load_image "$OUT/mikhmon-arm64.tar" "linux/arm64"
load_image "$OUT/mikhmon-armv7.tar" "linux/arm/v7"

# Récupérer les IDs des images chargées depuis les tars
ID_ARM64=$(skopeo inspect docker-archive:"$OUT/mikhmon-arm64.tar" | \
           python3 -c "import sys,json; print(json.load(sys.stdin)['Digest'][:19])")
ID_ARMV7=$(skopeo inspect docker-archive:"$OUT/mikhmon-armv7.tar" | \
           python3 -c "import sys,json; print(json.load(sys.stdin)['Digest'][:19])")

# On s'appuie sur les images déjà présentes dans le daemon (chargées ci-dessus)
# en les re-taguant explicitement pour la manipulation
docker images --format "{{.Digest}}\t{{.ID}}" 2>/dev/null || true

# Retrouver le tag correct après chargement
SRC_ARM64=$(docker images --format "{{.Repository}}:{{.Tag}}" | \
            grep -v "<none>" | xargs -I{} docker inspect --format "{{.Architecture}} {{.RepoTags}}" {} 2>/dev/null | \
            grep "^arm64" | head -1 | awk '{print $2}' | tr -d '[]' || true)

# Approche plus fiable : utiliser skopeo copy direct depuis docker-archive
# ─── Étape 2 : Aplatir arm64 (hAP ax²  — 128 MB flash) ───────────────────────
info "=== Aplatissement arm64 (hAP ax²) ==="

FLAT_ARM64="mikhmon-flatten-work:arm64"

# Créer un conteneur temporaire depuis l'archive, l'exporter, le ré-importer (1 couche)
CONTAINER_ARM64="mikhmon_flatten_arm64_$(date +%s)"

docker load < "$OUT/mikhmon-arm64.tar"

# Trouver l'image chargée (sans tag, on cherche par label)
SRC_IMG_ARM64=$(docker images --format "{{.ID}}" --filter "label=org.opencontainers.image.title=Mikhmon" | head -1)
[[ -z "$SRC_IMG_ARM64" ]] && error "Image arm64 introuvable après chargement"

info "Image source arm64 : $SRC_IMG_ARM64"

docker create --name "$CONTAINER_ARM64" --platform linux/arm64 "$SRC_IMG_ARM64" >/dev/null

info "Export → import (squash toutes les couches → 1)…"
docker export "$CONTAINER_ARM64" | docker import \
  --platform linux/arm64 \
  --change 'LABEL org.opencontainers.image.title="Mikhmon"' \
  --change 'LABEL org.opencontainers.image.description="Mikhmon-MikroTik-RouterOS7-arm64-hAP-ax2"' \
  --change 'LABEL mikrotik.target="hap-ax2"' \
  --change 'LABEL mikrotik.flash="128MB"' \
  --change "WORKDIR /var/www/html" \
  --change "EXPOSE 80" \
  --change 'CMD ["/usr/local/bin/php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]' \
  - "$FLAT_ARM64"

docker rm "$CONTAINER_ARM64" >/dev/null
info "Aplatissement arm64 terminé → $FLAT_ARM64"

# ─── Étape 3 : Aplatir armv7 (hAP ax lite — 16 MB flash) ─────────────────────
info "=== Aplatissement armv7 (hAP ax lite) ==="

FLAT_ARMV7="mikhmon-flatten-work:armv7"
CONTAINER_ARMV7="mikhmon_flatten_armv7_$(date +%s)"

docker load < "$OUT/mikhmon-armv7.tar"

SRC_IMG_ARMV7=$(docker images --format "{{.ID}}" --filter "label=org.opencontainers.image.title=Mikhmon" | \
                xargs -I{} docker inspect --format "{{.Architecture}} {{.ID}}" {} | \
                grep "^arm " | head -1 | awk '{print $2}' || true)

# Fallback : prendre toutes les images avec le label, exclure arm64
if [[ -z "$SRC_IMG_ARMV7" ]]; then
  SRC_IMG_ARMV7=$(docker images --format "{{.ID}}" --filter "label=org.opencontainers.image.title=Mikhmon" | \
                  while read id; do
                    arch=$(docker inspect --format "{{.Architecture}}" "$id" 2>/dev/null)
                    [[ "$arch" == "arm" ]] && echo "$id" && break
                  done)
fi
[[ -z "$SRC_IMG_ARMV7" ]] && error "Image armv7 introuvable après chargement"

info "Image source armv7 : $SRC_IMG_ARMV7"

docker create --name "$CONTAINER_ARMV7" --platform linux/arm/v7 "$SRC_IMG_ARMV7" >/dev/null

info "Export → import (squash)…"
docker export "$CONTAINER_ARMV7" | docker import \
  --platform linux/arm/v7 \
  --change 'LABEL org.opencontainers.image.title="Mikhmon"' \
  --change 'LABEL org.opencontainers.image.description="Mikhmon-MikroTik-RouterOS7-armv7-hAP-ax-lite"' \
  --change 'LABEL mikrotik.target="hap-ax-lite"' \
  --change 'LABEL mikrotik.flash="16MB"' \
  --change "WORKDIR /var/www/html" \
  --change "EXPOSE 80" \
  --change 'CMD ["/usr/local/bin/php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]' \
  - "$FLAT_ARMV7"

docker rm "$CONTAINER_ARMV7" >/dev/null
info "Aplatissement armv7 terminé → $FLAT_ARMV7"

# ─── Étape 4 : Conversion skopeo → docker-archive OCI pour MikroTik ───────────
info "=== Conversion skopeo (docker-daemon → docker-archive) ==="

OUT_ARM64="$OUT/mikhmon-flat-arm64-mikrotik.tar"
OUT_ARMV7="$OUT/mikhmon-flat-armv7-mikrotik.tar"

# arm64 → hAP ax² (128 MB flash, image complète acceptée)
info "skopeo copy arm64…"
skopeo copy \
  --override-arch  arm64 \
  --override-os    linux \
  "docker-daemon:$FLAT_ARM64" \
  "docker-archive:${OUT_ARM64}:mikhmon-flat:arm64"

# armv7 → hAP ax lite (16 MB flash — ATTENTION : stockage USB recommandé)
info "skopeo copy armv7…"
skopeo copy \
  --override-arch    arm \
  --override-variant v7 \
  --override-os      linux \
  "docker-daemon:$FLAT_ARMV7" \
  "docker-archive:${OUT_ARMV7}:mikhmon-flat:armv7"

# ─── Étape 5 : Compression gzip max pour économiser le flash ──────────────────
info "=== Compression gzip -9 ==="

gzip -9 -f -k "$OUT_ARM64"
gzip -9 -f -k "$OUT_ARMV7"

# ─── Rapport ───────────────────────────────────────────────────────────────────
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
info "RÉSULTAT FINAL"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

for f in "$OUT_ARM64" "$OUT_ARMV7" "$OUT_ARM64.gz" "$OUT_ARMV7.gz"; do
  [[ -f "$f" ]] && printf "  %-55s %s\n" "$(basename "$f")" "$(du -sh "$f" | cut -f1)"
done

echo ""
info "Couches après aplatissement :"
skopeo inspect "docker-daemon:$FLAT_ARM64" | python3 -c \
  "import json,sys; d=json.load(sys.stdin); print(f'  arm64 : {len(d[\"Layers\"])} couche(s)')" 2>/dev/null || true
skopeo inspect "docker-daemon:$FLAT_ARMV7" | python3 -c \
  "import json,sys; d=json.load(sys.stdin); print(f'  armv7 : {len(d[\"Layers\"])} couche(s)')" 2>/dev/null || true

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
warn "hAP ax lite (16 MB flash) : utiliser le .gz → stocker sur clé USB"
warn "hAP ax²    (128 MB flash) : le tar non-compressé ou .gz convient"
echo ""
info "Import sur MikroTik RouterOS 7 :"
echo "  /container/add file=mikhmon-flat-armv7-mikrotik.tar interface=veth1 logging=yes"
echo "  /container/add file=mikhmon-flat-arm64-mikrotik.tar interface=veth1 logging=yes"
echo ""

# ─── Push optionnel ────────────────────────────────────────────────────────────
if [[ -n "$PUSH_REGISTRY" ]]; then
  info "Push vers $PUSH_REGISTRY …"
  skopeo copy \
    --override-arch arm64 --override-os linux \
    "docker-daemon:$FLAT_ARM64" \
    "docker://${PUSH_REGISTRY}:arm64"
  skopeo copy \
    --override-arch arm --override-variant v7 --override-os linux \
    "docker-daemon:$FLAT_ARMV7" \
    "docker://${PUSH_REGISTRY}:armv7"
  info "Push terminé."
fi
