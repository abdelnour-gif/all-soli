#!/usr/bin/env python3
"""
مولّد بيانات تدريب لنموذج خدمة الزبائن بالدارجة الجزائرية.

الفكرة: كل مثال فيه "بطاقة معلومات المحل" في رسالة النظام (system)،
وسؤال الزبون بالدارجة، والرد المثالي المبني على معلومات المحل فقط.
بهذه الطريقة يتعلم النموذج أن يجاوب من معلومات أي محل تعطيه له،
وألا يخترع معلومات غير موجودة.

الاستعمال:
    python3 generate_data.py                 # يولّد data/train.jsonl و data/test.jsonl
    python3 generate_data.py --n 3000        # عدد الأمثلة
    python3 generate_data.py --extra data/my_examples.jsonl   # يضيف أمثلتك الحقيقية
"""
import argparse
import json
import random
from pathlib import Path

# ---------------------------------------------------------------------------
# 1) المحلات (يمكنك إضافة محلات حقيقية هنا)
# ---------------------------------------------------------------------------
SHOPS = [
    {
        "name": "بوتيك ليلى للملابس", "type": "clothes", "city": "وهران",
        "address": "حي العقيد لطفي، قدام الفارماسي", "hours": "من 9 الصباح حتى 8 الليل، الجمعة مسكّر",
        "delivery": {"available": True, "price": "400 دج في وهران و700 دج للولايات الأخرى", "time": "24 حتى 72 ساعة", "wilayas": "58 ولاية"},
        "payment": "الدفع عند الاستلام، ولا بريدي موب (BaridiMob)",
        "returns": "تقدر تبدّل في 3 أيام إذا السلعة ما تلبستش وعندك التيكي",
        "products": [
            {"name": "سروال جينز", "price": 2800, "sizes": "من 36 حتى 46", "stock": True},
            {"name": "جاكيطة جلد", "price": 6500, "sizes": "M, L, XL", "stock": True},
            {"name": "روب صيفية", "price": 3200, "sizes": "S, M, L", "stock": False},
            {"name": "تيشورت قطن", "price": 1200, "sizes": "S حتى XXL", "stock": True},
        ],
        "phone": "0555 12 34 56",
    },
    {
        "name": "بيتزيريا الأصيل", "type": "food", "city": "الجزائر العاصمة",
        "address": "باب الزوار، جنب الجامعة", "hours": "من 11 الصباح حتى 11 الليل، كل يوم",
        "delivery": {"available": True, "price": "200 دج داخل باب الزوار و400 دج للبلديات القريبة", "time": "30 حتى 45 دقيقة", "wilayas": "العاصمة برك"},
        "payment": "كاش عند الاستلام",
        "returns": "إذا الطلبية جات غالطة نبدلوهالك بلاش",
        "products": [
            {"name": "بيتزا مارغريتا", "price": 800, "sizes": "صغيرة ومتوسطة وكبيرة", "stock": True},
            {"name": "بيتزا 4 فرماج", "price": 1300, "sizes": "متوسطة وكبيرة", "stock": True},
            {"name": "طاكوس ميكس", "price": 650, "sizes": "M و L و XL", "stock": True},
            {"name": "شاورمة دجاج", "price": 450, "sizes": "حجم واحد", "stock": True},
        ],
        "phone": "0770 98 76 54",
    },
    {
        "name": "فون ستور سطيف", "type": "phones", "city": "سطيف",
        "address": "عين الفوارة، الطابق الأرضي تاع السنتر", "hours": "من 9 الصباح حتى 7 العشية، الجمعة من 2 العشية",
        "delivery": {"available": True, "price": "600 دج لكل الولايات", "time": "48 ساعة", "wilayas": "58 ولاية"},
        "payment": "عند الاستلام، CCP، ولا بريدي موب",
        "returns": "ضمان 6 أشهر على التيليفونات، والإكسسوارات بلا ضمان",
        "products": [
            {"name": "سامسونغ A55", "price": 62000, "sizes": "128 و256 جيغا", "stock": True},
            {"name": "ريدمي نوت 13", "price": 38000, "sizes": "128 جيغا", "stock": True},
            {"name": "آيفون 13 مستعمل", "price": 85000, "sizes": "128 جيغا", "stock": False},
            {"name": "شارجور سريع 33W", "price": 1800, "sizes": "Type-C", "stock": True},
        ],
        "phone": "0661 22 33 44",
    },
    {
        "name": "كوسمتيك نور", "type": "cosmetics", "city": "قسنطينة",
        "address": "سيدي مبروك، قريب من المسجد", "hours": "من 10 الصباح حتى 7 العشية، الجمعة مسكّر",
        "delivery": {"available": True, "price": "500 دج، ومجاني فوق 5000 دج", "time": "2 حتى 4 أيام", "wilayas": "58 ولاية"},
        "payment": "الدفع عند الاستلام",
        "returns": "ما نرجعوش المنتجات المفتوحة، المسكّرة تقدر ترجعها في يومين",
        "products": [
            {"name": "كريم مرطب", "price": 1500, "sizes": "50 مل", "stock": True},
            {"name": "سيروم فيتامين C", "price": 2400, "sizes": "30 مل", "stock": True},
            {"name": "بارفان نسائي", "price": 4500, "sizes": "100 مل", "stock": False},
            {"name": "واقي شمس", "price": 1900, "sizes": "50 مل", "stock": True},
        ],
        "phone": "0698 45 67 89",
    },
    {
        "name": "عيادة الشفاء لطب الأسنان", "type": "clinic", "city": "البليدة",
        "address": "وسط المدينة، شارع العربي التبسي", "hours": "من 8:30 الصباح حتى 5 العشية، السبت حتى الخميس",
        "delivery": {"available": False},
        "payment": "كاش، وكاين تقسيط للتقويم",
        "returns": "الموعد يتلغى بلاش إذا خبّرتنا قبل 24 ساعة",
        "products": [
            {"name": "فحص عام", "price": 1500, "sizes": "", "stock": True},
            {"name": "تنظيف الأسنان", "price": 3000, "sizes": "", "stock": True},
            {"name": "حشوة", "price": 4000, "sizes": "", "stock": True},
            {"name": "تبييض الأسنان", "price": 15000, "sizes": "", "stock": True},
        ],
        "phone": "025 41 22 33",
    },
    {
        "name": "حلويات الدار", "type": "sweets", "city": "تيزي وزو",
        "address": "المدينة الجديدة، قدام البريد", "hours": "من 8 الصباح حتى 9 الليل",
        "delivery": {"available": True, "price": "300 دج في تيزي، وما نوصلوش برا الولاية", "time": "نفس النهار", "wilayas": "تيزي وزو برك"},
        "payment": "كاش، والطلبيات الكبار بعربون 30%",
        "returns": "ما كانش ترجيع، بصح إذا كاين مشكل نحلوه",
        "products": [
            {"name": "بقلاوة (الكيلو)", "price": 3500, "sizes": "", "stock": True},
            {"name": "مقروط (الكيلو)", "price": 1800, "sizes": "", "stock": True},
            {"name": "طورطة عيد ميلاد", "price": 4000, "sizes": "8 و12 و20 شخص", "stock": True},
            {"name": "قريوش (الكيلو)", "price": 1600, "sizes": "", "stock": False},
        ],
        "phone": "0550 66 77 88",
    },
]

# ---------------------------------------------------------------------------
# 2) أدوات مساعدة
# ---------------------------------------------------------------------------
GREET_PREFIX = ["", "", "", "السلام عليكم، ", "سلام، ", "صباح الخير، ", "مساء الخير، ", "عسلامة، ", "خويا ", "ختي "]
EMOJI = ["", "", "", " 😊", " 👌", " 🙏", " ✅"]
CLOSERS = ["", "", " كاش ما حبيت حاجة أخرى؟", " مرحبا بيك في أي وقت.", " نحن في الخدمة.", " كاش سؤال آخر؟"]


def money(n: int) -> str:
    return f"{n:,}".replace(",", " ") + " دج"


def shop_card(s: dict) -> str:
    lines = [
        f"اسم المحل: {s['name']}",
        f"المدينة: {s['city']}",
        f"العنوان: {s['address']}",
        f"أوقات العمل: {s['hours']}",
        f"الهاتف: {s['phone']}",
    ]
    d = s["delivery"]
    if d.get("available"):
        lines.append(f"التوصيل: متوفر، السعر {d['price']}، المدة {d['time']}، المناطق: {d['wilayas']}")
    else:
        lines.append("التوصيل: غير متوفر")
    lines.append(f"الدفع: {s['payment']}")
    lines.append(f"الترجيع/الضمان: {s['returns']}")
    lines.append("المنتجات/الخدمات:")
    for p in s["products"]:
        extra = f" | {p['sizes']}" if p["sizes"] else ""
        stock = "متوفر" if p["stock"] else "غير متوفر حالياً"
        lines.append(f"- {p['name']}: {money(p['price'])}{extra} | {stock}")
    return "\n".join(lines)


SYSTEM_TEMPLATE = (
    "أنت مساعد خدمة الزبائن لمحل \"{name}\". تهدر بالدارجة الجزائرية بطريقة مؤدبة وقصيرة وواضحة. "
    "جاوب غير من المعلومات اللي تحت. إذا المعلومة ما كانتش، قول للزبون بلي راح تسقسي وترجعلو، "
    "ولا عطيه رقم الهاتف. ما تخترعش أسعار ولا معلومات.\n\n"
    "معلومات المحل:\n{card}"
)

# ---------------------------------------------------------------------------
# 3) النوايا (intents): كل واحدة ترجع (سؤال، جواب)
# ---------------------------------------------------------------------------

def intent_price(s, r):
    p = r.choice(s["products"])
    q = r.choice([
        "بشحال {p}؟", "شحال سومة {p}؟", "قداش {p}؟", "السعر تاع {p} من فضلك",
        "بقداه {p}؟", "{p} بشحال راهو؟", "حاب نعرف سعر {p}", "prix تاع {p}؟",
    ]).format(p=p["name"])
    a = f"{p['name']} سومتو {money(p['price'])}."
    if p["sizes"]:
        a += f" كاين {p['sizes']}."
    if not p["stock"]:
        a += " بصح للأسف راهو ماكانش حالياً، كي يرجع نخبروك إذا حبيت."
    return q, a


def intent_stock(s, r):
    p = r.choice(s["products"])
    q = r.choice([
        "كاين {p}؟", "{p} راهو متوفر؟", "عندكم {p}؟", "مازال {p}؟", "نلقى عندكم {p}؟",
    ]).format(p=p["name"])
    if p["stock"]:
        a = r.choice(["إيه كاين", "إيه متوفر", "إيه مازال"]) + f"، {p['name']} بـ {money(p['price'])}."
        if p["sizes"]:
            a += f" ({p['sizes']})."
        a += r.choice([" تحب نحجزهولك؟", " تحب تطلب؟", ""])
    else:
        a = f"للأسف {p['name']} ماكانش حالياً. " + r.choice([
            "خليلنا رقمك ونخبروك كي يرجع.", "كي يرجع نخبروك إن شاء الله.",
            "نقدر نقترحلك حاجة أخرى إذا حبيت.",
        ])
    return q, a


def intent_sizes(s, r):
    cands = [p for p in s["products"] if p["sizes"]]
    if not cands:
        return intent_price(s, r)
    p = r.choice(cands)
    q = r.choice([
        "واش كاين من مقاسات فـ {p}؟", "{p} واش من تايّات كاين؟", "شحال من حجم كاين فـ {p}؟",
        "كاين الطاي تاعي فـ {p}؟", "{p} فيه أحجام؟",
    ]).format(p=p["name"])
    a = f"{p['name']} كاين فيه: {p['sizes']}."
    if not p["stock"]:
        a += " بصح حالياً ماكانش فالستوك."
    return q, a


def intent_delivery(s, r):
    q = r.choice([
        "كاين التوصيل؟", "توصلو؟", "تبعثو للولايات؟", "شحال التوصيل؟", "livraison كاين؟",
        "توصلو لعنابة؟", "قداش ياخذ التوصيل وقت؟", "واش تديرو التوصيل للدار؟",
    ])
    d = s["delivery"]
    if not d.get("available"):
        return q, f"لا للأسف، ما عندناش التوصيل. لازم تجي للمحل فـ {s['address']}، {s['city']}."
    a = f"إيه كاين التوصيل، السعر {d['price']}، وياخذ {d['time']}. نوصلو لـ {d['wilayas']}."
    return q, a


def intent_hours(s, r):
    q = r.choice([
        "وقتاش تحلو؟", "واش من ساعة تسكرو؟", "راكم حالّين ضرك؟", "الجمعة تخدمو؟",
        "أوقات العمل تاعكم؟", "وقتاش نقدر نجي؟", "تحلو غدوة؟",
    ])
    return q, f"نخدمو {s['hours']}."


def intent_location(s, r):
    q = r.choice([
        "وين راكم؟", "وين جا المحل؟", "العنوان من فضلك", "كيفاش نوصلكم؟", "وين نلقاكم فـ {c}؟",
    ]).format(c=s["city"])
    return q, f"راحنا فـ {s['city']}، {s['address']}. إذا تلفتّ عيطلنا على {s['phone']}."


def intent_payment(s, r):
    q = r.choice([
        "كيفاش نخلص؟", "نقدر نخلص بالبريدي موب؟", "الخلاص عند الاستلام؟", "تقبلو CCP؟",
        "لازم نخلص قبل؟", "واش من طريقة دفع كاينة؟",
    ])
    return q, f"الخلاص: {s['payment']}."


def intent_returns(s, r):
    q = r.choice([
        "نقدر نبدل إذا ما عجبنيش؟", "كاين الترجيع؟", "كاين ضمان؟", "إذا كان فيه مشكل واش ندير؟",
        "نقدر نرجع السلعة؟", "واش السياسة تاعكم فالتبديل؟",
    ])
    return q, f"{s['returns']}."


def intent_order(s, r):
    p = r.choice([x for x in s["products"] if x["stock"]])
    q = r.choice([
        "حاب نطلب {p}", "نحب ناخذ {p}", "حطلي {p} من فضلك", "كيفاش نطلب {p}؟", "نكوموندي {p}",
    ]).format(p=p["name"])
    if s["type"] == "clinic":
        a = f"مرحبا! باش نحجزولك موعد لـ {p['name']} ({money(p['price'])})، عطيني اسمك الكامل ورقم هاتفك والنهار اللي يناسبك."
    elif s["delivery"].get("available"):
        a = f"مرحبا بيك! {p['name']} بـ {money(p['price'])}. باش نكملو الطلبية عطيني: الاسم، رقم الهاتف، الولاية والبلدية"
        a += "، والمقاس." if p["sizes"] and s["type"] == "clothes" else "."
    else:
        a = f"مرحبا! {p['name']} بـ {money(p['price'])}. عطيني اسمك ورقمك ونوجدوهالك، وتجي تديها من المحل."
    return q, a


def intent_greeting(s, r):
    q = r.choice(["السلام عليكم", "سلام", "صباح الخير", "عسلامة", "مرحبا", "سلام خويا", "bonjour"])
    a = r.choice([
        f"وعليكم السلام، مرحبا بيك فـ {s['name']}! كيفاش نقدر نعاونك؟",
        f"أهلا وسهلا! هنا {s['name']}، واش تحتاج؟",
        f"مرحبا بيك! تفضل، كيفاش نعاونك؟",
    ])
    return q, a


def intent_thanks(s, r):
    q = r.choice(["يعطيك الصحة", "شكرا بزاف", "صحيت", "merci", "بارك الله فيك", "ربي يحفظك"])
    a = r.choice([
        "الله يسلمك، مرحبا بيك في أي وقت!", "بلا مزية، نحن في الخدمة.",
        "وانت تاني، ربي يحفظك. كاش ما حبيت حاجة أخبرنا.",
    ])
    return q, a


def intent_unknown(s, r):
    q = r.choice([
        "كاين تخفيضات هاد الأسبوع؟", "تبيعو بالجملة؟", "كاين فرع آخر؟", "تخدمو بالكريدي؟",
        "واش من ماركة عندكم؟", "كاين بطاقة وفاء؟", "تقبلو تبدلو بالدوفيز؟",
    ])
    a = r.choice([
        f"سؤال مليح! هاد المعلومة ما عنديش، خليني نسقسي ونرجعلك، ولا عيط مباشرة على {s['phone']}.",
        f"صراحة ما نعرفش بالضبط، باش ما نغلطكش عيط للمحل على {s['phone']} ويجاوبوك.",
    ])
    return q, a


def intent_complaint(s, r):
    q = r.choice([
        "الطلبية تاعي ما وصلتش", "جاتني السلعة مكسورة", "عيطت بزاف وحد ما رد",
        "راني مقلق، ليفريزون تعطلت", "السلعة ماشي كيما فالتصويرة",
    ])
    a = r.choice([
        "سمحلنا بزاف على هاد المشكل 🙏 عطيني رقم الطلبية ولا رقم هاتفك ونتبعوها معاك ضرك.",
        f"معليش، حقك علينا. ابعثلي اسمك ورقمك ونحلو المشكل في أقرب وقت، ولا عيط على {s['phone']}.",
    ])
    return q, a


def intent_offtopic(s, r):
    q = r.choice([
        "واش راك تشوف فالماتش تاع البارح؟", "عاونّي نكتب ديفوار", "شكون انت؟ روبو؟",
        "واش هو الطقس غدوة؟", "عطيني نكتة",
    ])
    a = r.choice([
        f"أنا المساعد تاع {s['name']}، نقدر نعاونك غير فالأسئلة على المحل، السلع والأسعار والتوصيل 😊",
        f"هههه سمحلي، أنا هنا باش نعاونك فاللي يخص {s['name']} برك. واش تحتاج من عندنا؟",
    ])
    return q, a


INTENTS = [
    (intent_price, 18), (intent_stock, 12), (intent_sizes, 7), (intent_delivery, 11),
    (intent_hours, 7), (intent_location, 6), (intent_payment, 6), (intent_returns, 5),
    (intent_order, 10), (intent_greeting, 5), (intent_thanks, 3), (intent_unknown, 5),
    (intent_complaint, 3), (intent_offtopic, 2),
]


def make_example(r: random.Random) -> dict:
    s = r.choice(SHOPS)
    fns, weights = zip(*INTENTS)
    fn = r.choices(fns, weights=weights)[0]
    q, a = fn(s, r)
    if fn not in (intent_greeting, intent_thanks):
        prefix = r.choice(GREET_PREFIX)
        q = prefix + q
        reply_to_greet = {"السلام عليكم، ": "وعليكم السلام، ", "سلام، ": "سلام، ",
                          "صباح الخير، ": "صباح النور، ", "مساء الخير، ": "مساء النور، ",
                          "عسلامة، ": "عسلامة، "}
        if prefix in reply_to_greet and r.random() < 0.7:
            a = reply_to_greet[prefix] + a
        a = a + r.choice(EMOJI) + r.choice(CLOSERS)
    return {"messages": [
        {"role": "system", "content": SYSTEM_TEMPLATE.format(name=s["name"], card=shop_card(s))},
        {"role": "user", "content": q.strip()},
        {"role": "assistant", "content": a.strip()},
    ]}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--n", type=int, default=2000, help="عدد الأمثلة المولّدة")
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--test-ratio", type=float, default=0.05)
    ap.add_argument("--extra", type=str, default="data/my_examples.jsonl", help="أمثلتك الحقيقية (اختياري)")
    ap.add_argument("--out", type=str, default="data")
    args = ap.parse_args()

    r = random.Random(args.seed)
    seen, rows = set(), []
    tries = 0
    while len(rows) < args.n and tries < args.n * 20:
        tries += 1
        ex = make_example(r)
        key = (ex["messages"][0]["content"][:40], ex["messages"][1]["content"], ex["messages"][2]["content"])
        if key in seen:
            continue
        seen.add(key)
        rows.append(ex)

    extra = Path(args.extra)
    n_extra = 0
    if extra.exists():
        for line in extra.read_text(encoding="utf-8").splitlines():
            if line.strip():
                ex = json.loads(line)
                # الأمثلة الحقيقية أهم: نكررها 3 مرات
                rows.extend([ex] * 3)
                n_extra += 1

    r.shuffle(rows)
    n_test = max(20, int(len(rows) * args.test_ratio))
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for name, part in (("test.jsonl", rows[:n_test]), ("train.jsonl", rows[n_test:])):
        with open(out / name, "w", encoding="utf-8") as f:
            for ex in part:
                f.write(json.dumps(ex, ensure_ascii=False) + "\n")
    print(f"✅ {len(rows) - n_test} مثال تدريب، {n_test} مثال اختبار ({n_extra} مثال حقيقي من {extra})")


if __name__ == "__main__":
    main()
