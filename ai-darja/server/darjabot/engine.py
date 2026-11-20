"""المحرك الرئيسي: يستقبل رسالة الزبون ويقرر كيفاش يجاوب.

الترتيب:
  1) البوت مطفي ولا المحادثة عند بشري ← ما يجاوبش (يسجّل برك).
  2) طلبية مفتوحة ← مدير الطلبيات (مضمون، بلا نموذج).
  3) الموجّه المدمج (rules) يكشف: طلب بشري، شكوى، بداية طلبية.
  4) باقي الأسئلة ← النموذج (إذا كاين) + الحارس، وإلا ← الرد المدمج.
"""
from __future__ import annotations

import logging
import random
import time
from dataclasses import dataclass, field

from . import db, orders, rules
from .config import get_settings
from .guard import check
from .notify import attention_text, notify_owner, order_text
from .prompt import system_prompt
from .providers import ProviderError, get_provider

log = logging.getLogger("darjabot.engine")

HUMAN_TIMEOUT_SECONDS = 6 * 3600   # بعد 6 ساعات بلا رد بشري، البوت يرجع يخدم


@dataclass
class BotReply:
    text: str | None
    provider: str = ""
    conversation: dict | None = None
    order: dict | None = None
    events: list[str] = field(default_factory=list)


# يمكن تبديلها في الاختبارات
_notifier = notify_owner
_provider_override = None


def set_notifier(fn) -> None:
    global _notifier
    _notifier = fn


def set_provider_override(provider) -> None:
    """للاختبارات: نحطو مزوّد وهمي."""
    global _provider_override
    _provider_override = provider


def _provider():
    if _provider_override is not None:
        return _provider_override
    return get_provider(get_settings())


def _history(conv_id: int) -> list[dict]:
    turns = get_settings().history_turns
    out = []
    for m in db.list_messages(conv_id, limit=turns):
        if m["role"] == "user":
            out.append({"role": "user", "content": m["content"]})
        elif m["role"] in ("assistant", "owner"):
            out.append({"role": "assistant", "content": m.get("corrected") or m["content"]})
    return out


def _llm_answer(shop: dict, products: list[dict], conv_id: int, text: str) -> tuple[str, str] | None:
    provider = _provider()
    if provider is None:
        return None
    system = system_prompt(shop, products)
    try:
        reply = provider.generate(system, _history(conv_id))
    except ProviderError as e:
        log.warning("provider failed, using rules: %s", e)
        return None
    verdict = check(reply.text, system, text, products)
    if not verdict.ok:
        log.info("guard rejected reply (%s): %r", verdict.reason, reply.text[:200])
        return None
    return reply.text, reply.provider


def _notify(shop: dict, text: str, events: list[str], event: str) -> None:
    events.append(event)
    try:
        _notifier(shop, text)
    except Exception:  # التنبيه ما لازمش يطيّح الرد
        log.exception("notify failed")


def answer_question(shop: dict, products: list[dict], conv: dict, text: str, rr: rules.RuleResult,
                    events: list[str]) -> tuple[str, str]:
    """يجاوب على سؤال عادي: النموذج أولاً، وإلا الرد المدمج."""
    llm = _llm_answer(shop, products, conv["id"], text)
    if llm:
        return llm
    if rr.unknown:
        db.update_conversation(conv["id"], needs_attention=True)
        _notify(shop, attention_text(shop, conv, "unknown", text), events, "unknown")
    return rr.reply, "rules"


def handle_message(shop: dict, channel: str, external_id: str, text: str, customer_name: str = "",
                   rng: random.Random | None = None) -> BotReply:
    text = (text or "").strip()[:2000]
    conv = db.get_or_create_conversation(shop["id"], channel, external_id, customer_name)
    events: list[str] = []

    # الرجوع التلقائي للبوت إذا ما جاوب حتى واحد من الفريق مدة طويلة
    if conv["status"] == "human":
        last_reply = max((m["created_at"] for m in db.list_messages(conv["id"], limit=50)
                          if m["role"] in ("owner", "assistant")), default=conv["updated_at"])
        if time.time() - last_reply > HUMAN_TIMEOUT_SECONDS:
            conv = db.update_conversation(conv["id"], status="bot")

    db.add_message(conv["id"], "user", text)
    if not text:
        return BotReply(None, conversation=conv)
    if not shop.get("bot_enabled", True) or conv["status"] == "human":
        return BotReply(None, conversation=conv, events=["silent"])

    products = db.list_products(shop["id"])
    r = rng or random.Random()
    state = conv.get("order_state") or {}
    reply_text, provider, order = None, "rules", None
    by_id = {p["id"]: p for p in products}
    context_product = by_id.get(state.get("last_product_id"))

    # 1) طلبية مفتوحة
    if state.get("active"):
        step = orders.handle(shop, products, state, text)
        if not step.passthrough:
            reply_text, provider = step.reply, "orders"
            state = step.state
            if step.order:
                order = db.create_order(shop["id"], conv["id"], **step.order)
                _notify(shop, order_text(shop, order), events, "order")
                if not conv.get("customer_name") and order.get("customer_name"):
                    db.update_conversation(conv["id"], customer_name=order["customer_name"])
        else:
            rr = rules.answer(shop, products, text, r, context_product)
            if rr.needs_human or rr.complaint:
                state = {}
            else:
                ans, provider = answer_question(shop, products, conv, text, rr, events)
                reply_text = ans + (f"\n\n{step.reminder}" if step.reminder else "")
        db.update_conversation(conv["id"], order_state=state)

    if reply_text is None:
        rr = rules.answer(shop, products, text, r, context_product)
        if rr.needs_human:
            reply_text, provider = rr.reply, "rules"
            state = {}
            conv = db.update_conversation(conv["id"], status="human", needs_attention=True)
            _notify(shop, attention_text(shop, conv, "human", text), events, "human")
        elif rr.complaint:
            reply_text, provider = rr.reply, "rules"
            conv = db.update_conversation(conv["id"], needs_attention=True)
            _notify(shop, attention_text(shop, conv, "complaint", text), events, "complaint")
        elif rr.start_order:
            step = orders.start(shop, products, text, rr.product)
            if step.passthrough or not step.reply:
                reply_text, provider = answer_question(shop, products, conv, text, rr, events)
            else:
                reply_text, provider = step.reply, "orders"
                state = step.state
        else:
            reply_text, provider = answer_question(shop, products, conv, text, rr, events)
        # نتفكرو آخر منتج تهدرنا عليه
        if rr.product and not state.get("active"):
            state = {**state, "last_product_id": rr.product["id"]}
        elif rr.product:
            state["last_product_id"] = rr.product["id"]
        db.update_conversation(conv["id"], order_state=state)

    db.add_message(conv["id"], "assistant", reply_text, provider)
    return BotReply(reply_text, provider=provider, conversation=db.get_conversation(conv["id"]), order=order,
                    events=events)


def owner_reply(conv_id: int, text: str, take_over: bool = True) -> dict:
    """صاحب المحل يجاوب بيدو من لوحة التحكم."""
    msg = db.add_message(conv_id, "owner", text.strip())
    fields = {"needs_attention": False}
    if take_over:
        fields["status"] = "human"
    db.update_conversation(conv_id, **fields)
    return msg
