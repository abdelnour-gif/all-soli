"""واتساب (WhatsApp Cloud API) وماسنجر/إنستغرام (Graph API) تاع Meta."""
from __future__ import annotations

import hashlib
import hmac
import logging

import httpx

from ..media import FILE, VOICE

log = logging.getLogger("darjabot.meta")
GRAPH = "https://graph.facebook.com/v21.0"


def verify_signature(app_secret: str, body: bytes, header: str | None) -> bool:
    """يتأكد بلي الطلب جاي من Meta (X-Hub-Signature-256). إذا ما كانش سر، نقبلو (وضع التطوير)."""
    if not app_secret:
        return True
    if not header or not header.startswith("sha256="):
        return False
    expected = hmac.new(app_secret.encode(), body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header.split("=", 1)[1])


def _post(url: str, token: str, payload: dict) -> bool:
    if not token:
        return False
    try:
        r = httpx.post(url, json=payload, headers={"Authorization": f"Bearer {token}"}, timeout=15)
        if r.status_code >= 400:
            log.warning("meta send failed %s: %s", r.status_code, r.text[:300])
            return False
        return True
    except httpx.HTTPError as e:
        log.warning("meta send error: %s", e)
        return False


def send_whatsapp(phone_number_id: str, token: str, to: str, text: str) -> bool:
    if not phone_number_id:
        return False
    return _post(f"{GRAPH}/{phone_number_id}/messages", token, {
        "messaging_product": "whatsapp", "recipient_type": "individual", "to": to,
        "type": "text", "text": {"preview_url": False, "body": text[:4000]},
    })


def send_messenger(page_token: str, recipient_id: str, text: str) -> bool:
    return _post(f"{GRAPH}/me/messages", page_token, {
        "recipient": {"id": recipient_id}, "messaging_type": "RESPONSE", "message": {"text": text[:2000]},
    })


def parse_whatsapp(payload: dict) -> list[dict]:
    """يرجع قائمة {phone_number_id, from, name, text}."""
    out = []
    for entry in payload.get("entry", []):
        for change in entry.get("changes", []):
            value = change.get("value") or {}
            pnid = (value.get("metadata") or {}).get("phone_number_id", "")
            names = {c.get("wa_id"): (c.get("profile") or {}).get("name", "") for c in value.get("contacts", [])}
            for m in value.get("messages", []):
                t = m.get("type")
                if t == "text":
                    text = (m.get("text") or {}).get("body", "")
                elif t == "button":
                    text = (m.get("button") or {}).get("text", "")
                elif t == "interactive":
                    inter = m.get("interactive") or {}
                    text = (inter.get("button_reply") or inter.get("list_reply") or {}).get("title", "")
                elif t in ("audio", "voice"):
                    text = VOICE
                elif t in ("image", "video", "document", "sticker"):
                    text = (m.get(t) or {}).get("caption") or FILE
                else:
                    continue
                if text:
                    out.append({"phone_number_id": pnid, "from": m.get("from", ""), "name": names.get(m.get("from"), ""),
                                "text": text})
    return out


def parse_messenger(payload: dict) -> list[dict]:
    """يرجع قائمة {page_id, sender, text, channel}. يخدم لماسنجر وإنستغرام."""
    channel = "instagram" if payload.get("object") == "instagram" else "messenger"
    out = []
    for entry in payload.get("entry", []):
        page_id = str(entry.get("id", ""))
        for ev in entry.get("messaging", []):
            msg = ev.get("message") or {}
            if msg.get("is_echo"):
                continue
            text = msg.get("text")
            if not text and msg.get("attachments"):
                text = FILE
            if not text:
                continue
            out.append({"page_id": page_id, "sender": str((ev.get("sender") or {}).get("id", "")), "text": text,
                        "channel": channel})
    return out
