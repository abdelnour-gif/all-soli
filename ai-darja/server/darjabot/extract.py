"""استخراج المعلومات من رسائل الزبائن: المنتج، الهاتف، الاسم، الكمية، المقاس، نعم/لا."""
from __future__ import annotations

import re
from dataclasses import dataclass

from .normalize import content_tokens, contains_any, norm_list, normalize, strip_article, to_latin_digits
from .wilayas import find_wilaya

# ---------------------------------------------------------------------------
# الهاتف
# ---------------------------------------------------------------------------
_PHONE_RE = re.compile(
    r"(?<!\d)(?:(?:\+|00)\s*213[\s.\-]*|0)([5-7](?:[\s.\-]*\d){8}|[2-4](?:[\s.\-]*\d){7})(?!\d)"
)


def find_phone(text: str) -> tuple[str, str] | None:
    """يرجع (الرقم الموحّد 0XXXXXXXXX، النص الأصلي) ولا None."""
    raw = to_latin_digits(text)
    m = _PHONE_RE.search(raw)
    if not m:
        return None
    digits = re.sub(r"\D", "", m.group(1))
    return "0" + digits, m.group(0)


# ---------------------------------------------------------------------------
# نعم / لا / إلغاء
# ---------------------------------------------------------------------------
YES = norm_list(["إيه", "ايه", "اه", "آه", "إي", "اي", "نعم", "واه", "وه", "صح", "أكد", "أكّد", "نأكد", "مأكد",
                 "اكيد", "أكيد", "ok", "okay", "oki", "oui", "ouais", "wah", "ih", "ey", "yes", "d'accord", "dacord",
                 "مليح", "باهي", "يه", "c bon", "cbon", "صحيت", "هيا", "يالاه", "يلا", "موافق", "confirm", "confirmer"])
NO = norm_list(["لا", "لالا", "ماشي", "no", "non", "nn", "غلط", "غالط", "بدل", "نبدل", "مازال لا", "machi", "la"])
CANCEL = norm_list(["ألغي", "الغي", "نلغي", "إلغاء", "الغاء", "بطلت", "ما حبيتش", "ماحبيتش", "خلاص ما", "annuler",
                    "annule", "cancel", "ما نحبش", "مانحبش", "سمحلي ما", "نحبس", "حبس"])


def is_yes(text: str) -> bool:
    n = normalize(text)
    if not n or len(n.split()) > 6:
        return False
    return contains_any(n, YES) and not is_no(text)


def is_no(text: str) -> bool:
    n = normalize(text)
    if not n:
        return False
    first = n.split()[0]
    return first in NO or n in NO


def is_cancel(text: str) -> bool:
    return contains_any(normalize(text), CANCEL)


# ---------------------------------------------------------------------------
# الكمية
# ---------------------------------------------------------------------------
_WORD_QTY = {
    normalize(k): v for k, v in {
        "واحد": 1, "وحدة": 1, "وحده": 1, "زوج": 2, "جوج": 2, "اثنين": 2, "زوجة": 2, "ثلاثة": 3, "تلاتة": 3,
        "ثلاثه": 3, "تلاثة": 3, "ربعة": 4, "اربعة": 4, "أربعة": 4, "خمسة": 5, "ستة": 6, "سبعة": 7, "ثمانية": 8,
        "تسعة": 9, "عشرة": 10,
    }.items()
}
_QTY_RE = re.compile(r"(?<![\d.])(\d{1,2})\s*(?:x|×|حبات|حبة|حبه|قطع|قطعة|pieces|pcs)?\s*(?=\D|$)")


def find_quantity(text: str, sizes: str = "") -> int | None:
    """"زوج"، "x3"، "3 حبات"، ولا رقم صغير وحدو ("2 تيشورتات") إذا ماهوش مقاس."""
    n = normalize(text)
    for w in n.split():
        if w in _WORD_QTY:
            return _WORD_QTY[w]
    raw = to_latin_digits(text)
    ph = find_phone(raw)
    if ph:
        raw = raw.replace(ph[1], " ")
    m = re.search(r"(?:x|×)\s*(\d{1,2})\b|\b(\d{1,2})\s*(?:x|×|حبات|حبة|حبه|قطع|قطعة|pcs|pieces)", raw)
    if m:
        return int(m.group(1) or m.group(2))
    size_values = {normalize(o) for o in size_options(sizes)} if sizes else set()
    for m in re.finditer(r"(?<![\d.,])(\d{1,2})(?![\d.,]|\s*(?:h|سا|:))", raw):
        if m.group(1) not in size_values and 1 < int(m.group(1)) <= 20:
            return int(m.group(1))
    return None


# ---------------------------------------------------------------------------
# المنتجات
# ---------------------------------------------------------------------------

@dataclass
class ProductMatch:
    product: dict
    score: float


def _product_terms(p: dict) -> list[list[str]]:
    """كل اسم (الاسم + الأسماء الأخرى) كقائمة كلمات موحّدة."""
    names = [p["name"]] + [a for a in re.split(r"[,،;\n]", p.get("aliases") or "") if a.strip()]
    out = []
    for nm in names:
        toks = content_tokens(re.sub(r"\(.*?\)", " ", nm)) or content_tokens(nm)
        if toks:
            out.append(toks)
    return out


def _tok_match(a: str, b: str) -> bool:
    if a == b:
        return True
    # جمع/مفرد بسيط: "سراول" ~ "سروال"، "تيشورتات" ~ "تيشورت"
    if len(a) >= 4 and len(b) >= 4 and (a.startswith(b) or b.startswith(a)):
        return True
    return False


def match_products(text: str, products: list[dict], min_score: float = 0.5) -> list[ProductMatch]:
    msg = [strip_article(t) for t in content_tokens(text)]
    if not msg:
        return []
    results: list[ProductMatch] = []
    for p in products:
        best = 0.0
        for terms in _product_terms(p):
            hits = sum(1 for t in terms if any(_tok_match(t, m) for m in msg))
            score = hits / len(terms)
            # كلمة مميّزة وحدها (مثلاً "جينز" ولا "بقلاوة") تكفي
            if hits and len(terms) > 1 and any(len(t) >= 4 and any(_tok_match(t, m) for m in msg) for t in terms[-1:]):
                score = max(score, 0.75)
            best = max(best, score)
        if best >= min_score:
            results.append(ProductMatch(p, best))
    results.sort(key=lambda r: -r.score)
    if results:
        top = results[0].score
        results = [r for r in results if r.score >= top - 0.01]
    return results


def best_product(text: str, products: list[dict]) -> dict | None:
    m = match_products(text, products)
    return m[0].product if len(m) == 1 else (m[0].product if m and m[0].score >= 0.99 else None)


# ---------------------------------------------------------------------------
# المقاس
# ---------------------------------------------------------------------------

def size_options(sizes: str) -> list[str]:
    s = to_latin_digits(sizes)
    rng = re.search(r"(\d{2})\s*(?:حتى|الى|إلى|-|à|a)\s*(\d{2})", s)
    opts: list[str] = []
    if rng:
        a, b = int(rng.group(1)), int(rng.group(2))
        if 0 < b - a <= 30:
            opts.extend(str(i) for i in range(a, b + 1))
    letter = re.search(r"\b(XXS|XS|S|M|L|XL|XXL|XXXL)\b\s*(?:حتى|الى|إلى|-)\s*\b(XXS|XS|S|M|L|XL|XXL|XXXL)\b", s, re.I)
    order = ["XXS", "XS", "S", "M", "L", "XL", "XXL", "XXXL"]
    if letter:
        i, j = order.index(letter.group(1).upper()), order.index(letter.group(2).upper())
        opts.extend(order[i:j + 1])
    for part in re.split(r"[,،/|]|\bو\b|\s+و", s):
        part = part.strip()
        if part and not re.search(r"حتى|الى|إلى", part):
            opts.append(part)
    seen, out = set(), []
    for o in opts:
        k = normalize(o)
        if k and k not in seen:
            seen.add(k)
            out.append(o)
    return out


def find_size(text: str, sizes: str) -> str | None:
    if not sizes:
        return None
    raw = to_latin_digits(text)
    ph = find_phone(raw)
    if ph:
        raw = raw.replace(ph[1], " ")
    norm = f" {normalize(raw)} "
    for opt in sorted(size_options(sizes), key=lambda o: -len(o)):
        k = normalize(opt)
        if not k:
            continue
        if re.fullmatch(r"[a-z]{1,4}", k):
            if re.search(rf"(?<![a-z]){re.escape(k)}(?![a-z])", raw.lower()):
                return opt.upper()
        elif re.fullmatch(r"\d+", k):
            if re.search(rf"(?<!\d){k}(?!\d)", raw):
                return opt
        elif len(k) >= 3 and f" {k} " in norm:
            return opt
    return None


# ---------------------------------------------------------------------------
# الاسم
# ---------------------------------------------------------------------------
_NAME_RE = re.compile(
    r"(?:اسمي|إسمي|سميتي|الاسم|الإسم|انا اسمي|smiti|esmi|nom|je m'appelle|my name is)\s*[:：\-]?\s*"
    r"([^\d,،\n.!?؟]{2,40})", re.I)
_NAME_STOP = re.compile(r"\s+(?:و?رقمي|و?الرقم|و?الهاتف|و?التيليفون|و?نسكن|و?ساكن|من|في|و)(?=\s|$).*$")


def _explicit_name_match(text: str) -> tuple[str, str] | None:
    """يرجع (الاسم، النص الكامل اللي لازم يتنحّى من الرسالة)."""
    m = _NAME_RE.search(text)
    if not m:
        return None
    name = _NAME_STOP.sub("", m.group(1).strip()).strip(" :-")
    if not 2 <= len(name) <= 40:
        return None
    full = text[m.start():m.start(1)] + name
    return name, full


def find_explicit_name(text: str) -> str | None:
    r = _explicit_name_match(text)
    return r[0] if r else None


_NOT_NAME = norm_list(["سلام", "السلام عليكم", "مرحبا", "شكرا", "ايه", "لا", "واش", "وين", "كيفاش", "بشحال",
                       "شحال", "التوصيل", "عندكم", "كاين", "نحب", "حاب", "راني", "ok", "oui", "non", "merci",
                       "صحيت", "يعطيك الصحة", "بارك الله فيك", "نعم", "قداش", "وقتاش", "علاش", "ممكن"])


def looks_like_name(text: str) -> bool:
    t = text.strip(" .,!،")
    if not t or re.search(r"\d", to_latin_digits(t)):
        return False
    words = t.split()
    if not 1 <= len(words) <= 4 or len(t) > 40:
        return False
    n = normalize(t)
    if n in _NOT_NAME or any(normalize(w) in _NOT_NAME for w in words):
        return False
    return bool(re.search(r"[A-Za-z؀-ۿ]", t))


# ---------------------------------------------------------------------------
# تقسيم رسالة فيها معلومات بزاف ("محمد، 0555..., وهران بير الجير")
# ---------------------------------------------------------------------------

@dataclass
class CustomerFields:
    name: str | None = None
    phone: str | None = None
    wilaya: str | None = None
    address: str | None = None
    leftovers: list[str] | None = None


_LABELS = re.compile(
    r"(?:و?رقمي|و?الرقم|رقم الهاتف|و?تيليفوني|و?الهاتف|numero|num|tel|العنوان|عنواني|adresse)\s*[:：\-]?",
    re.I)
_EDGE_WORDS = {"من", "في", "ف", "فـ", "و", "ولاية", "ولايه", "بلدية", "بلديه", "commune", "wilaya", "نسكن", "ساكن",
               "انا", "أنا", "راني"}


def _trim_edges(text: str) -> str:
    words = text.replace("،", " ").split()
    while words and normalize(words[0]) in {normalize(w) for w in _EDGE_WORDS}:
        words.pop(0)
    while words and normalize(words[-1]) in {normalize(w) for w in _EDGE_WORDS}:
        words.pop()
    return " ".join(words).strip(" .:-")


def parse_customer_fields(text: str) -> CustomerFields:
    """يفرّق رسالة فيها (اسم، هاتف، ولاية، عنوان) على الحقول."""
    out = CustomerFields(leftovers=[])
    work_text = to_latin_digits(text)
    em = _explicit_name_match(work_text)
    if em:
        out.name = em[0]
        work_text = work_text.replace(em[1], " , ")
    ph = find_phone(work_text)
    if ph:
        out.phone = ph[0]
        work_text = work_text.replace(ph[1], " , ")
    work_text = _LABELS.sub(" ", work_text)
    for seg in [s.strip() for s in re.split(r"[\n,،;/]+|\s+-\s+", work_text) if s.strip()]:
        w = find_wilaya(seg)
        if w and not out.wilaya:
            out.wilaya = w[1]
            rest = _trim_edges(_remove_alias(seg, w[2]))
            if len(rest) >= 3:
                out.address = out.address or rest
            continue
        seg = _trim_edges(seg)
        if seg:
            out.leftovers.append(seg)
    return out


def _remove_alias(text: str, alias: str) -> str:
    norm_words = text.split()
    alias_words = alias.split()
    for i in range(len(norm_words) - len(alias_words) + 1):
        window = " ".join(normalize(w) for w in norm_words[i:i + len(alias_words)])
        if alias in window:
            return " ".join(norm_words[:i] + norm_words[i + len(alias_words):])
    return text.replace(alias, " ")
