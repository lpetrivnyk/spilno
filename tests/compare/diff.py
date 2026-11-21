"""Pixel diff of the reference and the stand, and side-by-side images.

Usage: python3 -I diff.py <dir> <width> [<width> …]
Reads <dir>/okl-<w>.png and <dir>/stand-<w>.png, writes
<dir>/side-<w>.png (reference | stand | difference in red) and prints the
share of differing pixels and the bounding box of the differences.
"""
import sys
from PIL import Image, ImageChops

out = sys.argv[1]
for w in sys.argv[2:]:
    a = Image.open(f'{out}/okl-{w}.png').convert('RGB')
    b = Image.open(f'{out}/stand-{w}.png').convert('RGB')
    h = max(a.height, b.height)
    pa = Image.new('RGB', (a.width, h), 'white'); pa.paste(a)
    pb = Image.new('RGB', (b.width, h), 'white'); pb.paste(b)
    d = ImageChops.difference(pa, pb).convert('L').point(lambda v: 255 if v > 24 else 0)
    n = sum(1 for v in d.getdata() if v)
    box = d.getbbox()
    red = Image.new('RGB', pa.size, 'white'); red.paste((220, 0, 0), mask=d)
    side = Image.new('RGB', (pa.width * 3 + 40, h), (200, 200, 200))
    side.paste(pa, (0, 0)); side.paste(pb, (pa.width + 20, 0)); side.paste(red, (pa.width * 2 + 40, 0))
    side.save(f'{out}/side-{w}.png')
    print(f'{w}: {n} px differ ({100 * n / (pa.width * h):.3f} %), box {box}, sizes {a.size} {b.size}')
