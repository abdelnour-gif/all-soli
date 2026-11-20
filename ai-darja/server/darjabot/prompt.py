"""بناء رسالة النظام (system prompt) من معلومات المحل.

مهم: نفس الصيغة اللي تدرّب عليها النموذج في generate_data.py، باش النموذج
المدرَّب يلقى نفس الشكل اللي تعلّم منه (كاين اختبار يتأكد من هاد التطابق).
"""
from __future__ import annotations

import re

SYSTEM_TEMPLATE = (
    "أنت مساعد خدمة الزبائن لمحل \"{name}\". تهدر بالدارجة الجزائرية بطريقة مؤدبة وقصيرة وواضحة. "
    "جاوب غير من المعلومات اللي تحت. إذا المعلومة ما كانتش، قول للزبون بلي راح تسقسي وترجعلو، "
    "ولا عطيه رقم الهاتف. ما تخترعش أسعار ولا معلومات.\n\n"
    "معلومات المحل:\n{card}"
)

MODES = {
    "delivery": "بيع مع التوصيل",
    "pickup": "بيع في المحل (الزبون يجي يدي)",
    "booking": "حجز مواعيد",
}


def money(n: int | float) -> str:
    """بلا فواصل (2800 دج): الفراغ بين الأرقام يتقلب في الكتابة من اليمين لليسار."""
    return f"{int(n)} دج"


def fmt_phone(phone: str) -> str:
    """0555 12 34 56 ← 0555123456 (نفس السبب: الفراغات تقلب ترتيب الأرقام)."""
    return re.sub(r"[\s.\-]+", "", phone or "")


def shop_card(shop: dict, products: list[dict]) -> str:
    info = shop.get("info") or {}
    lines = [f"اسم المحل: {shop['name']}"]
    for key, label in (("city", "المدينة"), ("address", "العنوان"), ("hours", "أوقات العمل"), ("phone", "الهاتف")):
        if info.get(key):
            value = fmt_phone(info[key]) if key == "phone" else info[key]
            lines.append(f"{label}: {value}")
    if info.get("delivery_available"):
        lines.append(
            f"التوصيل: متوفر، السعر {info.get('delivery_price') or 'حسب الولاية'}، "
            f"المدة {info.get('delivery_time') or 'حسب الولاية'}، المناطق: {info.get('delivery_areas') or '58 ولاية'}"
        )
    else:
        lines.append("التوصيل: غير متوفر")
    if info.get("payment"):
        lines.append(f"الدفع: {info['payment']}")
    if info.get("returns"):
        lines.append(f"الترجيع/الضمان: {info['returns']}")
    lines.append("المنتجات/الخدمات:")
    for p in products:
        extra = f" | {p['sizes']}" if p.get("sizes") else ""
        stock = "متوفر" if p.get("in_stock", True) else "غير متوفر حالياً"
        desc = f" | {p['description']}" if p.get("description") else ""
        lines.append(f"- {p['name']}: {money(p['price'])}{extra} | {stock}{desc}")
    if info.get("extra"):
        lines.append(f"معلومات إضافية: {info['extra']}")
    faqs = [f for f in (info.get("faqs") or []) if f.get("q") and f.get("a")]
    if faqs:
        lines.append("أسئلة متكررة:")
        for f in faqs:
            lines.append(f"- س: {f['q']} | ج: {f['a']}")
    return "\n".join(lines)


def system_prompt(shop: dict, products: list[dict]) -> str:
    return SYSTEM_TEMPLATE.format(name=shop["name"], card=shop_card(shop, products))
