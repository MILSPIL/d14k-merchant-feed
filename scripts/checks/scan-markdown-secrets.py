#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import re
import sys


ROOT = pathlib.Path(__file__).resolve().parents[2]

IGNORE_DIRS = {
    ".git",
    "releases",
}

PATTERNS = [
    (
        "cloudflare_token",
        re.compile(r"cfut_[A-Za-z0-9]{20,}"),
    ),
    (
        "raw_bearer_token",
        re.compile(r"Authorization:\s*Bearer\s+(?!\$\()([A-Za-z0-9._-]{20,})", re.IGNORECASE),
    ),
    (
        "labeled_secret_token",
        re.compile(
            r"(?i)\b(token|access token|refresh token|developer token|prom_api_token)\b[^\n]{0,30}[:=]\s*[\"'`]?([A-Za-z0-9._-]{20,})"
        ),
    ),
]

ALLOW_SNIPPETS = (
    "{token}",
    "stored in keychain",
    "keychain item",
    "$(security ",
)


def should_skip(path: pathlib.Path) -> bool:
    return any(part in IGNORE_DIRS for part in path.parts)


def main() -> int:
    findings: list[str] = []

    for path in ROOT.rglob("*.md"):
        if should_skip(path):
            continue

        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            text = path.read_text(encoding="utf-8", errors="ignore")

        for lineno, line in enumerate(text.splitlines(), start=1):
            lowered = line.lower()
            if any(snippet in lowered for snippet in ALLOW_SNIPPETS):
                continue

            for name, pattern in PATTERNS:
                if pattern.search(line):
                    rel = path.relative_to(ROOT)
                    findings.append(f"{rel}:{lineno}: {name}: {line.strip()}")

    if findings:
        print("Markdown secret scan failed.")
        for item in findings:
            print(item)
        return 1

    print("OK  markdown secret scan")
    return 0


if __name__ == "__main__":
    sys.exit(main())
