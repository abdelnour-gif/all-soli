"""محرك الردود المدمج بالدارجة (يخدم بلا نموذج ذكاء اصطناعي).

يفهم نية الزبون (السعر، التوصيل، الأوقات...) ويجاوب من معلومات المحل.
يُستعمل:
  1) كمزوّد رئيسي إذا ما كانش نموذج (LLM_PROVIDER=rules)،
  2) كاحتياط إذا النموذج طاح ولا جاوب بمعلومة غالطة.
"""
from __future__ import annotations

import random
from dataclasses import dataclass, field

from .extract import match_products
from .media import FILE, VOICE
from .wilayas import find_wilaya
from .normalize import contains_any, norm_list, normalize, content_tokens
from .prompt import fmt_phone, money

KW = {
    "price": norm_list(["بشحال", "شحال", "قداش", "قداه", "بقداه", "بقداش", "سعر", "السعر", "سومة", "سومه", "سومتو",
                        "الثمن", "ثمن", "prix", "combien", "chhal", "bechhal", "9adach", "kadach", "9dach", "price",
                        "bch7al", "ch7al", "tarif"]),
    "stock": norm_list(["كاين", "كاينة", "كاينه", "متوفر", "متوفرة", "مازال", "عندكم", "نلقى", "kayen", "kayn",
                        "disponible", "dispo", "available", "راهو كاين", "تبيعو"]),
    "sizes": norm_list(["مقاس", "مقاسات", "طاي", "تاي", "تايات", "طايات", "taille", "pointure", "حجم", "أحجام",
                        "احجام", "size", "نمرة", "نمره", "القد", "تايّات"]),
    "delivery": norm_list(["توصيل", "توصلو", "توصلوا", "التوصيل", "ليفريزون", "ليفريزو", "livraison", "تبعثو",
                           "تبعثوا", "يوصل", "توصل", "شحن", "yalidine", "ياليدين", "zr express", "للدار",
                           "تبعثولي", "توصيلة", "livreur", "ليفرور", "delivery"]),
    "hours": norm_list(["وقتاش", "واش من ساعة", "ساعة", "تحلو", "تحلّو", "تسكرو", "حالين", "حالّين", "مسكر",
                        "مسكّر", "أوقات", "اوقات", "اوقات العمل", "تخدمو", "horaire", "ouvert", "fermé", "ferme",
                        "غدوة", "الجمعة", "السبت", "الليل"]),
    "location": norm_list(["وين", "عنوان", "العنوان", "لوكاسيون", "localisation", "adresse", "بلاصة", "بلاصه",
                           "الخريطة", "maps", "المحل وين", "كيفاش نوصل", "nlqakom", "win", "location", "gps", "où", "ou etes",
                           "vous etes ou", "rakom win", "وين جا", "وين تقعدو", "وين كاين المحل"]),
    "payment": norm_list(["نخلص", "خلاص", "الخلاص", "دفع", "الدفع", "بريدي", "baridimob", "ccp", "carte", "بطاقة",
                          "بطاقه", "تقسيط", "بالتقسيط", "paiement", "payer", "cib", "edahabia", "الذهبية", "عند الاستلام",
                          "كريدي", "credit", "crédit", "facilité", "بالكريدي", "versement"]),
    "returns": norm_list(["نبدل", "تبديل", "نرجع", "ترجيع", "نرجعها", "نرجعو", "ضمان", "garantie", "échange",
                          "echange", "retour", "rembourse", "تبدلولي"]),
    "order": norm_list(["نطلب", "نكوموندي", "كوموند", "commande", "commander", "نشري", "حاب ناخذ", "نحب ناخذ",
                        "حطلي", "ابعثلي", "بعثلي", "ابعثهولي", "نحجز", "حجز", "موعد", "rdv", "rendez", "réserver",
                        "reserver", "نديه", "نديها", "احجزلي", "حابة ناخذ", "نحب نشري", "حاب نشري", "نكموندي",
                        "طلبية", "order", "ندي", "بغيت", "نبغي", "حبيت ناخذ", "حبيت نطلب", "نحب نحجز", "je veux",
                        "réservation", "reservation"]),
    "greeting": norm_list(["سلام", "السلام", "صباح الخير", "مساء الخير", "عسلامة", "عسلامه", "مرحبا", "اهلا",
                           "bonjour", "salam", "slm", "bonsoir", "hello", "salut", "صباحو", "slt", "hi", "hey", "cc",
                           "coucou", "wesh", "saha", "صحة", "السلام عليكم ورحمة الله"]),
    "thanks": norm_list(["شكرا", "يعطيك الصحة", "يعطيك الصحه", "صحيت", "بارك الله", "merci", "ربي يحفظك",
                         "ربي يخليك", "thanks", "mrc", "سحيت", "تبارك الله", "الله يبارك"]),
    "human": norm_list(["نهدر مع", "بشري", "مسؤول", "المسؤول", "صاحب المحل", "شخص حقيقي", "عبد حقيقي", "humain",
                        "responsable", "نحب نهدر", "حاب نهدر", "واحد من الخدامين", "مولا المحل", "مول الحانوت",
                        "مولى المحل"]),
    "complaint": norm_list(["ما وصلتش", "ماوصلتش", "ما وصلنيش", "ماوصلنيش", "مكسور", "مكسورة", "مقلق", "تعطل",
                            "تعطلت", "ماشي كيما", "غالطة", "غلطتو", "مشكل", "مشكلة", "شكوى", "réclamation",
                            "reclamation", "arnaque", "نصب", "خايبة", "ما ردش", "حد ما رد", "ما جاتش", "ماجاتش",
                            "مقطوع", "مخدوش", "probleme", "problème", "نستنى", "طولت", "retard", "ما جاش", "ماجاش",
                            "مازال ما وصل", "مازال ماوصل", "ما وصلش", "ماوصلش", "وين راهي الطلبية",
                            "وين راهي الكوموند", "تأخرت", "تاخرت"]),
    "catalog": norm_list(["واش عندكم", "واش كاين", "واش تبيعو", "نشوف السلع", "كل السلع", "السلع تاعكم",
                          "السلعة تاعكم", "واش من سلعة", "المنيو", "menu", "قائمة", "القائمة", "الأسعار", "الاسعار",
                          "catalogue", "المنتجات تاعكم", "الخدمات تاعكم", "واش تديرو", "les prix", "واش من خدمات",
                          "les articles", "photos", "تصاور", "صور"]),
    "smalltalk": norm_list(["واش راك", "كيراك", "كي راك", "لاباس", "labas", "cv", "ça va", "ca va", "rak mlih",
                            "كيداير", "واش راكي", "كيراكي", "واشراك", "واشراكي", "واش راكم",
                            "واشراكم", "كيفاش راك", "labes", "cv?", "rak labas"]),
}

INFO_INTENTS = ("sizes", "delivery", "payment", "returns", "hours", "location")


@dataclass
class RuleResult:
    reply: str
    intents: list[str] = field(default_factory=list)
    product: dict | None = None
    needs_human: bool = False
    complaint: bool = False
    unknown: bool = False
    start_order: bool = False


def detect(text: str, products: list[dict]) -> tuple[set[str], list[dict]]:
    n = normalize(text)
    found = {k for k, words in KW.items() if contains_any(n, words)}
    matches = [m.product for m in match_products(text, products)]
    # "كاين التوصيل؟" هي سؤال على التوصيل، ماشي على منتج
    if found & set(INFO_INTENTS) and not matches:
        found.discard("stock")
    return found, matches


def _is_filler(text: str) -> bool:
    """رسالة بلا محتوى: "ok"، "؟"، ايموجي، "اه"..."""
    toks = [t for t in normalize(text).split() if t]
    if not toks:
        return True
    fillers = set(norm_list(["ok", "okay", "oki", "dac", "d'accord", "اه", "ايه", "واه", "مم", "hmm", "اوكي",
                             "باهي", "مليح", "نعم", "yes", "oui", "wah", "تمام", "top"]))
    return all(t in fillers for t in toks)


def faq_match(text: str, faqs: list[dict]) -> dict | None:
    msg = set(content_tokens(text))
    if not msg:
        return None
    best, best_score = None, 0.0
    for f in faqs:
        q = set(content_tokens(f.get("q", "")))
        if not q:
            continue
        score = len(q & msg) / len(q)
        if score > best_score:
            best, best_score = f, score
    return best if best_score >= 0.6 else None


def _pick(r: random.Random, options: list[str]) -> str:
    return r.choice(options)


# ---------------------------------------------------------------------------
# الردود حسب النية
# ---------------------------------------------------------------------------

def _price_line(p: dict) -> str:
    line = f"{p['name']} سومتو {money(p['price'])}"
    if p.get("sizes"):
        line += f" ({p['sizes']})"
    if not p.get("in_stock", True):
        line += "، بصح للأسف ماكانش حالياً"
    return line + "."


def _stock_line(p: dict, r: random.Random) -> str:
    if p.get("in_stock", True):
        line = _pick(r, ["إيه كاين", "إيه متوفر", "إيه مازال"]) + f"، {p['name']} بـ {money(p['price'])}"
        if p.get("sizes"):
            line += f" ({p['sizes']})"
        return line + "."
    return f"للأسف {p['name']} ماكانش حالياً، كي يرجع نخبروك إن شاء الله."


def _sizes_line(p: dict) -> str:
    if p.get("sizes"):
        line = f"{p['name']} كاين فيه: {p['sizes']}."
        if not p.get("in_stock", True):
            line += " بصح حالياً ماكانش فالستوك."
        return line
    return f"{p['name']} ما عندوش مقاسات، حجم واحد."


def _delivery_line(info: dict, shop: dict, text: str = "") -> str:
    if not info.get("delivery_available"):
        where = "، ".join(x for x in (info.get("address"), info.get("city")) if x)
        return "للأسف ما عندناش التوصيل" + (f"، لازم تجي للمحل فـ {where}." if where else ".")
    line = "إيه كاين التوصيل"
    w = find_wilaya(text) if text else None
    areas = info.get("delivery_areas") or ""
    if w:
        everywhere = any(x in areas for x in ("58", "كل", "48", "الولايات", "toutes"))
        if everywhere or normalize(w[1]) in normalize(areas) or normalize(w[2]) in normalize(areas):
            line = f"إيه نوصلو لـ {w[1]}"
        else:
            return f"للأسف ما نوصلوش لـ {w[1]}، نوصلو غير لـ {areas}."
    if info.get("delivery_price"):
        line += f"، السعر {info['delivery_price']}"
    if info.get("delivery_time"):
        line += f"، وياخذ {info['delivery_time']}"
    line += "."
    if info.get("delivery_areas"):
        line += f" نوصلو لـ {info['delivery_areas']}."
    return line


def _hours_line(info: dict) -> str:
    return f"نخدمو {info['hours']}." if info.get("hours") else ""


def _location_line(info: dict) -> str:
    where = "، ".join(x for x in (info.get("city"), info.get("address")) if x)
    if not where:
        return ""
    line = f"راحنا فـ {where}."
    if info.get("maps_url"):
        line += f" الموقع: {info['maps_url']}"
    if info.get("phone"):
        line += f" إذا تلفتّ عيطلنا على {fmt_phone(info['phone'])}."
    return line


def _payment_line(info: dict) -> str:
    return f"الخلاص: {info['payment']}." if info.get("payment") else ""


def _returns_line(info: dict) -> str:
    return f"{info['returns']}." if info.get("returns") else ""


def catalog_line(products: list[dict], limit: int = 8) -> str:
    avail = [p for p in products if p.get("in_stock", True)] or products
    if not avail:
        return ""
    items = "\n".join(f"• {p['name']}: {money(p['price'])}" for p in avail[:limit])
    more = "\n…وكاين حوايج أخرى، سقسيني على واش تحتاج." if len(avail) > limit else ""
    return f"هادو شوية من السلع تاعنا:\n{items}{more}"


def unknown_reply(info: dict, r: random.Random) -> str:
    phone = fmt_phone(info.get("phone", ""))
    if phone:
        return _pick(r, [
            f"سؤال مليح! هاد المعلومة ما عنديش، خليني نسقسي ونرجعلك، ولا عيط مباشرة على {phone}.",
            f"صراحة ما نعرفش بالضبط، باش ما نغلطكش عيط للمحل على {phone} ويجاوبوك.",
        ])
    return "هاد المعلومة ما عنديش ضرك، خليني نسقسي ونرجعلك في أقرب وقت."


def greeting_reply(text: str, shop: dict, r: random.Random) -> str:
    n = normalize(text)
    if "سلام" in n or "salam" in n or "slm" in n:
        head = "وعليكم السلام"
    elif "صباح" in n:
        head = "صباح النور"
    elif "مساء" in n:
        head = "مساء النور"
    else:
        head = _pick(r, ["أهلا وسهلا", "مرحبا بيك"])
    welcome = (shop.get("info") or {}).get("welcome")
    if welcome:
        return f"{head}! {welcome}"
    return f"{head}، مرحبا بيك فـ {shop['name']}! " + _pick(r, ["كيفاش نقدر نعاونك؟", "واش تحتاج؟",
                                                                  "تفضل، واش نقدر ندير عليك؟"])


def greeting_prefix(text: str) -> str:
    n = normalize(text)
    if contains_any(n, norm_list(["السلام عليكم", "سلام", "salam", "slm"])):
        return "وعليكم السلام، "
    if "صباح الخير" in n:
        return "صباح النور، "
    if "مساء الخير" in n:
        return "مساء النور، "
    if contains_any(n, norm_list(["عسلامة"])):
        return "عسلامة، "
    return ""


CONTEXT_INTENTS = {"order", "price", "stock", "sizes"}


def answer(shop: dict, products: list[dict], text: str, rng: random.Random | None = None,
           context_product: dict | None = None) -> RuleResult:
    """context_product: آخر منتج تهدرو عليه (باش "نحب نطلبها" تتفهم)."""
    r = rng or random.Random()
    info = shop.get("info") or {}
    intents, matches = detect(text, products)
    if not matches and context_product and intents & CONTEXT_INTENTS and not intents & set(INFO_INTENTS) - {"sizes"} \
            and "catalog" not in intents:
        matches = [context_product]
    product = matches[0] if len(matches) == 1 else None
    res = RuleResult(reply="", intents=sorted(intents), product=product)

    if text.strip() == VOICE:
        res.reply = "سمحلي، ما نقدرش نسمع الرسائل الصوتية 🙏 اكتبلي سؤالك ونجاوبك دغيا ✍️"
        return res
    if text.strip() == FILE:
        res.complaint = False
        res.unknown = True
        res.reply = ("وصلتني الصورة/الملف ✓ واحد من الفريق يشوفها ويرجعلك. وإذا عندك سؤال على سلعة، "
                     "اكتبلي اسمها ونجاوبك دغيا.")
        return res
    if "human" in intents:
        res.needs_human = True
        res.reply = "مفهوم، راني نحوّلك لواحد من الفريق، يجاوبك في أقرب وقت إن شاء الله 🙏"
        if info.get("phone"):
            res.reply += f" ولا عيط مباشرة على {fmt_phone(info['phone'])}."
        return res

    if "complaint" in intents:
        res.complaint = True
        res.reply = _pick(r, [
            "سمحلنا بزاف على هاد المشكل 🙏 عطيني رقم الطلبية ولا رقم هاتفك ونتبعوها معاك ضرك.",
            "معليش، حقك علينا. ابعثلي اسمك ورقمك ونحلو المشكل في أقرب وقت.",
        ])
        if info.get("phone"):
            res.reply += f" تقدر تاني تعيط على {fmt_phone(info['phone'])}."
        return res

    mode = info.get("mode", "delivery")
    if "order" in intents and (product or mode == "booking"):
        res.start_order = True
        return res  # مدير الطلبيات هو اللي يكمل

    parts: list[str] = []
    for intent, fn in (("delivery", lambda: _delivery_line(info, shop, text)), ("payment", lambda: _payment_line(info)),
                       ("returns", lambda: _returns_line(info)), ("hours", lambda: _hours_line(info)),
                       ("location", lambda: _location_line(info))):
        if intent in intents:
            line = fn()
            if line:
                parts.append(line)
            else:
                res.unknown = True

    if matches:
        targets = matches[:3]
        for p in targets:
            if "sizes" in intents:
                parts.insert(0, _sizes_line(p))
            elif "price" in intents or not ({"stock"} & intents):
                parts.insert(0, _price_line(p))
            else:
                parts.insert(0, _stock_line(p, r))
        if len(targets) == 1 and targets[0].get("in_stock", True) and not (set(INFO_INTENTS) & intents):
            if mode == "booking":
                parts.append(_pick(r, ["تحب تحجز موعد؟", "كاش ما حبيت نحجزولك موعد قولي."]))
            else:
                parts.append(_pick(r, ["تحب تطلب؟", "تحب نحجزهولك؟", "كاش ما حبيت تطلب قولي."]))
    elif not parts and ("price" in intents or "catalog" in intents or "stock" in intents):
        faq = faq_match(text, info.get("faqs") or [])
        if faq:
            parts.append(faq["a"])
        elif "stock" in intents and "catalog" not in intents and "price" not in intents:
            res.unknown = True
            phone = fmt_phone(info.get("phone", ""))
            line = "ما لقيتش هاد الحاجة فالقائمة تاعنا"
            line += f"، باش نتأكدلك عيط على {phone}." if phone else "، خليني نسقسي ونرجعلك."
            parts.append(line + "\n" + catalog_line(products, 6))
        else:
            parts.append(catalog_line(products))
    elif "sizes" in intents:
        with_sizes = [p for p in products if p.get("sizes")]
        parts.append("قولي على واش من منتج تسقسي باش نعطيك المقاسات." if with_sizes else "ما عندناش مقاسات، حجم واحد.")

    if "order" in intents and not product and not res.start_order:
        parts.append("مرحبا! قولي واش حاب تطلب بالضبط. " + catalog_line(products, 6))

    if not parts:
        faq = faq_match(text, info.get("faqs") or [])
        if faq:
            parts.append(faq["a"])
        elif "greeting" in intents:
            res.reply = greeting_reply(text, shop, r)
            return res
        elif "smalltalk" in intents:
            res.reply = _pick(r, ["الحمد لله، ربي يسلمك! كيفاش نقدر نعاونك؟", "لاباس الحمد لله 😊 واش تحتاج؟"])
            return res
        elif _is_filler(text):
            res.reply = _pick(r, ["😊 كيفاش نقدر نعاونك؟ تقدر تسقسيني على السلع، الأسعار، ولا التوصيل.",
                                  "مرحبا! سقسيني على واش تحب: السلع، الأسعار، التوصيل، ولا اطلب مباشرة 👌"])
            return res
        elif "thanks" in intents:
            res.reply = _pick(r, ["الله يسلمك، مرحبا بيك في أي وقت!", "بلا مزية، نحن في الخدمة.",
                                  "وانت تاني، ربي يحفظك. كاش ما حبيت حاجة أخبرنا."])
            return res
        else:
            res.unknown = True
            parts.append(unknown_reply(info, r))

    reply = " ".join(p for p in parts if p)
    if "greeting" in intents:
        reply = greeting_prefix(text) + reply
    res.reply = reply.strip()
    return res
