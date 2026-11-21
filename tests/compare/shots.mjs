// Headless Chrome over the DevTools protocol (no npm packages): screenshots
// and layout / keyboard checks of the stand and the reference.
//
//   node tests/compare/shots.mjs <out-dir> <jobs.json>
//
// jobs.json — a list of jobs:
//   { name, url, root (CSS selector), width, cookies: {name: value},
//     media: [{name, value}] (prefers-reduced-motion, forced-colors),
//     shot: true|false, checks: ["overflow", "keys", "motion"] }
// Prints one JSON line per job with the results; screenshots go to
// <out-dir>/<name>.png (the root element only).
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [ outDir, jobsFile ] = process.argv.slice( 2 );
const jobs = JSON.parse( readFileSync( jobsFile, 'utf8' ) );
mkdirSync( outDir, { recursive: true } );

const port = 9333 + Math.floor( Math.random() * 500 );
const chrome = spawn( 'google-chrome', [
	'--headless=new', `--remote-debugging-port=${ port }`, '--no-first-run', '--no-default-browser-check',
	`--user-data-dir=${ mkdtempSync( join( tmpdir(), 'spilno-chrome-' ) ) }`, '--ignore-certificate-errors',
	'--hide-scrollbars', '--force-color-profile=srgb', 'about:blank',
], { stdio: 'ignore' } );

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
let target;
for ( let i = 0; i < 50 && ! target; i++ ) {
	await sleep( 200 );
	try {
		const list = await ( await fetch( `http://127.0.0.1:${ port }/json/list` ) ).json();
		target = list.find( ( t ) => 'page' === t.type );
	} catch {}
}
if ( ! target ) {
	console.error( 'chrome did not start' );
	process.exit( 2 );
}

const ws = new WebSocket( target.webSocketDebuggerUrl );
await new Promise( ( r ) => ws.addEventListener( 'open', r, { once: true } ) );
let seq = 0;
const pending = new Map();
const waiters = [];
ws.addEventListener( 'message', ( e ) => {
	const msg = JSON.parse( e.data );
	if ( msg.id && pending.has( msg.id ) ) {
		const { resolve, reject } = pending.get( msg.id );
		pending.delete( msg.id );
		msg.error ? reject( new Error( JSON.stringify( msg.error ) ) ) : resolve( msg.result );
	} else if ( msg.method ) {
		for ( const w of waiters.splice( 0 ) ) {
			w( msg );
		}
	}
} );
const send = ( method, params = {} ) => new Promise( ( resolve, reject ) => {
	const id = ++seq;
	pending.set( id, { resolve, reject } );
	ws.send( JSON.stringify( { id, method, params } ) );
} );
const evaluate = async ( expression ) => {
	const r = await send( 'Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true } );
	if ( r.exceptionDetails ) {
		throw new Error( r.exceptionDetails.text + ' ' + ( r.exceptionDetails.exception?.description || '' ) );
	}
	return r.result.value;
};

await send( 'Page.enable' );
await send( 'Network.enable' );
await send( 'Emulation.setFocusEmulationEnabled', { enabled: true } );

const loaded = () => new Promise( ( resolve ) => {
	const check = ( msg ) => ( 'Page.loadEventFired' === msg.method ? resolve() : waiters.push( check ) );
	waiters.push( check );
} );

for ( const job of jobs ) {
	const result = { name: job.name };
	await send( 'Network.clearBrowserCookies' );
	for ( const [ name, value ] of Object.entries( job.cookies || {} ) ) {
		await send( 'Network.setCookie', { name, value, url: job.url } );
	}
	await send( 'Emulation.setEmulatedMedia', { features: job.media || [] } );
	await send( 'Emulation.setDeviceMetricsOverride', { width: job.width, height: 900, deviceScaleFactor: 1, mobile: false } );
	const done = loaded();
	await send( 'Page.navigate', { url: job.url } );
	await done;
	await evaluate( 'document.fonts.ready.then(() => true)' );
	// Two frames, so the surface has the new size before a capture.
	await evaluate( 'new Promise(r => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))' );
	await sleep( 300 );

	const checks = job.checks || [];

	if ( checks.includes( 'overflow' ) ) {
		result.overflow = await evaluate( `(() => {
			const root = document.querySelector(${ JSON.stringify( job.root ) });
			const doc = document.documentElement;
			const out = { hscroll: doc.scrollWidth > doc.clientWidth, docWidth: doc.scrollWidth, clipped: [], outside: [] };
			const right = root.getBoundingClientRect().right + 0.5;
			for (const el of root.querySelectorAll('*')) {
				const r = el.getBoundingClientRect();
				if (r.width === 0 && r.height === 0) continue;
				const cs = getComputedStyle(el);
				if (r.right > right && cs.position !== 'absolute') out.outside.push(el.className.baseVal ?? el.className);
				const hasText = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim());
				if (hasText && (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1) && (cs.overflow !== 'visible' || cs.textOverflow === 'ellipsis')) out.clipped.push(el.className);
			}
			return out;
		})()` );
	}

	if ( checks.includes( 'motion' ) ) {
		result.motion = await evaluate( `(() => {
			const el = document.querySelector('.spilno-photo-band__media, .ds-photo-band__media');
			return el ? getComputedStyle(el).animationDuration : null;
		})()` );
	}

	if ( checks.includes( 'keys' ) ) {
		await evaluate( 'document.activeElement && document.activeElement.blur(), window.scrollTo(0,0), true' );
		const stops = [];
		for ( let i = 0; i < 60; i++ ) {
			await send( 'Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 } );
			await send( 'Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 } );
			const stop = await evaluate( `(() => {
				const el = document.activeElement;
				const root = document.querySelector(${ JSON.stringify( job.root ) });
				if (!el || el === document.body) return null;
				if (!el.hasAttribute('data-stop')) el.setAttribute('data-stop', String(document.querySelectorAll('[data-stop]').length));
				return { inRoot: root.contains(el), text: (el.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 60), href: el.getAttribute('href') || '', visible: el.matches(':focus-visible') };
			})()` );
			if ( ! stop ) {
				continue;
			}
			if ( stops.length && stops[ 0 ].text === stop.text && stops[ 0 ].href === stop.href ) {
				break;
			}
			stops.push( stop );
		}
		// A visible focus ring = the pixels around the element differ between
		// focused and not focused (the ring of a card or tile link is drawn
		// around the whole card).
		for ( let i = 0; i < stops.length; i++ ) {
			const clip = await evaluate( `(() => {
				const el = document.querySelector('[data-stop="${ i }"]');
				el.scrollIntoView({ block: 'center' });
				const box = (el.closest('.spilno-news-card, .spilno-tile, .ds-news-card, .ds-tile') || el).getBoundingClientRect();
				return { x: Math.max(0, box.left + scrollX - 8), y: Math.max(0, box.top + scrollY - 8), width: box.width + 16, height: box.height + 16, scale: 1 };
			})()` );
			await evaluate( `(document.activeElement && document.activeElement.blur(), true)` );
			const off = ( await send( 'Page.captureScreenshot', { format: 'png', clip } ) ).data;
			await evaluate( `(document.querySelector('[data-stop="${ i }"]').focus({ focusVisible: true }), true)` );
			await sleep( 50 );
			const on = ( await send( 'Page.captureScreenshot', { format: 'png', clip } ) ).data;
			stops[ i ].ring = off !== on;
		}
		result.keys = stops.filter( ( s ) => s.inRoot );
	}

	if ( job.shot ) {
		// The whole document in the viewport (no captureBeyondViewport: it
		// re-lays the page out and gives unstable captures).
		const full = await evaluate( 'Math.ceil(document.documentElement.scrollHeight)' );
		await send( 'Emulation.setDeviceMetricsOverride', { width: job.width, height: full, deviceScaleFactor: 1, mobile: false } );
		await evaluate( 'new Promise(r => requestAnimationFrame(() => requestAnimationFrame(() => r(true))))' );
		await sleep( 300 );
		const rect = await evaluate( `(() => { window.scrollTo(0, 0); const r = document.querySelector(${ JSON.stringify( job.root ) }).getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) }; })()` );
		const shot = await send( 'Page.captureScreenshot', { format: 'png', clip: { ...rect, scale: 1 } } );
		writeFileSync( join( outDir, job.name + '.png' ), Buffer.from( shot.data, 'base64' ) );
		result.height = Math.round( rect.height );
	}

	console.log( JSON.stringify( result ) );
}

ws.close();
chrome.kill();
