"""دردشة في الترمينال باش تجرب البوت دغيا:

    python -m darjabot.cli                     # يختار أول محل
    python -m darjabot.cli boutique-leila
"""
from __future__ import annotations

import sys
import uuid

from . import db, engine
from .demo import seed


def main() -> None:
    if not db.list_shops():
        seed()
    slug = sys.argv[1] if len(sys.argv) > 1 else db.list_shops()[0]["slug"]
    shop = db.get_shop_by("slug", slug)
    if not shop:
        print("المحلات:", ", ".join(s["slug"] for s in db.list_shops()))
        sys.exit(1)
    engine.set_notifier(lambda s, t: print(f"   🔔 تنبيه لصاحب المحل: {t.splitlines()[0]}"))
    user = "cli-" + uuid.uuid4().hex[:8]
    print(f"🛍️  {shop['name']} — اكتب رسالتك (exit باش تخرج)\n")
    while True:
        try:
            text = input("👤 ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            break
        if text.lower() in {"exit", "quit", "q"}:
            break
        if not text:
            continue
        r = engine.handle_message(shop, "test", user, text)
        print(f"🤖 {r.text or '(البوت ساكت: المحادثة عند بشري)'}   [{r.provider}]\n")


if __name__ == "__main__":
    main()
