"""مدير الطلبيات والحجوزات: يجمع المعلومات خطوة بخطوة، يلخّص، ويطلب التأكيد.

هاد الجزء ما يعتمدش على النموذج (deterministic) باش الطلبيات تكون مضمونة 100%.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

from .extract import (best_product, find_quantity, find_size, is_cancel, is_no, is_yes, looks_like_name,
                      match_products, parse_customer_fields, size_options)
from .normalize import contains_any, norm_list, normalize
from .prompt import money
from .rules import KW

FIELD_LABELS = {
    "size": "المقاس",
    "name": "الاسم",
    "phone": "رقم الهاتف",
    "wilaya": "الولاية",
    "address": "البلدية/العنوان",
    "preferred_time": "النهار والوقت اللي يناسبك",
}

_TIME_WORDS = norm_list(["غدوة", "غدوا", "بعد غدوة", "اليوم", "ليوم", "السبت", "الحد", "الأحد", "الاحد", "الاثنين",
                         "الإثنين", "الثلاثاء", "التلات", "الأربعاء", "الاربعاء", "لاربعا", "الخميس", "الجمعة",
                         "الصباح", "الصبح", "العشية", "لعشية", "المساء", "الليل", "demain", "matin", "soir",
                         "après-midi", "apres midi", "lundi", "mardi", "mercredi", "jeudi", "samedi", "dimanche",
                         "الجاية", "الجاي", "السيمانة", "semaine", "ساعة", "الساعة"])
_TIME_RE = re.compile(r"\b\d{1,2}\s*(?:h|:|سا|ساعة|heure)\s*\d{0,2}\b", re.I)
_ADD_WORDS = norm_list(["زيد", "زيدلي", "وزيد", "مع", "حتى", "ajoute", "plus"])


@dataclass
class OrderStep:
    state: dict
    reply: str | None = None
    order: dict | None = None          # الطلبية إذا تأكدت (للحفظ)
    passthrough: bool = False          # ما فهمناش معلومة: خلي المحرك يجاوب على السؤال
    cancelled: bool = False
    reminder: str | None = None        # تذكير بالمعلومات الناقصة (يتزاد بعد جواب السؤال)
    notes: list[str] = field(default_factory=list)


def _mode(shop: dict) -> str:
    return (shop.get("info") or {}).get("mode", "delivery")


_ALL_KW = tuple(w for words in KW.values() for w in words if len(w) >= 3)
_QUESTION_KW = tuple(w for k in ("price", "delivery", "hours", "location", "payment", "returns", "catalog")
                     for w in KW[k])


def is_question(text: str) -> bool:
    """سؤال (ماشي معلومات للطلبية): "بشحال التوصيل لعنابة؟"."""
    if parse_customer_fields(text).phone:
        return False
    n = normalize(text)
    return contains_any(n, _QUESTION_KW) or (text.strip().endswith(("?", "؟")) and len(n.split()) > 1)


def required_fields(shop: dict, state: dict) -> list[str]:
    mode = _mode(shop)
    missing: list[str] = []
    for it in state.get("items", []):
        if it.get("sizes") and not it.get("size") and mode != "booking":
            missing.append("size")
            break
    if mode == "booking":
        wanted = ["name", "phone", "preferred_time"]
    elif mode == "pickup":
        wanted = ["name", "phone"]
    else:
        wanted = ["name", "phone", "wilaya", "address"]
    missing += [f for f in wanted if not state.get(f)]
    return missing


def _item(p: dict, qty: int | None = None, size: str | None = None) -> dict:
    return {"product_id": p["id"], "name": p["name"], "price": int(p["price"]), "qty": max(1, int(qty or 1)),
            "size": size or "", "sizes": p.get("sizes") or ""}


def _ask(shop: dict, state: dict, missing: list[str]) -> str:
    if not missing:
        return ""
    if missing[0] == "size":
        it = next(i for i in state["items"] if i.get("sizes") and not i.get("size"))
        return f"واش من مقاس تحب فـ {it['name']}؟ كاين: {it['sizes']}."
    labels = "، ".join(FIELD_LABELS[f] for f in missing)
    if _mode(shop) == "booking":
        return f"باش نحجزولك، عطيني: {labels}."
    return f"باش نكملو الطلبية عطيني: {labels}."


def total(state: dict) -> int:
    return sum(i["price"] * i["qty"] for i in state.get("items", []))


def summary(shop: dict, state: dict) -> str:
    info = shop.get("info") or {}
    booking = _mode(shop) == "booking"
    lines = ["📅 ملخص الحجز:" if booking else "📝 ملخص الطلبية:"]
    for i in state["items"]:
        size = f" ({i['size']})" if i.get("size") else ""
        qty = f" × {i['qty']}" if i["qty"] > 1 else ""
        lines.append(f"• {i['name']}{size}{qty}: {money(i['price'] * i['qty'])}")
    if not booking:
        t = f"💰 المجموع: {money(total(state))}"
        if info.get("delivery_available") and _mode(shop) == "delivery" and info.get("delivery_price"):
            t += f" + التوصيل ({info['delivery_price']})"
        lines.append(t)
    lines.append(f"👤 الاسم: {state.get('name', '')}")
    lines.append(f"📞 الهاتف: {state.get('phone', '')}")
    if state.get("wilaya") or state.get("address"):
        lines.append("📍 العنوان: " + "، ".join(x for x in (state.get("wilaya"), state.get("address")) if x))
    if state.get("preferred_time"):
        lines.append(f"🕐 الوقت: {state['preferred_time']}")
    lines.append("")
    lines.append("نأكدو الحجز؟ (إيه / لا)" if booking else "نأكدو الطلبية؟ (إيه / لا)")
    return "\n".join(lines)


def _apply_fields(shop: dict, products: list[dict], state: dict, text: str) -> bool:
    """يعبّي الحقول من الرسالة. يرجع True إذا تلقات معلومة جديدة."""
    changed = False
    mode = _mode(shop)

    # منتج إضافي ("زيدلي تيشورت")
    matches = match_products(text, products)
    if matches and len(matches) == 1:
        p = matches[0].product
        existing = next((i for i in state["items"] if i["product_id"] == p["id"]), None)
        if existing is None and (contains_any(normalize(text), _ADD_WORDS) or not state["items"]):
            if p.get("in_stock", True):
                state["items"].append(_item(p, find_quantity(text), find_size(text, p.get("sizes", ""))))
                changed = True
        elif existing is not None:
            q = find_quantity(text)
            if q and q != existing["qty"]:
                existing["qty"] = q
                changed = True

    # المقاس
    for it in state["items"]:
        if it.get("sizes") and not it.get("size"):
            s = find_size(text, it["sizes"])
            if s:
                it["size"] = s
                changed = True
            break

    f = parse_customer_fields(text)
    for key in ("name", "phone", "wilaya", "address"):
        val = getattr(f, key)
        if val and (not state.get(key) or state.get("stage") == "confirm"):
            if state.get(key) != val:
                state[key] = val
                changed = True

    # الباقي: وقت (للحجز)، اسم ولا عنوان حسب اللي ناقص
    leftovers = [l for l in (f.leftovers or []) if l]
    if mode == "booking":
        split: list[str] = []
        for chunk in leftovers:
            before, when = split_time(chunk)
            if when and (not state.get("preferred_time") or state.get("stage") == "confirm"):
                if state.get("preferred_time") != when:
                    state["preferred_time"] = when
                    changed = True
                if before:
                    split.append(before)
            else:
                split.append(chunk)
        leftovers = split
    if leftovers:
        missing = required_fields(shop, state)
        if state.get("stage") == "confirm" and "name" not in missing:
            # في مرحلة التأكيد نقبلو تصحيح الاسم إذا جا مع معلومات أخرى
            if (f.phone or f.wilaya) and looks_like_name(leftovers[0]) and leftovers[0] != state.get("name"):
                state["name"] = leftovers[0]
                changed = True
            return changed
        for chunk in leftovers:
            if is_yes(chunk) or is_no(chunk) or len(chunk.strip()) < 2:
                continue
            if contains_any(normalize(chunk), _ALL_KW):
                continue
            if any(i.get("size") and normalize(i["size"]) == normalize(chunk) for i in state["items"]):
                continue
            if match_products(chunk, products):
                continue
            if "name" in missing and looks_like_name(chunk):
                state["name"] = chunk
                missing.remove("name")
                changed = True
            elif "address" in missing and (state.get("wilaya") or f.wilaya) and len(chunk) >= 3 \
                    and not contains_any(normalize(chunk), _TIME_WORDS):
                state["address"] = chunk
                missing.remove("address")
                changed = True
    return changed


def split_time(chunk: str) -> tuple[str, str | None]:
    """"سارة بوزيد السبت العشية" ← ("سارة بوزيد"، "السبت العشية")."""
    words = chunk.split()
    for i, w in enumerate(words):
        if contains_any(normalize(w), _TIME_WORDS) or _TIME_RE.search(w) or \
                (i + 1 < len(words) and _TIME_RE.search(f"{w} {words[i + 1]}")):
            return " ".join(words[:i]).strip(), " ".join(words[i:]).strip()[:80]
    if _TIME_RE.search(chunk):
        return "", chunk.strip()[:80]
    return chunk, None


def start(shop: dict, products: list[dict], text: str, product: dict | None = None) -> OrderStep:
    mode = _mode(shop)
    p = product or best_product(text, products)
    state = {"active": True, "stage": "collect", "kind": "booking" if mode == "booking" else "order", "items": []}
    if p is not None:
        if not p.get("in_stock", True):
            return OrderStep(state={}, reply=f"للأسف {p['name']} ماكانش حالياً، كي يرجع نخبروك إن شاء الله. "
                                              "تحب حاجة أخرى؟")
        state["items"].append(_item(p, find_quantity(text), find_size(text, p.get("sizes", ""))))
    elif mode != "booking":
        return OrderStep(state={}, passthrough=True)
    _apply_fields(shop, products, state, text)
    if not state["items"]:
        names = "، ".join(x["name"] for x in products[:6] if x.get("in_stock", True))
        state["stage"] = "collect"
        return OrderStep(state=state, reply=f"مرحبا! على واش تحب تحجز؟ عندنا: {names}.")
    return _next(shop, state, opening=True)


def _next(shop: dict, state: dict, opening: bool = False) -> OrderStep:
    missing = required_fields(shop, state)
    if missing:
        state["stage"] = "collect"
        intro = ""
        if opening:
            it = state["items"][0]
            intro = f"مرحبا بيك! {it['name']} بـ {money(it['price'])}. " if _mode(shop) != "booking" else \
                f"مرحبا! {it['name']} ({money(it['price'])}). "
        return OrderStep(state=state, reply=intro + _ask(shop, state, missing))
    state["stage"] = "confirm"
    return OrderStep(state=state, reply=summary(shop, state))


def handle(shop: dict, products: list[dict], state: dict, text: str) -> OrderStep:
    """رسالة جديدة وطلبية مفتوحة."""
    if is_cancel(text):
        return OrderStep(state={}, cancelled=True,
                         reply="ماكاش مشكل، لغينا الطلبية. إذا حبيت حاجة أخرى راني هنا 😊")

    if state.get("stage") == "confirm":
        if is_yes(text):
            booking = state.get("kind") == "booking"
            order = {
                "kind": state.get("kind", "order"),
                "items": [{k: v for k, v in i.items() if k != "sizes"} for i in state["items"]],
                "customer_name": state.get("name", ""), "phone": state.get("phone", ""),
                "wilaya": state.get("wilaya", ""), "address": state.get("address", ""),
                "preferred_time": state.get("preferred_time", ""), "total": total(state),
            }
            reply = ("✅ تسجّل طلب الموعد! راح نعيطولك قريب باش نأكدو الوقت. يعطيك الصحة 🙏" if booking else
                     "✅ تسجّلت الطلبية! راح نعيطولك قريب باش نأكدو معاك. يعطيك الصحة 🙏")
            return OrderStep(state={}, reply=reply, order=order)
        if is_no(text):
            changed = _apply_fields(shop, products, state, text)
            if changed:
                return _next(shop, state)
            for k in ("name", "phone", "wilaya", "address", "preferred_time"):
                state.pop(k, None)
            for it in state["items"]:
                it["size"] = ""
            state["stage"] = "collect"
            missing = required_fields(shop, state)
            return OrderStep(state=state, reply="ماكاش مشكل، نعاودو. " + _ask(shop, state, missing))
        has_info = bool(parse_customer_fields(text).phone or split_time(text)[1])
        if (has_info or not is_question(text)) and _apply_fields(shop, products, state, text):
            return _next(shop, state)
        what = "الحجز" if state.get("kind") == "booking" else "الطلبية"
        return OrderStep(state=state, passthrough=True, reminder=f"نأكدو {what}؟ جاوب بـ إيه ولا لا.")

    # مرحلة الجمع
    awaiting_time = _mode(shop) == "booking" and not state.get("preferred_time")
    if is_question(text) and not (awaiting_time and split_time(text)[1] and "?" not in text and "؟" not in text):
        missing = required_fields(shop, state)
        return OrderStep(state=state, passthrough=True, reminder=_ask(shop, state, missing))
    missing_before = required_fields(shop, state)
    if missing_before and missing_before[0] == "size":
        it = next(i for i in state["items"] if i.get("sizes") and not i.get("size"))
        if not find_size(text, it["sizes"]) and re.fullmatch(r"\s*[\w]{1,4}\s*", text or "") and \
                not parse_customer_fields(text).phone:
            return OrderStep(state=state, reply=f"هاد المقاس ماكانش فـ {it['name']}. المقاسات: {it['sizes']}.")
    if _apply_fields(shop, products, state, text):
        return _next(shop, state)
    missing = required_fields(shop, state)
    return OrderStep(state=state, passthrough=True, reminder=_ask(shop, state, missing))


def describe_sizes(sizes: str) -> list[str]:
    return size_options(sizes)
