#!/usr/bin/env python3
"""REQ-MAKE-004 / REQ-I18N-002: key parity across NowoMarketingKitBundle locales."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1] / "src/Resources/translations"
LOCALES = ["en", "es", "it", "fr", "pt", "de", "nl"]


def keys(path: Path) -> set[str]:
    out = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        m = re.match(r"^([A-Za-z0-9_.]+)\s*:", line)
        if m:
            out.add(m.group(1))
    return out


def main() -> int:
    base = ROOT / "NowoMarketingKitBundle.en.yaml"
    if not base.exists():
        print(f"Missing {base}", file=sys.stderr)
        return 1
    base_keys = keys(base)
    ok = True
    for loc in LOCALES:
        path = ROOT / f"NowoMarketingKitBundle.{loc}.yaml"
        if not path.exists():
            print(f"Missing {path}", file=sys.stderr)
            ok = False
            continue
        data = keys(path)
        missing = sorted(base_keys - data)
        extra = sorted(data - base_keys)
        if missing or extra:
            print(f"Parity fail {loc}: missing={missing} extra={extra}", file=sys.stderr)
            ok = False
    if ok:
        print("OK translation parity for " + "/".join(LOCALES))
        return 0
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
