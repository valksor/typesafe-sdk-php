"""Read and validate the PHP SDK version and latest changelog entry."""

from __future__ import annotations

import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def version() -> str:
    """Return the SDK version after checking release metadata consistency."""
    source = (ROOT / "src/Client.php").read_text()
    match = re.search(r"public const VERSION = '(\d+\.\d+\.\d+)';", source)
    if match is None:
        raise ValueError("Could not find a semantic Client::VERSION constant")
    value = match[1]
    changelog = (ROOT / "docs/changelog.md").read_text()
    headings = re.findall(r"^## (v\d+\.\d+\.\d+) \(\d{4}-\d{2}-\d{2}\)$", changelog, re.MULTILINE)
    if not headings or headings[0] != f"v{value}":
        raise ValueError(f"Latest changelog entry must be v{value}")
    return value


if __name__ == "__main__":
    print(version())
