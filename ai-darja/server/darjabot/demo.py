"""محلات تجريبية باش تجرب النظام دغيا: python -m darjabot.demo"""
from __future__ import annotations

from . import db

DEMO_SHOPS = [
    {
        "slug": "boutique-leila", "name": "بوتيك ليلى للملابس",
        "info": {
            "mode": "delivery", "city": "وهران", "address": "حي العقيد لطفي، قدام الفارماسي",
            "hours": "من 9 الصباح حتى 8 الليل، الجمعة مسكّر", "phone": "0555 12 34 56",
            "delivery_available": True, "delivery_price": "400 دج في وهران و700 دج للولايات الأخرى",
            "delivery_time": "24 حتى 72 ساعة", "delivery_areas": "58 ولاية",
            "payment": "الدفع عند الاستلام، ولا بريدي موب (BaridiMob)",
            "returns": "تقدر تبدّل في 3 أيام إذا السلعة ما تلبستش وعندك التيكي",
            "faqs": [{"q": "كاين تخفيضات؟", "a": "إيه، كل خميس كاين تخفيض 10% على السراول 🎉"}],
        },
        "products": [
            {"name": "سروال جينز", "price": 2800, "sizes": "من 36 حتى 46", "in_stock": True, "aliases": "جينز, jean, سروال"},
            {"name": "جاكيطة جلد", "price": 6500, "sizes": "M, L, XL", "in_stock": True, "aliases": "جاكيطة, veste, blouson"},
            {"name": "روب صيفية", "price": 3200, "sizes": "S, M, L", "in_stock": False, "aliases": "روبة, robe"},
            {"name": "تيشورت قطن", "price": 1200, "sizes": "S حتى XXL", "in_stock": True, "aliases": "تيشورت, tshirt, t-shirt"},
        ],
    },
    {
        "slug": "pizzeria-assil", "name": "بيتزيريا الأصيل",
        "info": {
            "mode": "delivery", "city": "الجزائر العاصمة", "address": "باب الزوار، جنب الجامعة",
            "hours": "من 11 الصباح حتى 11 الليل، كل يوم", "phone": "0770 98 76 54",
            "delivery_available": True, "delivery_price": "200 دج داخل باب الزوار و400 دج للبلديات القريبة",
            "delivery_time": "30 حتى 45 دقيقة", "delivery_areas": "العاصمة برك",
            "payment": "كاش عند الاستلام", "returns": "إذا الطلبية جات غالطة نبدلوهالك بلاش",
        },
        "products": [
            {"name": "بيتزا مارغريتا", "price": 800, "sizes": "صغيرة, متوسطة, كبيرة", "in_stock": True, "aliases": "مارغريتا, margherita"},
            {"name": "بيتزا 4 فرماج", "price": 1300, "sizes": "متوسطة, كبيرة", "in_stock": True, "aliases": "4 فرماج, quatre fromages"},
            {"name": "طاكوس ميكس", "price": 650, "sizes": "M, L, XL", "in_stock": True, "aliases": "طاكوس, tacos"},
            {"name": "شاورمة دجاج", "price": 450, "sizes": "", "in_stock": True, "aliases": "شاورمة, chawarma"},
        ],
    },
    {
        "slug": "clinique-chifa", "name": "عيادة الشفاء لطب الأسنان",
        "info": {
            "mode": "booking", "city": "البليدة", "address": "وسط المدينة، شارع العربي التبسي",
            "hours": "من 8:30 الصباح حتى 5 العشية، السبت حتى الخميس", "phone": "025 41 22 33",
            "delivery_available": False, "payment": "كاش، وكاين تقسيط للتقويم",
            "returns": "الموعد يتلغى بلاش إذا خبّرتنا قبل 24 ساعة",
        },
        "products": [
            {"name": "فحص عام", "price": 1500, "sizes": "", "in_stock": True, "aliases": "فحص, كونسولتاسيون, consultation"},
            {"name": "تنظيف الأسنان", "price": 3000, "sizes": "", "in_stock": True, "aliases": "تنظيف, detartrage"},
            {"name": "حشوة", "price": 4000, "sizes": "", "in_stock": True, "aliases": "plombage, حشو"},
            {"name": "تبييض الأسنان", "price": 15000, "sizes": "", "in_stock": True, "aliases": "تبييض, blanchiment"},
        ],
    },
]


def seed(force: bool = False) -> list[dict]:
    created = []
    for spec in DEMO_SHOPS:
        existing = db.get_shop_by("slug", spec["slug"])
        if existing and not force:
            continue
        if existing:
            db.delete_shop(existing["id"])
        shop = db.create_shop(spec["slug"], spec["name"], spec["info"])
        for p in spec["products"]:
            db.add_product(shop["id"], **p)
        created.append(shop)
    return created


if __name__ == "__main__":
    shops = seed()
    print(f"✅ تزادو {len(shops)} محلات تجريبية" if shops else "المحلات التجريبية موجودة من قبل")
