"""حارس الردود: يرفض رد النموذج إذا فيه معلومات مخترعة ولا شكل غالط.

النماذج الصغيرة ساعات تخترع الأسعار ولا تكتب بالصينية (Qwen) ولا تطوّل بزاف.
إذا الرد ما فاتش، المحرك يستعمل الرد المدمج المضمون.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

from .normalize import to_latin_digits

_CJK = re.compile(r"[぀-ヿ㐀-䶿一-鿿가-힯]")
# "2 800" و"2.800" و"2,800" = عدد واحد؛ وإلا أرقام عادية
_NUMBER = re.compile(r"(?<!\d)\d{1,3}(?:[   .,]\d{3})+(?!\d)|\d+")
_PHONE = re.compile(r"(?<!\d)(?:\+?213|0)[\s.\-]*[2-7](?:[\s.\-]*\d){7,8}(?!\d)")
_CURRENCY = re.compile(r"^\s*(?:دج|دينار|da\b|dzd|د\.ج)", re.I)
_LEAK = re.compile(r"<\|im_|<\|end|<\/?s>|\[INST\]|معلومات المحل:|system:|assistant:", re.I)
_CLAUSE = re.compile(r"[.!؟?\n،,;]+")


@dataclass
class GuardResult:
    ok: bool
    reason: str = ""


def _value(s: str) -> int:
    return int(re.sub(r"\D", "", s))


def numbers(text: str) -> list[tuple[int, bool]]:
    """يرجع [(العدد، هل هو سعر بـ "دج")] بلا أرقام الهاتف."""
    t = to_latin_digits(text)
    t = _PHONE.sub(" ", t)
    out = []
    for m in _NUMBER.finditer(t):
        out.append((_value(m.group(0)), bool(_CURRENCY.match(t[m.end():]))))
    return out


def phones(text: str) -> set[str]:
    return {re.sub(r"\D", "", m.group(0))[-9:] for m in _PHONE.finditer(to_latin_digits(text))}


def allowed_numbers(system_prompt: str, user_text: str = "") -> set[int]:
    return {v for v, _ in numbers(system_prompt + "\n" + user_text)}


def price_numbers(system_prompt: str) -> set[int]:
    return {v for v, is_price in numbers(system_prompt) if is_price and v >= 50}


def _is_plausible_total(v: int, prices: set[int]) -> bool:
    """يقبل سعر × كمية صغيرة، ولا مجموع سعرين (مثلاً منتج + توصيل)."""
    for a in prices:
        if any(a * q == v for q in range(2, 11)):
            return True
        if any(a + b == v for b in prices):
            return True
    return False


def check(reply: str, system_prompt: str, user_text: str = "", products: list[dict] | None = None,
          max_len: int = 700) -> GuardResult:
    if not reply or not reply.strip():
        return GuardResult(False, "empty")
    if len(reply) > max_len:
        return GuardResult(False, "too_long")
    if _CJK.search(reply):
        return GuardResult(False, "foreign_script")
    if _LEAK.search(reply):
        return GuardResult(False, "prompt_leak")
    arabic = len(re.findall(r"[؀-ۿ]", reply))
    latin = len(re.findall(r"[A-Za-z]", reply))
    if arabic == 0 and latin > 40:
        return GuardResult(False, "not_darja")

    # أرقام الهاتف لازم تكون أرقام المحل
    known_phones = phones(system_prompt + "\n" + user_text)
    for ph in phones(reply):
        if ph not in known_phones:
            return GuardResult(False, f"unknown_phone:{ph}")

    allowed = allowed_numbers(system_prompt, user_text)
    prices = price_numbers(system_prompt)
    for v, is_price in numbers(reply):
        if v in allowed:
            continue
        if (is_price or v >= 100) and not _is_plausible_total(v, prices):
            return GuardResult(False, f"invented_number:{v}")

    # السعر المكتوب جنب اسم المنتج لازم يكون سعرو هو
    if products:
        from .extract import match_products
        for clause in _CLAUSE.split(to_latin_digits(reply).replace(" ", " ")):
            clause_prices = [v for v, is_price in numbers(clause) if is_price]
            if not clause_prices:
                continue
            matched = match_products(clause, products)
            if len(matched) != 1:
                continue
            base = int(matched[0].product["price"])
            ok_values = {base * q for q in range(1, 11)} | {base + d for d in prices}
            for v in clause_prices:
                if v not in ok_values:
                    return GuardResult(False, f"wrong_product_price:{v}")
    return GuardResult(True)
