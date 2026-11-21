"""Normalize the page root of the reference and of the stand for a diff.

Usage: python3 -I normalize.py <html file> <ref|stand>
Reference root: <div class="ds-backdrop-page"> … up to </main>; stand root:
<div class="spilno spilno--about"> … up to </main>. Normalized: class prefix
ds- -> x-, ids okl-about-/spilno-about- -> about-, hosts -> HOST, the root
wrapper class, SVG bodies, whitespace between tags; one tag per line.
"""
import re, sys

html = open(sys.argv[1], encoding='utf-8').read()
kind = sys.argv[2]
start = '<div class="ds-backdrop-page">' if kind == 'ref' else '<div class="spilno spilno--about">'
a = html.index(start)
b = html.index('</main>', a)
s = html[a:b]
s = s.replace(start, '<div class="ROOT">', 1)
s = re.sub(r'<svg\b[^>]*class="([^"]*)"[^>]*>.*?</svg>', lambda m: '<svg class="%s"/>' % m.group(1), s, flags=re.S)
s = re.sub(r'\b(okl|spilno)-about-', 'about-', s)
s = re.sub(r'\b(ds|spilno)-', 'x-', s)
s = re.sub(r'https://[a-z0-9.-]+\.ddev\.site:33001', 'HOST', s)
s = re.sub(r'>\s+<', '><', s)
s = re.sub(r'\s+', ' ', s)
s = s.replace('><', '>\n<')
print(s.strip())
