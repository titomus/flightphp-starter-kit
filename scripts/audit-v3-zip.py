#!/usr/bin/env python3
"""Read-only ZIP weight audit: compressed and uncompressed bytes per component."""
import collections
import sys
import zipfile

if len(sys.argv) != 2:
    raise SystemExit("Usage: python3 scripts/audit-v3-zip.py path/to/archive.zip")
totals = collections.defaultdict(lambda: [0, 0, 0])
with zipfile.ZipFile(sys.argv[1]) as archive:
    for item in archive.infolist():
        if item.is_dir():
            continue
        name = item.filename.removeprefix(".flight-package/")
        parts = name.split("/")
        if parts[:2] == ["runtime", "vendor"]:
            group = "/".join(parts[:4])
        elif parts[:2] == ["runtime", "build"]:
            group = "/".join(parts[:3])
        else:
            group = parts[0]
        row = totals[group]
        row[0] += item.compress_size
        row[1] += item.file_size
        row[2] += 1
print(f"{'Component':65} {'ZIP MiB':>9} {'Raw MiB':>9} {'Files':>7}")
for name, (packed, raw, count) in sorted(totals.items(), key=lambda pair: -pair[1][0]):
    print(f"{name[:65]:65} {packed / 1048576:9.2f} {raw / 1048576:9.2f} {count:7}")
