#!/usr/bin/env python3
"""تشغيل بوتات تيليغرام بلا سيرفر عمومي (long polling).

مليح للتجربة على حاسوبك ولا على سيرفر بلا دومين/https.
كل محل عندو telegram_token في لوحة التحكم يخدم وحدو.

    python scripts/telegram_polling.py
"""
from __future__ import annotations

import logging
import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from darjabot import channels, db, engine  # noqa: E402
from darjabot.channels import telegram  # noqa: E402

log = logging.getLogger("telegram-polling")


def run_shop(shop_id: int, stop: threading.Event) -> None:
    shop = db.get_shop(shop_id)
    token = shop["telegram_token"]
    me = telegram.get_me(token)
    if not me:
        log.error("[%s] التوكن غالط", shop["slug"])
        return
    telegram.delete_webhook(token)
    log.info("[%s] البوت @%s راهو يخدم", shop["slug"], me.get("username"))
    offset = None
    while not stop.is_set():
        for update in telegram.get_updates(token, offset):
            offset = update["update_id"] + 1
            parsed = telegram.parse_update(update)
            if not parsed:
                continue
            chat_id, name, text = parsed
            if text.strip() == "/myid":
                telegram.send_message(token, chat_id, f"رقم المحادثة تاعك (chat id): {chat_id}")
                continue
            if text.strip() == "/start":
                text = "سلام"
            shop = db.get_shop(shop_id) or shop  # المعلومات الجديدة من لوحة التحكم
            try:
                r = engine.handle_message(shop, "telegram", chat_id, text, customer_name=name)
                if r.text:
                    channels.send(shop, r.conversation, r.text)
                log.info("[%s] %s: %s -> %s", shop["slug"], name, text[:60], (r.text or "—")[:60])
            except Exception:
                log.exception("خطأ في معالجة الرسالة")
        time.sleep(0.2)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    shops = [s for s in db.list_shops() if s.get("telegram_token")]
    if not shops:
        print("ما كاين حتى محل عندو توكن تيليغرام. حطو من لوحة التحكم ← القنوات.")
        return
    stop = threading.Event()
    threads = [threading.Thread(target=run_shop, args=(s["id"], stop), daemon=True) for s in shops]
    for t in threads:
        t.start()
    try:
        while any(t.is_alive() for t in threads):
            time.sleep(1)
    except KeyboardInterrupt:
        stop.set()
        print("\nتوقف.")


if __name__ == "__main__":
    main()
