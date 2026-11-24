#!/usr/bin/env python3
"""Build the installable plugin ZIP using only Python's standard library."""
import argparse
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'f2-backup-local'
OUTPUT = ROOT / 'dist' / 'f2-backup-local.zip'


def version():
    header = (PLUGIN / 'f2-backup-local.php').read_text(encoding='utf-8')
    engine = (PLUGIN / 'engine.php').read_text(encoding='utf-8')
    a = re.search(r'^\s*\*?\s*Version:\s*([0-9.]+)\s*$', header, re.M)
    b = re.search(r"const VERSION\s*=\s*'([^']+)'", engine)
    if not a or not b or a[1] != b[1] or not re.fullmatch(r'\d+\.\d+\.\d+(?:\.\d+)?', a[1]):
        raise SystemExit('Plugin header and engine versions must match, e.g. 1.2.1.')
    return a[1]


def build():
    current = version()
    files = sorted(p for p in PLUGIN.rglob('*') if p.is_file())
    if any(p.is_symlink() for p in PLUGIN.rglob('*')):
        raise SystemExit('Symbolic links are not allowed in the release.')
    if any(p.name in {'wp-config.php', '.env', 'config.json', 'state.json', 'database.jsonl'} or p.suffix == '.sql' for p in files):
        raise SystemExit('Configuration or database files must not be packaged.')
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    # Preserve an already-verified ZIP when every packaged byte matches the source.
    expected = {p.relative_to(ROOT).as_posix(): p.read_bytes() for p in files}
    valid = False
    if OUTPUT.is_file():
        try:
            with zipfile.ZipFile(OUTPUT) as z:
                valid = len(z.namelist()) == len(expected) and set(z.namelist()) == set(expected) and z.testzip() is None and all(z.read(n) == data for n, data in expected.items())
        except (OSError, zipfile.BadZipFile):
            valid = False
    if not valid:
        with zipfile.ZipFile(OUTPUT, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for p in files:
                z.write(p, p.relative_to(ROOT).as_posix())
    with zipfile.ZipFile(OUTPUT) as z:
        if z.testzip() is not None:
            raise SystemExit('ZIP integrity validation failed.')
    print(f'F2 Bakup {current}: {OUTPUT.relative_to(ROOT)} ({OUTPUT.stat().st_size} bytes)')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--version-only', action='store_true')
    args = parser.parse_args()
    if args.version_only:
        print(version())
    else:
        build()
