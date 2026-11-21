"""Visual and keyboard checks of the about page on the stand vs the reference.

Usage (from the repository root, stands running):
    python3 -I tests/compare/visual.py <out-dir>

Phases (one headless Chrome run each, tests/compare/shots.mjs):
  1. reference vs stand, default theme, 1440 / 1200 / 768 / 320, reduced
     motion: root screenshots, pixel diff, side-by-side images (diff.py);
  2. stand matrix: 4 themes x text 100 / 125 / 150 % x 4 widths —
     no horizontal scroll, nothing clipped, nothing outside the root;
  3. keyboard order and visible focus (1440, 320), forced-colors (1440, 320),
     motion (system setting, the site switch, default);
  4. slots: data empty (as on the second site) and all empty, 4 widths.
Prints FAIL lines and a total; exit code 1 on a failure.
"""
import json
import os
import subprocess
import sys

OUT = sys.argv[1]
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
STAND_DIR = os.path.expanduser('~/Documents/learn/spilno-dev')
STAND = 'https://spilno-dev.ddev.site:33001/about/'
REF = 'https://okl.ddev.site:33001/about/'
WIDTHS = (1440, 1200, 768, 320)
REDUCE = [{'name': 'prefers-reduced-motion', 'value': 'reduce'}]

os.makedirs(OUT, exist_ok=True)
passed, failed = 0, []


def check(name, ok, detail=''):
    global passed
    if ok:
        passed += 1
    else:
        failed.append(name + (' — ' + detail if detail else ''))


def run(name, jobs):
    path = os.path.join(OUT, 'jobs-' + name + '.json')
    with open(path, 'w') as f:
        json.dump(jobs, f)
    out = subprocess.run(['node', os.path.join(HERE, 'shots.mjs'), OUT, path], capture_output=True, text=True, timeout=900)
    if out.returncode:
        check('chrome run ' + name, False, out.stderr[-300:])
        return {}
    return {r['name']: r for r in map(json.loads, out.stdout.split('\n')[:-1])}


def slots(mode):
    cmd = ['ddev', 'wp', 'option', 'update', 'spilno_test_slots', mode] if mode else ['ddev', 'wp', 'option', 'delete', 'spilno_test_slots']
    subprocess.run(cmd, cwd=STAND_DIR, capture_output=True)


def overflow_ok(name, r):
    o = r.get('overflow') or {}
    check(name + ': no horizontal scroll', o.get('hscroll') is False, str(o.get('docWidth')))
    check(name + ': nothing clipped', o.get('clipped') == [], str(o.get('clipped'))[:200])
    check(name + ': nothing outside the root', o.get('outside') == [], str(o.get('outside'))[:200])


slots('')

# 1. Reference vs stand.
jobs = []
for w in WIDTHS:
    jobs.append(dict(name=f'okl-{w}', url=REF, root='.ds-backdrop-page', width=w, media=REDUCE, shot=True))
    jobs.append(dict(name=f'stand-{w}', url=STAND, root='.spilno', width=w, media=REDUCE, shot=True))
res = run('compare', jobs)
diff = subprocess.run(['python3', '-I', os.path.join(HERE, 'diff.py'), OUT] + [str(w) for w in WIDTHS], capture_output=True, text=True)
print(diff.stdout.strip())
for line in diff.stdout.strip().split('\n'):
    w, rest = line.split(':', 1)
    share = float(rest.split('(')[1].split(' %')[0])
    check(f'compare {w}: same height', res.get(f'okl-{w}', {}).get('height') == res.get(f'stand-{w}', {}).get('height'))
    check(f'compare {w}: < 0.01 % pixels differ', share < 0.01, rest.strip())

# 2. Matrix.
jobs = []
for theme in ('normal', 'high', 'dark', 'mono'):
    for scale in ('100', '125', '150'):
        for w in WIDTHS:
            cookies = {}
            if theme != 'normal':
                cookies['ds_theme'] = theme
            if scale != '100':
                cookies['ds_text_scale'] = scale
            jobs.append(dict(name=f'm-{theme}-{scale}-{w}', url=STAND, root='.spilno', width=w, cookies=cookies, media=REDUCE, shot=True, checks=['overflow']))
for name, r in run('matrix', jobs).items():
    overflow_ok(name, r)
check('matrix: 48 states', len([j for j in jobs]) == 48)

# 3. Keyboard, forced colours, motion, underlined links.
jobs = [
    dict(name='keys-1440', url=STAND, root='.spilno', width=1440, media=REDUCE, checks=['keys']),
    dict(name='keys-320', url=STAND, root='.spilno', width=320, media=REDUCE, checks=['keys']),
    dict(name='keys-ref-1440', url=REF, root='.ds-backdrop-page', width=1440, media=REDUCE, checks=['keys']),
    dict(name='forced-1440', url=STAND, root='.spilno', width=1440, media=REDUCE + [{'name': 'forced-colors', 'value': 'active'}], shot=True, checks=['overflow']),
    dict(name='forced-320', url=STAND, root='.spilno', width=320, media=REDUCE + [{'name': 'forced-colors', 'value': 'active'}], shot=True, checks=['overflow']),
    dict(name='motion-default', url=STAND, root='.spilno', width=1440, checks=['motion']),
    dict(name='motion-system', url=STAND, root='.spilno', width=1440, media=REDUCE, checks=['motion']),
    dict(name='motion-switch', url=STAND, root='.spilno', width=1440, cookies={'ds_motion': 'reduce'}, checks=['motion']),
    dict(name='links-underline-1440', url=STAND, root='.spilno', width=1440, cookies={'ds_links': 'underline'}, media=REDUCE, shot=True, checks=['overflow']),
]
res = run('a11y', jobs)
for w in ('1440', '320'):
    keys = res.get('keys-' + w, {}).get('keys', [])
    check(f'keys {w}: 21 stops in the root', len(keys) == 21, str(len(keys)))
    check(f'keys {w}: every stop has a visible focus ring', all(k['ring'] and k['visible'] for k in keys), str([k['text'] for k in keys if not (k['ring'] and k['visible'])]))
ref_keys = [(k['text'], k['href'].replace('okl.ddev.site', 'X').replace('spilno-dev.ddev.site', 'X')) for k in res.get('keys-ref-1440', {}).get('keys', [])]
own_keys = [(k['text'], k['href'].replace('okl.ddev.site', 'X').replace('spilno-dev.ddev.site', 'X')) for k in res.get('keys-1440', {}).get('keys', [])]
check('keys: same order as the reference', ref_keys == own_keys, str(len(ref_keys)) + '/' + str(len(own_keys)))
for name in ('forced-1440', 'forced-320', 'links-underline-1440'):
    overflow_ok(name, res.get(name, {}))
check('motion: animated by default', res.get('motion-default', {}).get('motion') == '4s', str(res.get('motion-default')))
check('motion: system reduce', res.get('motion-system', {}).get('motion') in ('1e-05s', '0.00001s'), str(res.get('motion-system')))
check('motion: site switch reduce', res.get('motion-switch', {}).get('motion') in ('1e-05s', '0.00001s'), str(res.get('motion-switch')))

# 4. Slots.
for mode in ('data-empty', 'all-empty'):
    slots(mode)
    jobs = [dict(name=f'slots-{mode}-{w}', url=STAND, root='.spilno', width=w, media=REDUCE, shot=True, checks=['overflow']) for w in WIDTHS]
    for name, r in run('slots-' + mode, jobs).items():
        overflow_ok(name, r)
slots('')

for f in failed:
    print('FAIL ' + f)
print(f'visual: {passed}/{passed + len(failed)} passed')
sys.exit(1 if failed else 0)
