#!/usr/bin/env bash
# Two stands for synchronisation (stage 4): spilno-dev (site a) and
# spilno-dev-b (site b). Idempotent; from the repository root:
#   tests/stands.sh
#
# - stand b is created as a copy of stand a's WordPress core (no network),
#   its own ddev project and salts;
# - both get the same bind mount of this repository, the harness
#   mu-plugins and the test theme shell;
# - both get the link constants of plan §E1 between markers in wp-config.php.
#   The pair's secrets are generated once and are the same on both stands;
#   they live only in the stands' wp-config.php, never in the repository.
#
# Harness switches in each stand's root (files, not constants, so a test can
# flip them between requests):
#   spilno-test-no-link     present → no link constants (not configured);
#   spilno-test-peer-ips    its content is SPILNO_PEER_IPS (absent → empty).
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
LEARN=${LEARN:-$HOME/Documents/learn}
A=$LEARN/spilno-dev
B=$LEARN/spilno-dev-b
PORT=33001

if [ ! -d "$B" ]; then
	mkdir "$B"
	rsync -a --exclude /.ddev --exclude /wp-content/debug.log --exclude /wp-content/uploads \
		--exclude '/wp-content/mu-plugins/spilno-*' --exclude /wp-content/plugins/spilno \
		--exclude /wp-content/themes/spilno-dev-shell --exclude /wp-content/upgrade "$A/" "$B/"
	mkdir -p "$B/.ddev"
	cp "$A/.ddev/docker-compose.spilno.yaml" "$B/.ddev/"
	(cd "$B" && ddev config --project-name=spilno-dev-b --project-type=wordpress --docroot=. \
		--php-version=8.4 --webserver-type=nginx-fpm --database=mariadb:11.8 --nodejs-version=24 \
		--xdebug-enabled=false >/dev/null)
	php -r '$f=$argv[1];$c=file_get_contents($f);$c=preg_replace_callback("/define\( \x27(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)\x27, \x27[^\x27]+\x27 \);/",function($m){return "define( \x27".$m[1]."\x27, \x27".bin2hex(random_bytes(32))."\x27 );";},$c);file_put_contents($f,$c);' "$B/wp-config.php"
	(cd "$B" && ddev start >/dev/null && ddev wp core install --url="https://spilno-dev-b.ddev.site:$PORT" \
		--title=spilno-dev-b --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null \
		&& ddev wp rewrite structure '/%postname%/' >/dev/null)
fi

# Harness and theme shell into both stands.
for S in "$A" "$B"; do
	ln -sfn "$REPO" "$S/wp-content/plugins/spilno"
	for f in "$REPO"/tests/dev-mu/*.php; do ln -sfn "$f" "$S/wp-content/mu-plugins/spilno-$(basename "$f")"; done
	ln -sfn "$REPO/tests/dev-theme" "$S/wp-content/themes/spilno-dev-shell"
done

# Link constants (plan §E1), once.
if ! grep -q 'spilno-link:begin' "$A/wp-config.php"; then
	secret=$(php -r 'echo bin2hex(random_bytes(32));')
	previous=$(php -r 'echo bin2hex(random_bytes(32));')
	block() { # site, peer, peer host
		cat <<-PHP
		// spilno-link:begin (tests/stands.sh)
		if ( ! is_file( __DIR__ . '/spilno-test-no-link' ) ) {
			define( 'SPILNO_SITE_ID', '$1' );
			define( 'SPILNO_PEER_SITE_ID', '$2' );
			define( 'SPILNO_PEER_URL', 'https://$3:$PORT' );
			define( 'SPILNO_PEER_IPS', is_readable( __DIR__ . '/spilno-test-peer-ips' ) ? trim( (string) file_get_contents( __DIR__ . '/spilno-test-peer-ips' ) ) : '' );
			define( 'SPILNO_SECRET', '$secret' );
			define( 'SPILNO_SECRET_PREVIOUS', '$previous' );
		}
		// spilno-link:end
		PHP
	}
	for pair in "$A:a:b:spilno-dev-b.ddev.site" "$B:b:a:spilno-dev.ddev.site"; do
		IFS=: read -r S site peer host <<<"$pair"
		block "$site" "$peer" "$host" > "$S/.spilno-link.tmp"
		# Before the line that loads wp-settings.php.
		php -r '$f=$argv[1];$c=file_get_contents($f);$b=file_get_contents($argv[2]);$p=strpos($c,"/** Include wp-settings.php");if(false===$p){$p=strpos($c,"if ( file_exists( ABSPATH . \x27/wp-settings.php\x27 ) )");}if(false===$p){fwrite(STDERR,"no anchor in $f\n");exit(1);}file_put_contents($f,substr($c,0,$p).$b."\n".substr($c,$p));' "$S/wp-config.php" "$S/.spilno-link.tmp"
		rm -f "$S/.spilno-link.tmp"
	done
fi

for S in "$A" "$B"; do
	(cd "$S" && ddev wp plugin activate spilno >/dev/null && ddev wp theme activate spilno-dev-shell >/dev/null)
done
echo "stands: a $A, b $B"
