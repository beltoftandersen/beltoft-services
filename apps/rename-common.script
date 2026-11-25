#!/bin/bash
# Moves a running one-click app to a new domain name. The plugin pushes this file followed by the app's own rename.script
# and a last line that calls beltoft_rename_main, and runs it detached (systemd-run) with the request in rename.json.
#
# Order (the old name keeps working until the new one is proven):
#   1. save the Caddyfile and the app's own host values in rename.prev
#   2. serve the old and the new name together (same site body), validate, reload
#   3. prove the certificate of the new name with a local TLS probe
#   4. change the app's own host setting and wait until the app answers
#   5. write the final Caddyfile (the new name only, and its www redirect), reload
#   6. probe the new name again
# Any failure after step 1 puts the Caddyfile and the app's host values back exactly. Customer content is never touched.
# A second run with the same run token converges to the same state; a finished token is left as it is. Secrets are never printed.
#
# The BELTOFT_REN_* variables exist for the tests; on a server they are not set.
set -uo pipefail
umask 077

state=${BELTOFT_REN_STATE:-/var/lib/beltoft-app}
etc=${BELTOFT_REN_ETC:-/etc/caddy}
tries=${BELTOFT_REN_TRIES:-24}
pause=${BELTOFT_REN_SLEEP:-5}
want=$state/rename.json
status_file=$state/rename.status.json
prev=$state/rename.prev
caddyfile=$etc/Caddyfile
body=$etc/beltoft-site.body
run=
domain=
www=
old_domain=
old_www=
ip=

# One status line: state step reason. The reader of the plugin takes only these fixed words.
status() {
  local tmp
  tmp=$(mktemp "$state/rename.status.XXXXXX") || return 1
  jq -n --arg run "$run" --arg state "$1" --arg step "$2" --arg reason "$3" --arg domain "$domain" --arg updated "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{v:1,run:$run,state:$state,step:$step,reason:$reason,domain:$domain,updated:$updated}' > "$tmp" || { rm -f "$tmp"; return 1; }
  chmod 0644 "$tmp"
  mv -f "$tmp" "$status_file"
}

# Reads and checks the request. Nothing is trusted: every value has the shape the recipes use.
load() {
  local re='^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$'
  [ -f "$want" ] || return 1
  jq -e '.v == 1 and .mode == "domain"' "$want" >/dev/null 2>&1 || return 1
  run=$(jq -er '.run' "$want") || return 1
  [[ "$run" =~ ^[A-Za-z0-9]{8,64}$ ]] || return 1
  domain=$(jq -er '.domain' "$want") || return 1
  www=$(jq -r '.www // ""' "$want") || return 1
  old_domain=$(jq -r '.old_domain // ""' "$want") || return 1
  old_www=$(jq -r '.old_www // ""' "$want") || return 1
  ip=$(jq -er '.ip' "$want") || return 1
  [[ "$domain" =~ $re && "$domain" == *.* ]] || return 1
  [ -z "$www" ] || [ "$www" = "www.$domain" ] || return 1
  [ -z "$old_domain" ] || { [[ "$old_domain" =~ $re && "$old_domain" == *.* ]] || return 1; }
  [ -z "$old_www" ] || [ "$old_www" = "www.$old_domain" ] || return 1
  [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
  return 0
}

# The site body the recipe wrote. A server from an older recipe has none: the app's script gives the body it had inline.
ensure_body() {
  [ -s "$body" ] && return 0
  declare -F app_legacy_body >/dev/null || return 1
  app_legacy_body > "$body" || return 1
  chmod 0644 "$body"
}

# The same text as the recipes' generator /usr/local/sbin/beltoft-caddy: one site block with the body, then the www redirect.
emit_site() {
  printf '%s {\n' "$1"
  cat "$body"
  printf '}\n'
}
emit_www() {
  [ -z "$1" ] || printf '%s {\n  redir https://%s{uri} permanent\n}\n' "$1" "$2"
}
emit_final() {
  emit_site "$domain"
  emit_www "$www" "$domain"
}
emit_both() {
  emit_site "$domain"
  emit_www "$www" "$domain"
  if [ -n "$old_domain" ]; then
    emit_site "$old_domain"
    emit_www "$old_www" "$old_domain"
  else
    printf 'http://%s {\n' "$ip"
    cat "$body"
    printf '}\n'
  fi
}

# Writes a Caddyfile from an emit_ function, validates it, puts it in place and reloads Caddy.
put_caddy() {
  local tmp
  tmp=$(mktemp "$etc/Caddyfile.XXXXXX") || return 1
  "$1" > "$tmp" || { rm -f "$tmp"; return 1; }
  if ! caddy validate --config "$tmp" --adapter caddyfile >/dev/null 2>&1; then
    rm -f "$tmp"
    return 1
  fi
  chmod 0644 "$tmp"
  mv -f "$tmp" "$caddyfile" || return 1
  systemctl reload caddy
}

# A TLS answer for the new name from this server proves that Caddy holds a certificate for it (curl does not skip the check).
wait_tls() {
  local n=0
  while [ "$n" -lt "$tries" ]; do
    if curl --silent --output /dev/null --max-time 10 --resolve "$domain:443:127.0.0.1" "https://$domain/"; then
      return 0
    fi
    n=$((n + 1))
    [ "$n" -ge "$tries" ] || sleep "$pause"
  done
  return 1
}

# The app answers on the new name through Caddy (any answer below 400; a redirect is one).
wait_web() {
  local n=0
  while [ "$n" -lt "$tries" ]; do
    if curl --fail --silent --output /dev/null --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain$APP_PATH"; then
      return 0
    fi
    n=$((n + 1))
    [ "$n" -ge "$tries" ] || sleep "$pause"
  done
  return 1
}

# Replaces KEY=value in an env file (or adds it) without printing it; the file keeps its owner and mode.
env_set() {
  local file=$1 key=$2 value=$3 tmp code
  tmp=$(mktemp "$state/rename.env.XXXXXX") || return 1
  if ! awk -v k="$key" -v v="$value" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; f = 1; next } { print } END { if (!f) print k "=" v }' "$file" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  cat "$tmp" > "$file"
  code=$?
  rm -f "$tmp"
  return "$code"
}

# Step 1: everything needed to go back. Skipped when this run token already saved it (a run that was cut off).
save_prev() {
  if [ -f "$prev/run" ] && [ "$(cat "$prev/run")" = "$run" ]; then
    return 0
  fi
  local created=0
  [ -f "$prev/body.created" ] && created=1
  rm -rf "$prev"
  mkdir -m 0700 "$prev" || return 1
  if [ "$created" = 1 ] || [ ! -s "$body" ]; then
    : > "$prev/body.created"
  fi
  cp -p "$caddyfile" "$prev/Caddyfile" || return 1
  app_save "$prev" || return 1
  printf '%s\n' "$run" > "$prev/run"
}

# Puts the Caddyfile and the app's values back. Returns 0 when both are back.
restore_prev() {
  [ -f "$prev/run" ] && [ "$(cat "$prev/run")" = "$run" ] || return 1
  local code=0
  # The app is put back only when this run got as far as changing it (a failure before that leaves it alone: no restart).
  if [ -f "$prev/app.touched" ]; then
    app_restore "$prev" || code=1
  fi
  if [ -f "$prev/Caddyfile" ]; then
    { cat "$prev/Caddyfile" > "$caddyfile" && systemctl reload caddy; } || code=1
  else
    code=1
  fi
  if [ -f "$prev/body.created" ]; then
    rm -f "$body"
  fi
  return "$code"
}

# A failure: put everything back, then report the reason. The old name works again.
fail() {
  local reason=$1
  status running rollback "$reason"
  if restore_prev; then
    rm -rf "$prev"
    status failed done "$reason"
  else
    status failed rollback "$reason"
  fi
  exit 1
}

beltoft_rename_main() {
  mkdir -p "$state"
  exec 9> "$state/rename.lock"
  if ! load; then
    if [[ "$run" =~ ^[A-Za-z0-9]{8,64}$ ]]; then
      status failed caddy caddy
    fi
    exit 2
  fi
  if ! flock -n 9; then
    # Another run is going. The same token is that run; another token is told it is busy.
    if [ "$(jq -r '.run // ""' "$status_file" 2>/dev/null)" != "$run" ]; then
      status failed caddy busy
    fi
    exit 0
  fi
  if [ "$(jq -r '.run // ""' "$status_file" 2>/dev/null)" = "$run" ]; then
    case "$(jq -r '.state // ""' "$status_file" 2>/dev/null)" in
      ready | failed) exit 0 ;;
    esac
  fi

  status running caddy none
  if [ "$domain" = "$old_domain" ] || [ "$domain" = "$old_www" ] || [ ! -f "$caddyfile" ] || ! command -v caddy >/dev/null 2>&1; then
    status failed caddy caddy
    exit 1
  fi
  if ! save_prev; then
    rm -rf "$prev"
    status failed caddy caddy
    exit 1
  fi
  ensure_body || fail caddy
  put_caddy emit_both || fail caddy

  status running certificate none
  wait_tls || fail certificate

  status running app none
  : > "$prev/app.touched"
  app_apply || fail app
  wait_web || fail app

  status running caddy none
  put_caddy emit_final || fail caddy

  status running verify none
  { wait_tls && wait_web; } || fail probe

  rm -rf "$prev"
  status ready done none
  exit 0
}
