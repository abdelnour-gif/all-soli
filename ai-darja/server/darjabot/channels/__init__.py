"""قنوات التواصل: تيليغرام، واتساب، ماسنجر/إنستغرام، وويدجت الموقع."""
from __future__ import annotations

import logging

from . import meta, telegram

log = logging.getLogger("darjabot.channels")

CHANNELS = ("web", "telegram", "whatsapp", "messenger", "instagram", "test")


def send(shop: dict, conversation: dict, text: str) -> bool:
    """يبعث رسالة للزبون على نفس القناة اللي جا منها. "web" و"test" ما يحتاجوش (الويدجت يقرا وحدو)."""
    ch = conversation["channel"]
    to = conversation["external_id"]
    try:
        if ch == "telegram":
            return telegram.send_message(shop.get("telegram_token", ""), to, text)
        if ch == "whatsapp":
            return meta.send_whatsapp(shop.get("wa_phone_number_id", ""), shop.get("wa_token", ""), to, text)
        if ch in ("messenger", "instagram"):
            return meta.send_messenger(shop.get("fb_page_token", ""), to, text)
    except Exception:  # لا نطيحو السيرفر بسبب قناة
        log.exception("send failed on %s", ch)
        return False
    return True
