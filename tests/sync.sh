#!/usr/bin/env bash
# Stage-4a checks on the two stands (endpoint, protection, ping). One run,
# from the repository root (run.sh calls it):
#   tests/sync.sh
# Needs both stands running; tests/stands.sh prepares them.
# Writes the refusal table to tests/out/sync-refusals.md.
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
LEARN=${LEARN:-$HOME/Documents/learn}
A=$LEARN/spilno-dev
B=$LEARN/spilno-dev-b
UA=https://spilno-dev.ddev.site:33001
UB=https://spilno-dev-b.ddev.site:33001
pass=0; fail=0
ok()  { pass=$((pass+1)); }
bad() { fail=$((fail+1)); echo "FAIL $*"; }
chk() { if [ "$2" = "$3" ]; then ok; else bad "$1 — got '$2', expected '$3'"; fi; }
has() { case "$2" in *"$3"*) ok ;; *) bad "$1 — '$2' has no '$3'" ;; esac; }
wa() { (cd "$A" && ddev wp "$@" 2>&1); }
wb() { (cd "$B" && ddev wp "$@" 2>&1); }
sa() { wa spilno-test send "$@" | tail -1; }   # a → b
sb() { wb spilno-test send "$@" | tail -1; }   # b → a
snap() { wb spilno-test state | tail -1; }
reset() { wb spilno-test guard-reset >/dev/null; }
mkdir -p "$REPO/tests/out"
table=$REPO/tests/out/sync-refusals.md
printf '| Request | Code | Journal of the receiver |\n|---|---|---|\n' > "$table"
logs=(); for S in "$A" "$B"; do touch "$S/wp-content/debug.log"; logs+=("$(wc -l < "$S/wp-content/debug.log")"); done

"$REPO/tests/stands.sh" >/dev/null 2>&1 || bad "stands.sh"
rm -f "$A/spilno-test-no-link" "$B/spilno-test-no-link" "$B/spilno-test-peer-ips"
wb option delete spilno_test_disabled >/dev/null; wb option delete spilno_test_redirect >/dev/null
reset

# A refusal: status, a new journal row matching the reason, nothing else written
# (with `nonce`: the nonce of an authenticated request is written, nothing else).
refuse() { # name code reason-pattern nonce|- args...
	local name=$1 code=$2 pat=$3 nonce=$4; shift 4
	local before after r j
	before=$(snap); r=$(sa "$@"); after=$(snap)
	j=${after#*|}
	chk "$name: status" "${r%% *}" "$code"
	if [ "$pat" = "-" ]; then chk "$name: no journal row" "${after#*|}" "${before#*|}"
	else [ "${after#*|}" != "${before#*|}" ] && echo "$j" | grep -qE "$pat" && ok || bad "$name: journal '$j' !~ $pat"; fi
	local fb=${before%%|*} fa=${after%%|*}
	if [ "$nonce" = nonce ]; then
		read -r p1 l1 n1 o1 <<<"$fb"; read -r p2 l2 n2 o2 <<<"$fa"
		# The nonce of the authenticated request is stored (and the purge may drop stale ones).
		chk "$name: only the nonce written" "$p2 $l2 $o2" "$p1 $l1 $o1"
	else chk "$name: nothing written" "$fa" "$fb"; fi
	[ "$pat" = "-" ] && j="—" || j=$(echo "$j" | cut -d' ' -f2-)
	printf '| %s | %s | %s |\n' "$name" "${r%% *}" "$j" >> "$table"
}

# PHP checks.
u=$(wa spilno-test sync-unit); echo "$u" | grep -E '^FAIL'
echo "$u" | grep -q '^sync-unit: .* passed' && ! echo "$u" | grep -q '^FAIL' && ok || bad "sync-unit"

# Ping both ways, with the other direction.
for side in a b; do
	if [ $side = a ]; then out=$(wa spilno ping --format=csv); else out=$(wb spilno ping --format=csv); fi
	echo "$out" | grep -q '^fail' && bad "ping $side: $(echo "$out" | grep '^fail')" || ok
	has "ping $side: reverse" "$out" "ok,reverse"
	has "ping $side: clock" "$out" "ok,clock"
done
reset

# Accepted.
r=$(sa); chk "valid ping a → b" "${r%% *}" "200"; has "pong site" "$r" '"site":"b"'
r=$(sb); chk "valid ping b → a" "${r%% *}" "200"
r=$(sa --op=dev/echo --secret=previous); chk "SPILNO_SECRET_PREVIOUS accepted" "${r%% *}" "200"; has "previous matched" "$r" '"secret":"previous"'
r=$(sa --shift=-290); chk "time −290 s accepted" "${r%% *}" "200"
r=$(sa --shift=290); chk "time +290 s accepted" "${r%% *}" "200"

# Refusals.
refuse "path: unknown operation" 404 - - --op=zzz
refuse "path: query string" 404 - - --query=x=1
refuse "method: POST to ping" 404 - - --method=POST
refuse "no signature header" 401 '401 headers' - --unsigned
refuse "forged signature" 401 '401 signature' - --bad-sig
refuse "foreign secret" 401 '401 signature' - --secret=foreign
refuse "time −301 s" 401 '401 time -30[0-9]' - --shift=-301
refuse "time +301 s" 401 '401 time 30[0-9]' - --shift=301
refuse "foreign env (staging)" 403 '403 env staging' - --env=staging
refuse "foreign site id" 403 '403 site' - --site=c
n=$(php -r 'echo bin2hex(random_bytes(16));')
r=$(sa --nonce="$n"); chk "nonce: first use" "${r%% *}" "200"
refuse "nonce replay" 401 '401 replay' - --nonce="$n"
refuse "body > 256 KB (Content-Length)" 413 '413 size' - --op=dev/echo --size=300000
refuse "body not JSON" 400 '400 not a JSON object' nonce --op=dev/echo --raw='text=hello'
refuse "body: JSON list" 400 '400 not a JSON object' nonce --op=dev/echo --b64="$(printf %s '["hello"]' | base64 -w0)"
refuse "body: unknown field" 400 '400 unknown field zzz' nonce --op=dev/echo --b64="$(printf %s '{"text":"a","zzz":1}' | base64 -w0)"
refuse "body: field not a string" 400 '400 field text' nonce --op=dev/echo --b64="$(printf %s '{"text":["a"]}' | base64 -w0)"
refuse "body on GET ping" 400 '400 body on GET' nonce --raw='x'

# Admin cookie in the request is ignored.
ck=$(wb eval 'echo SECURE_AUTH_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + 600, "secure_auth" ) ) . "; " . LOGGED_IN_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + 600, "logged_in" ) );' | tail -1 | tr -d '\r\n')
chk "cookie is a valid admin login on b" "$(curl -s -o /dev/null -w '%{http_code}' -H "Cookie: $ck" "$UB/wp-admin/")" "200"
r=$(sa --op=dev/echo --cookie="$ck"); chk "cookie: status" "${r%% *}" "200"; has "cookie: current user = 0" "$r" '"user":0'
printf '| %s | %s | %s |\n' "admin cookie of b in the request" "200" "— (current user 0)" >> "$table"
reset

# IP allowlist (emulated: the stand reads SPILNO_PEER_IPS from a file).
echo "192.0.2.1" > "$B/spilno-test-peer-ips"
refuse "IP not in the allowlist" 403 '403 ip op=ping ip=' -
router=$(snap | sed -nE 's/.*ip=([0-9.]+).*/\1/p')
echo "$router" > "$B/spilno-test-peer-ips"
r=$(sa); chk "IP in the allowlist ($router)" "${r%% *}" "200"
r=$(sa --direct); chk "other IP with a non-empty allowlist" "${r%% *}" "403"
rm -f "$B/spilno-test-peer-ips"
reset

# Rate limit: 60 / min per key; another address is not affected.
chk "rate: 60 accepted" "$(sa --repeat=60)" "200x60"
refuse "rate limit (61st in a minute)" 403 '403 rate' -
r=$(sa --direct); chk "rate: another address not limited" "${r%% *}" "200"
reset

# Block after 10 invalid signatures; another site id / address not blocked.
chk "block: 10 invalid signatures" "$(sa --bad-sig --repeat=10)" "401x10"
has "block: journal" "$(snap)" "signature, blocked"
refuse "blocked key, valid request" 403 '403 blocked' -
refuse "same address, other site id" 403 '\*zzz 403 site' - --site=zzz
r=$(sa --direct); chk "block: other address, partner site id" "${r%% *}" "200"
reset

# Nonce cleanup during acceptance, no cron.
wb spilno-test seed-nonces 300 1000 >/dev/null
read -r tot0 old0 <<<"$(wb spilno-test nonces | tail -1)"
chk "nonces: 15 accepted" "$(sa --repeat=15)" "200x15"
read -r tot1 old1 <<<"$(wb spilno-test nonces | tail -1)"
chk "nonces: stale rows purged (≤ 20 per request)" "$old1" "$(( old0 > 300 ? old0 - 300 : 0 ))"
chk "nonces: table = before − purged + 15" "$tot1" "$(( tot0 - old0 + old1 + 15 ))"
chk "nonces: shifted time accepted" "$(sa --shift=-250 --repeat=5)" "200x5"
read -r tot2 old2 <<<"$(wb spilno-test nonces | tail -1)"
chk "nonces: no stale rows left" "$old2" "0"
kept=$(wb db query "SELECT COUNT(*) FROM wp_spilno_nonces WHERE req_time BETWEEN UNIX_TIMESTAMP() - 280 AND UNIX_TIMESTAMP() - 220" --skip-column-names | grep . | tail -1 | tr -d '\r')
[ "${kept:-0}" -ge 5 ] && ok || bad "nonces: shifted-time nonces kept — $kept"
printf '| %s | %s | %s |\n' "nonces: 300 stale + 15 accepted, then 5 at −250 s" "200" "— (stale $old0 → $old1, total $tot0 → $tot1 → $tot2)" >> "$table"
wb cron event list --fields=hook --format=csv 2>/dev/null | grep -q spilno && bad "nonces: a cron event exists" || ok
reset

# Earlier than the redirects of a core.
chk "redirect emulation works (template_redirect)" "$(wb option update spilno_test_redirect template_redirect >/dev/null; curl -s -o /dev/null -w '%{http_code}' "$UB/")" "302"
for mode in init admin_init parse_request template_redirect; do
	wb option update spilno_test_redirect $mode >/dev/null
	r=$(sa); chk "endpoint before a $mode redirect" "${r%% *}" "200"
done
early=""
for mode in muplugins_loaded body; do
	wb option update spilno_test_redirect $mode >/dev/null
	r=$(sa); early="$early $mode=${r%% *}"
done
wb option delete spilno_test_redirect >/dev/null
echo "early redirects (recorded as is):$early"
printf '| %s | %s | %s |\n' "guest redirect in a must-use plugin (muplugins_loaded / file body)" "${early# }" "— (endpoint not reached)" >> "$table"
reset

# Switched off → 404, no journal.
wb option update spilno_test_disabled 1 >/dev/null
refuse "spilno_enabled = false" 404 - -
wb option delete spilno_test_disabled >/dev/null

# No constants: «not configured», endpoint absent, pages work.
touch "$A/spilno-test-no-link"
out=$(wa spilno ping --format=csv); code=$?
chk "no constants: ping exit code" "$code" "0"
echo "$out" | grep -qE "The link is not configured|Зв'язок не налаштовано" && ok || bad "no constants: not configured — $out"
r=$(sb); chk "no constants: endpoint of a → 404" "${r%% *}" "404"
page=$(curl -s -w '\n%{http_code}' "$UA/about/")
chk "no constants: page /about/ works" "$(echo "$page" | tail -1)" "200"
has "no constants: page rendered by spilno" "$page" 'class="spilno'
rm -f "$A/spilno-test-no-link"
r=$(sb); chk "constants back: endpoint of a" "${r%% *}" "200"

# Package: git archive of HEAD has no stand or harness files.
pkg=$(cd "$REPO" && git archive HEAD | tar -t)
echo "$pkg" | grep -qE '^(tests|docs|tools)/|stands\.sh|AGENTS|phpcs' && bad "package: $(echo "$pkg" | grep -E '^(tests|docs|tools)/|stands\.sh' | head -3)" || ok
echo "$pkg" | grep -q '^includes/sync/endpoint.php$' && ok || bad "package: includes/sync missing"

# PHP notices / warnings on both stands.
i=0; for S in "$A" "$B"; do
	new=$(tail -n +"$((logs[i]+1))" "$S/wp-content/debug.log" | grep -E 'PHP (Fatal|Warning|Notice|Deprecated)|Uncaught' | grep -v 'spilno_save() must not be called inside an open transaction' || true)
	[ -z "$new" ] && ok || bad "debug.log $(basename "$S"): $new"
	i=$((i+1))
done

echo "sync.sh: $pass/$((pass+fail)) passed"
[ $fail = 0 ]
