"""تيليغرام Bot API."""
from __future__ import annotations

import logging

import httpx

log = logging.getLogger("darjabot.telegram")
API = "https://api.telegram.org"


def _call(token: str, method: str, payload: dict, timeout: float = 15) -> dict | None:
    if not token:
        return None
    try:
        r = httpx.post(f"{API}/bot{token}/{method}", json=payload, timeout=timeout)
        data = r.json()
        if not data.get("ok"):
            log.warning("telegram %s failed: %s", method, data.get("description"))
            return None
        return data
    except (httpx.HTTPError, ValueError) as e:
        log.warning("telegram %s error: %s", method, e)
        return None


def send_message(token: str, chat_id: str, text: str) -> bool:
    return _call(token, "sendMessage", {"chat_id": chat_id, "text": text[:4000]}) is not None


def set_webhook(token: str, url: str, secret: str) -> dict | None:
    return _call(token, "setWebhook", {"url": url, "secret_token": secret,
                                       "allowed_updates": ["message", "edited_message"]})


def delete_webhook(token: str) -> dict | None:
    return _call(token, "deleteWebhook", {})


def get_me(token: str) -> dict | None:
    data = _call(token, "getMe", {})
    return data.get("result") if data else None


def get_updates(token: str, offset: int | None, timeout: int = 25) -> list[dict]:
    payload = {"timeout": timeout, "allowed_updates": ["message", "edited_message"]}
    if offset is not None:
        payload["offset"] = offset
    data = _call(token, "getUpdates", payload, timeout=timeout + 10)
    return data.get("result", []) if data else []


def parse_update(update: dict) -> tuple[str, str, str] | None:
    """يرجع (chat_id، اسم الزبون، النص) ولا None إذا ماشي رسالة نصية."""
    msg = update.get("message") or update.get("edited_message")
    if not msg:
        return None
    chat = msg.get("chat") or {}
    if chat.get("type") not in (None, "private"):
        return None
    text = msg.get("text") or msg.get("caption")
    if not text:
        if msg.get("voice") or msg.get("audio"):
            text = "[رسالة صوتية]"
        elif msg.get("photo") or msg.get("sticker") or msg.get("document"):
            text = "[صورة/ملف]"
        else:
            return None
    frm = msg.get("from") or {}
    name = " ".join(x for x in (frm.get("first_name"), frm.get("last_name")) if x)
    return str(chat.get("id")), name, text
