#!/usr/bin/env python3
"""Generate update.json for Tauri updater from dist directory contents.

Usage: gen-update-json.py <dist-dir> <host> <version> [preferred-suffix]

Prefers binaries matching preferred-suffix (e.g. "(Dev)") when multiple
candidates exist for the same platform.
"""
import json
import pathlib
import sys


def main():
    if len(sys.argv) < 4:
        print("Usage: gen-update-json.py <dist-dir> <host> <version> [preferred-suffix]", file=sys.stderr)
        sys.exit(1)

    dist = pathlib.Path(sys.argv[1])
    host = sys.argv[2]
    version = sys.argv[3]
    preferred = sys.argv[4] if len(sys.argv) > 4 else ""
    base_url = f"https://{host}/dist"

    pattern_map = {
        "linux-amd64": "linux-x86_64",
        "linux-arm64": "linux-arm64",
        "windows": "windows-x86_64",
    }

    platforms = {}
    for pattern, platform_key in pattern_map.items():
        sig_file = None
        candidates = []
        for f in sorted(dist.iterdir()):
            if f.is_file() and pattern in f.name:
                if f.suffix == ".sig":
                    sig_file = f
                elif f.suffix in (".deb", ".exe"):
                    candidates.append(f)

        if preferred and candidates:
            binary_file = next((c for c in candidates if preferred in c.name), candidates[-1])
        elif candidates:
            binary_file = candidates[-1]
        else:
            continue

        signature = ""
        if sig_file is not None:
            signature = sig_file.read_text().strip()

        platforms[platform_key] = {
            "url": f"{base_url}/{binary_file.name}",
            "signature": signature,
        }

    update = {
        "version": version,
        "pub_date": "2026-01-01T00:00:00Z",
        "notes": "",
        "platforms": platforms,
    }

    update_file = dist / "update.json"
    update_file.write_text(json.dumps(update, indent=2))
    print(f"Generated {update_file} with {len(platforms)} platform(s)")
    for pk, pv in platforms.items():
        sig_status = "signed" if pv["signature"] else "unsigned"
        print(f"  {pk}: {pv['url']} ({sig_status})")


if __name__ == "__main__":
    main()
