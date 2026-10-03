#!/usr/bin/env bash
# Publica el sitio en Nginx con HTTPS (Let's Encrypt) sin afectar los demás sitios del servidor.
#   sudo bash deploy/configurar_nginx.sh eventos.coopetrol.coop [correo@coopetrol.coop]
# 1) Sitio solo HTTP (para validar el dominio) -> 2) certificado con certbot -> 3) sitio HTTPS.
# Antes de cada recarga se valida con "nginx -t"; si falla, se revierten los cambios de este sitio.
set -euo pipefail

DOMINIO="${1:-}"
CORREO="${2:-}"
SITIO=/etc/nginx/sites-available/evento-coopetrol
ENLACE=/etc/nginx/sites-enabled/evento-coopetrol
WEBROOT=/var/www/html
ORIGEN="$(cd "$(dirname "$0")/.." && pwd)"

[ "$(id -u)" -eq 0 ] || { echo "Ejecute como root (sudo)."; exit 1; }
[[ "$DOMINIO" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || { echo "Uso: sudo bash deploy/configurar_nginx.sh dominio [correo]"; exit 1; }
curl -fsS http://127.0.0.1:3000/api/cupos >/dev/null || { echo "La aplicación no responde en 127.0.0.1:3000 (producción). Ejecute primero deploy/instalar.sh."; exit 1; }

echo "==> DNS de $DOMINIO"
IP_DNS=$(getent ahostsv4 "$DOMINIO" | awk 'NR==1{print $1}')
IP_SERVIDOR=$(curl -fsS -4 --max-time 5 https://api.ipify.org 2>/dev/null || true)
echo "    $DOMINIO -> ${IP_DNS:-sin resolver} | IP pública del servidor: ${IP_SERVIDOR:-desconocida}"
if [ -z "$IP_DNS" ] || { [ -n "$IP_SERVIDOR" ] && [ "$IP_DNS" != "$IP_SERVIDOR" ]; }; then
  echo "    El dominio no apunta a este servidor; certbot no podrá validar. Corrija el DNS y reintente."; exit 1
fi

RESPALDO=""
[ -f "$SITIO" ] && { RESPALDO=$(mktemp); cp "$SITIO" "$RESPALDO"; }
revertir() {
  echo "    nginx -t falló: se revierten los cambios de este sitio (los demás sitios no se tocaron)."
  if [ -n "$RESPALDO" ]; then cp "$RESPALDO" "$SITIO"; else rm -f "$SITIO" "$ENLACE"; fi
  nginx -t >/dev/null 2>&1 && systemctl reload nginx
  exit 1
}
recargar() { nginx -t || revertir; systemctl reload nginx; }

if [ ! -f "/etc/letsencrypt/live/$DOMINIO/fullchain.pem" ]; then
  echo "==> Paso 1: sitio HTTP temporal para validar el dominio"
  cat > "$SITIO" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMINIO;
    location /.well-known/acme-challenge/ { root $WEBROOT; }
    location / { proxy_pass http://127.0.0.1:3000; proxy_set_header Host \$host; proxy_set_header X-Real-IP \$remote_addr; }
}
EOF
  ln -sf "$SITIO" "$ENLACE"
  recargar

  echo "==> Paso 2: certificado Let's Encrypt"
  if [ -n "$CORREO" ]; then CUENTA=(--email "$CORREO" --no-eff-email); else CUENTA=(--register-unsafely-without-email); fi
  certbot certonly --webroot -w "$WEBROOT" -d "$DOMINIO" --agree-tos --non-interactive "${CUENTA[@]}" \
    --deploy-hook "systemctl reload nginx"
fi

echo "==> Paso 3: sitio HTTPS"
sed "s/DOMINIO_EVENTO/$DOMINIO/g" "$ORIGEN/deploy/nginx-evento-coopetrol.conf" > "$SITIO"
ln -sf "$SITIO" "$ENLACE"
recargar
[ -n "$RESPALDO" ] && rm -f "$RESPALDO"

sleep 1
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "https://$DOMINIO/api/cupos" || true)
echo "==> https://$DOMINIO/api/cupos respondió $CODIGO"
[ "$CODIGO" = 200 ] && echo "    Sitio publicado: https://$DOMINIO  ·  Panel: https://$DOMINIO/admin.html"
