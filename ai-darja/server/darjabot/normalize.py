"""توحيد النص العربي/الدارجة باش المقارنة تكون مرنة.

أمثلة: "إيــه" → "ايه"، "الطلبيّة" → "الطلبيه"، "ممممرحبا" → "مرحبا"، "٠٥٥٥" → "0555".
"""
from __future__ import annotations

import re
import unicodedata

_TASHKEEL = re.compile(r"[ؐ-ًؚ-ٰٟۖ-ۭ]")
_TATWEEL = "ـ"
_AR_DIGITS = str.maketrans("٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹", "01234567890123456789")
_LETTER_MAP = str.maketrans({
    "أ": "ا", "إ": "ا", "آ": "ا", "ٱ": "ا",
    "ة": "ه", "ى": "ي", "ئ": "ي", "ؤ": "و",
    "گ": "ق", "ڨ": "ق", "ڤ": "ف", "پ": "ب", "چ": "ج", "ژ": "ز",
    "،": ",", "؛": ";", "؟": "?",
})
_REPEAT = re.compile(r"([^\W\d_])\1{2,}")
_NON_WORD = re.compile(r"[^\w\s]", re.UNICODE)
_SPACES = re.compile(r"\s+")


def to_latin_digits(text: str) -> str:
    return text.translate(_AR_DIGITS)


def normalize(text: str) -> str:
    """نسخة موحّدة للمقارنة (ماشي للعرض)."""
    if not text:
        return ""
    t = unicodedata.normalize("NFKC", text)
    t = to_latin_digits(t)
    t = _TASHKEEL.sub("", t).replace(_TATWEEL, "")
    t = t.translate(_LETTER_MAP).lower()
    t = _REPEAT.sub(r"\1", t)           # "بزاااف" → "بزاف"
    t = _NON_WORD.sub(" ", t)
    return _SPACES.sub(" ", t).strip()


_STOP = {
    "ال", "في", "فـ", "ف", "من", "على", "مع", "و", "او", "ولا", "هاد", "هذا", "هذي", "هادي",
    "تاع", "نتاع", "ديال", "بصح", "واش", "راه", "راهي", "راهو", "انا", "انت", "le", "la", "les",
    "de", "du", "des", "un", "une", "et", "the", "a", "of",
}


def strip_article(token: str) -> str:
    """ينحّي "ال" و"وال" و"بال"... من بداية الكلمة."""
    for p in ("وال", "بال", "فال", "كال", "لل", "ال"):
        if token.startswith(p) and len(token) - len(p) >= 2:
            return token[len(p):]
    return token


def tokens(text: str) -> list[str]:
    return [strip_article(w) for w in normalize(text).split() if w]


def content_tokens(text: str) -> list[str]:
    return [w for w in tokens(text) if w not in _STOP and len(w) >= 2]


def contains_any(norm_text: str, keywords: list[str] | set[str] | tuple[str, ...]) -> bool:
    """هل النص (الموحّد) فيه وحدة من الكلمات؟ الكلمات القصيرة لازم تكون كلمة كاملة."""
    padded = f" {norm_text} "
    for kw in keywords:
        if len(kw) <= 3:
            if f" {kw} " in padded:
                return True
        elif kw in norm_text:
            return True
    return False


def norm_list(words) -> tuple[str, ...]:
    return tuple(dict.fromkeys(normalize(w) for w in words if normalize(w)))
