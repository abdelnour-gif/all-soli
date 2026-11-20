"""تنبيهات صاحب المحل على تيليغرام (طلبية جديدة، زبون يحتاج بشري، شكوى)."""
from __future__ import annotations

import logging

from .channels import telegram
from .config import get_settings
from .prompt import money

log = logging.getLogger("darjabot.notify")


def _token_for(shop: dict) -> str:
    return get_settings().notify_telegram_token or shop.get("telegram_token", "")


def notify_owner(shop: dict, text: str) -> bool:
    chat = shop.get("owner_telegram_chat_id")
    token = _token_for(shop)
    if not chat or not token:
        log.info("notify skipped for shop %s (no owner chat / token)", shop.get("slug"))
        return False
    return telegram.send_message(token, chat, text)


def order_text(shop: dict, order: dict) -> str:
    booking = order.get("kind") == "booking"
    lines = [f"{'📅 حجز جديد' if booking else '🛒 طلبية جديدة'} #{order['id']} — {shop['name']}"]
    for i in order.get("items", []):
        size = f" ({i['size']})" if i.get("size") else ""
        lines.append(f"• {i['name']}{size} × {i['qty']} = {money(i['price'] * i['qty'])}")
    if not booking:
        lines.append(f"💰 المجموع: {money(order.get('total', 0))}")
    lines.append(f"👤 {order.get('customer_name', '')}  📞 {order.get('phone', '')}")
    addr = "، ".join(x for x in (order.get("wilaya"), order.get("address")) if x)
    if addr:
        lines.append(f"📍 {addr}")
    if order.get("preferred_time"):
        lines.append(f"🕐 {order['preferred_time']}")
    base = get_settings().public_base_url
    lines.append(f"لوحة التحكم: {base}/admin/")
    return "\n".join(lines)


def attention_text(shop: dict, conversation: dict, reason: str, last_message: str) -> str:
    reasons = {"human": "🙋 زبون حاب يهدر مع بشري", "complaint": "⚠️ شكوى من زبون",
               "unknown": "❓ سؤال ما عرفش البوت يجاوب عليه"}
    who = conversation.get("customer_name") or conversation.get("external_id")
    return (f"{reasons.get(reason, reason)} — {shop['name']}\n"
            f"👤 {who} ({conversation['channel']})\n💬 {last_message[:500]}\n"
            f"جاوبو من لوحة التحكم: {get_settings().public_base_url}/admin/#conv-{conversation['id']}")
