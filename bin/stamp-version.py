#!/usr/bin/env python3
import json
import os
import sys

version = sys.argv[1] if len(sys.argv) > 1 else os.environ.get('VERSION', '').replace('refs/tags/v', '').replace('v', '')

with open('src-tauri/tauri.conf.json', 'r', encoding='utf-8') as f:
    config = json.load(f)
config['version'] = version
with open('src-tauri/tauri.conf.json', 'w', encoding='utf-8') as f:
    json.dump(config, f, indent=2)

print(f'Set version to: {version}')