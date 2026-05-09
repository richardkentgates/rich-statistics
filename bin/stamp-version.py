#!/usr/bin/env python3
import json
import os
import re
import sys
from pathlib import Path

version = sys.argv[1] if len(sys.argv) > 1 else os.environ.get('VERSION', '').replace('refs/tags/v', '').replace('v', '')

# Update tauri.conf.json
config_path = Path('src-tauri') / 'tauri.conf.json'
with open(config_path, 'r', encoding='utf-8') as f:
    config = json.load(f)
config['version'] = version
with open(config_path, 'w', encoding='utf-8') as f:
    json.dump(config, f, indent=2)
print(f'Set tauri.conf.json version to: {version}')

# Update Cargo.toml
cargo_path = Path('src-tauri') / 'Cargo.toml'
with open(cargo_path, 'r', encoding='utf-8') as f:
    content = f.read()
content = re.sub(r'^version = "[^"]*"', f'version = "{version}"', content, flags=re.MULTILINE)
with open(cargo_path, 'w', encoding='utf-8') as f:
    f.write(content)
print(f'Set Cargo.toml version to: {version}')