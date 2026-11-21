#!/usr/bin/env bash
# Stage-1 checks on the spilno-dev stand, one run. From the repository root:
#   tests/run.sh
# Needs the stand (ddev project spilno-dev) running.
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
STAND=${STAND:-$HOME/Documents/learn/spilno-dev}
URL=${URL:-https://spilno-dev.ddev.site:33001}
pass=0; fail=0
ok()  { pass=$((pass+1)); }
bad() { fail=$((fail+1)); echo "FAIL $*"; }
chk() { if [ "$2" = "$3" ]; then ok; else bad "$1 — got '$2', expected '$3'"; fi; }
wpx() { (cd "$STAND" && ddev wp "$@" 2>&1); }

# Static: php -l, phpcs.
lint=0; while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { bad "php -l $f"; lint=1; }; done < <(cd "$REPO" && find . -name '*.php' -not -path './vendor/*')
[ $lint = 0 ] && ok
(cd "$REPO" && vendor/bin/phpcs -q --report=summary >/dev/null) && ok || bad "phpcs"

# Harness into the stand (idempotent).
for f in adapter.php tests.php; do ln -sfn "$REPO/tests/dev-mu/$f" "$STAND/wp-content/mu-plugins/spilno-$f"; done
wpx plugin activate spilno >/dev/null
log="$STAND/wp-content/debug.log"; touch "$log"; log_from=$(wc -l < "$log")

# Migration from zero, then again.
wpx db query "DROP TABLE IF EXISTS wp_spilno_pages, wp_spilno_log" >/dev/null
wpx option delete spilno_db_version >/dev/null
chk "migration: from zero" "$(wpx eval 'echo get_option("spilno_db_version");')" "1.0.0"
chk "migration: tables" "$(wpx db query "SHOW TABLES LIKE 'wp_spilno_%'" --skip-column-names | grep . | sort | tr -d '\r' | tr '\n' ' ')" "wp_spilno_log wp_spilno_pages "
before=$(wpx db query "SHOW CREATE TABLE wp_spilno_pages; SHOW CREATE TABLE wp_spilno_log" --skip-column-names | md5sum)
chk "migration: current on the next request" "$(wpx eval 'echo _spilno_db_maybe_migrate();')" "current"
wpx option delete spilno_db_version >/dev/null
chk "migration: re-run over existing tables" "$(wpx eval 'echo get_option("spilno_db_version");')" "1.0.0"
chk "migration: re-run changes nothing" "$(wpx db query "SHOW CREATE TABLE wp_spilno_pages; SHOW CREATE TABLE wp_spilno_log" --skip-column-names | md5sum)" "$before"
chk "migration: busy under a held lock" "$(wpx eval 'global $wpdb; delete_option("spilno_db_version"); $c = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); $c->get_var("SELECT GET_LOCK(\"spilno_migrate_" . md5(DB_NAME . "|" . $wpdb->prefix) . "\", 0)"); echo _spilno_db_maybe_migrate(); $c->close();')" "busy"
chk "migration: done after the lock is gone" "$(wpx eval 'echo get_option("spilno_db_version");')" "1.0.0"

# PHP checks of the harness.
out=$(wpx spilno-test run); echo "$out" | grep -E '^(FAIL|spilno-test)'
echo "$out" | grep -q '^spilno-test: .* passed' && ! echo "$out" | grep -q '^FAIL' && ok || bad "spilno-test run"

# schema-hash: stable between processes.
h1=$(wpx spilno schema-hash test --format=csv); h2=$(wpx spilno schema-hash test --format=csv)
chk "schema-hash: stable" "$h1" "$h2"
echo "$h1" | grep -qE '^test,1,[0-9a-f]{64}$' && ok || bad "schema-hash format: $h1"

# doctor: no failures.
doc=$(wpx spilno doctor --format=csv); code=$?
chk "doctor: exit code" "$code" "0"
echo "$doc" | grep -q '^fail' && bad "doctor: $(echo "$doc" | grep '^fail')" || ok

# Lock: a save waits for the page lock and gives up after 5 s.
id=$(wpx post list --post_type=page --title='spilno-test record' --field=ID | tail -1)
name=$(wpx spilno-test lock-name "$id")
(cd "$STAND" && ddev exec "mysql -e \"SELECT GET_LOCK('$name', 0), SLEEP(9)\"" >/dev/null 2>&1) &
sleep 2
r=$(wpx spilno-test save "$id"); wait
case "$r" in "spilno_busy 5"*) ok ;; *) bad "lock: held → $r" ;; esac
chk "lock: free → saved" "$(wpx spilno-test save "$id" | cut -d' ' -f1)" "saved"

# Over HTTP: malformed form input is a field error, not a 500.
tmp=$(mktemp)
post() { curl -s -o "$tmp" -w '%{http_code}' -X POST "$URL/?spilno_test=parse" "$@"; }
chk "http: array instead of string → 200" "$(post --data-urlencode 'spilno[hero][title][]=x')" "200"
grep -q '"hero.title"' "$tmp" && ok || bad "http: error at hero.title"
chk "http: string instead of group → 200" "$(post --data-urlencode 'spilno[hero]=x')" "200"
grep -q '"hero":' "$tmp" && grep -q '"hero"' "$tmp" && ok || bad "http: error at hero"
chk "http: unknown field → 200" "$(post --data-urlencode 'spilno[zzz][a]=1')" "200"
grep -q '"zzz"' "$tmp" && ok || bad "http: error at zzz"
chk "http: no input → 200" "$(post -d 'x=1')" "200"
rm -f "$tmp"

# PHP notices / warnings / fatals during the run.
new=$(tail -n +"$((log_from+1))" "$log" | grep -E 'PHP (Fatal|Warning|Notice|Deprecated)|Uncaught' | grep -v 'spilno_save() must not be called inside an open transaction' || true)
[ -z "$new" ] && ok || bad "debug.log: $new"

echo "run.sh: $pass/$((pass+fail)) passed"
[ $fail = 0 ]
