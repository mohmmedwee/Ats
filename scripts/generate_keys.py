#!/usr/bin/env python3
"""Print fresh values for SECRET_KEY and ENCRYPTION_KEY.

Run once per deployment and store the output in your secret manager, never in
the repository.
"""

from __future__ import annotations

import secrets


def main() -> None:
    print(f"SECRET_KEY={secrets.token_urlsafe(48)}")
    print(f"ENCRYPTION_KEY={secrets.token_urlsafe(48)}")


if __name__ == "__main__":
    main()
