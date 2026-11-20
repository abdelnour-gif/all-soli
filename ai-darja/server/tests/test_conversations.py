"""محادثات كاملة عبر المحرك (بالمحرك المدمج، بلا نموذج)."""
from darjabot import db


def test_price_and_stock(chat):
    r = chat("boutique-leila", "بشحال السروال؟")
    assert "2800 دج" in r.text and r.provider == "rules"
    r = chat("boutique-leila", "الروب كاينة؟")
    assert "ماكانش" in r.text


def test_info_questions(chat):
    assert "400 دج" in chat("boutique-leila", "شحال التوصيل؟").text
    assert "وهران" in chat("boutique-leila", "وين راكم؟").text
    assert "بريدي موب" in chat("boutique-leila", "كيفاش نخلص؟").text
    assert "ما عندناش التوصيل" in chat("clinique-chifa", "كاين التوصيل؟").text


def test_multi_question(chat):
    r = chat("boutique-leila", "بشحال السروال و كاين التوصيل؟")
    assert "2800 دج" in r.text and "التوصيل" in r.text


def test_greeting_reply(chat):
    assert chat("boutique-leila", "السلام عليكم").text.startswith("وعليكم السلام")


def test_faq(chat):
    assert "خميس" in chat("boutique-leila", "كاين تخفيضات؟").text


def test_unknown_notifies_owner(chat, notifications):
    r = chat("boutique-leila", "تقبلو الشيكات؟")
    assert "0555123456" in r.text
    assert r.events == ["unknown"] and notifications
    assert r.conversation["needs_attention"]


def test_full_order_flow(chat, notifications):
    r = chat("boutique-leila", "حاب نطلب سروال جينز")
    assert "مقاس" in r.text
    r = chat("boutique-leila", "42")
    assert "الاسم" in r.text
    # سؤال وسط الطلبية: يتجاوب والطلبية تبقى
    r = chat("boutique-leila", "بشحال التوصيل لعنابة؟")
    assert "700 دج" in r.text and "باش نكملو الطلبية" in r.text
    r = chat("boutique-leila", "محمد بن علي، 0555123456، عنابة البوني")
    assert "ملخص الطلبية" in r.text and "سروال جينز (42)" in r.text
    r = chat("boutique-leila", "ايه")
    assert "تسجّلت الطلبية" in r.text and r.order
    o = db.list_orders(None)[0]
    assert (o["customer_name"], o["phone"], o["wilaya"], o["address"], o["total"]) == \
        ("محمد بن علي", "0555123456", "عنابة", "البوني", 2800)
    assert o["items"][0]["size"] == "42"
    assert any("طلبية جديدة" in n for n in notifications)


def test_context_product(chat):
    chat("boutique-leila", "بشحال الجاكيطة؟")
    r = chat("boutique-leila", "نحب نطلبها L")
    assert "جاكيطة جلد" in r.text and "الاسم" in r.text


def test_order_multi_items_and_qty(chat):
    chat("boutique-leila", "نحب نكوموندي تيشورت XL و زوج", user="u3")
    chat("boutique-leila", "أمين", user="u3")
    r = chat("boutique-leila", "0550998877 الجزائر باب الزوار", user="u3")
    assert "× 2" in r.text and "2400 دج" in r.text
    r = chat("boutique-leila", "زيدلي جاكيطة L", user="u3")
    assert "8900 دج" in r.text
    chat("boutique-leila", "ايه", user="u3")
    assert db.list_orders(None)[0]["total"] == 8900


def test_order_cancel(chat):
    chat("boutique-leila", "حاب نطلب تيشورت")
    r = chat("boutique-leila", "ألغي")
    assert "لغينا" in r.text
    assert not db.list_conversations(None)[0]["order_state"].get("active")


def test_out_of_stock_order(chat):
    r = chat("boutique-leila", "نحب نطلب روب صيفية")
    assert "ماكانش" in r.text
    assert not db.list_orders(None)


def test_booking_flow_with_correction(chat):
    chat("clinique-chifa", "نحب نحجز موعد تنظيف الأسنان")
    chat("clinique-chifa", "اسمي سارة")
    chat("clinique-chifa", "0661223344")
    r = chat("clinique-chifa", "غدوة الصباح")
    assert "ملخص الحجز" in r.text and "غدوة الصباح" in r.text
    r = chat("clinique-chifa", "لا")
    assert "نعاودو" in r.text
    r = chat("clinique-chifa", "سارة بوزيد 0661223344 السبت العشية")
    assert "سارة بوزيد" in r.text and "السبت العشية" in r.text
    chat("clinique-chifa", "إيه")
    o = db.list_orders(None)[0]
    assert (o["kind"], o["customer_name"], o["preferred_time"]) == ("booking", "سارة بوزيد", "السبت العشية")


def test_booking_service_not_taken_as_name(chat):
    chat("clinique-chifa", "بغيت موعد")
    chat("clinique-chifa", "الفحص")
    r = chat("clinique-chifa", "كريم 0770112233 الخميس 10h")
    assert "الاسم: كريم" in r.text and "فحص عام" in r.text


def test_human_handoff(chat, notifications):
    r = chat("boutique-leila", "نحب نهدر مع صاحب المحل")
    assert r.conversation["status"] == "human" and "human" in r.events
    r = chat("boutique-leila", "واش راك؟")
    assert r.text is None and r.events == ["silent"]


def test_complaint(chat, notifications):
    r = chat("boutique-leila", "الطلبية تاعي ما وصلتش")
    assert "سمحلنا" in r.text or "معليش" in r.text
    assert r.conversation["needs_attention"] and "complaint" in r.events


def test_bot_disabled(chat, shops):
    db.update_shop(shops["boutique-leila"]["id"], bot_enabled=False)
    assert chat("boutique-leila", "سلام").text is None


def test_owner_reply_takes_over(chat):
    from darjabot import engine
    r = chat("boutique-leila", "سلام")
    engine.owner_reply(r.conversation["id"], "مرحبا، أنا صاحب المحل")
    assert db.get_conversation(r.conversation["id"])["status"] == "human"
    assert chat("boutique-leila", "شكرا").text is None


def test_mixed_languages_and_arabizi(chat):
    assert "2800 دج" in chat("boutique-leila", "bch7al jean?").text
    assert "6500 دج" in chat("boutique-leila", "c combien la veste", user="u2").text
    assert "وهران" in chat("boutique-leila", "vous êtes où?", user="u3").text
    assert chat("boutique-leila", "hi", user="u4").text.startswith(("أهلا", "مرحبا"))


def test_delivery_to_named_wilaya(chat):
    assert "نوصلو لـ البويرة" in chat("boutique-leila", "تقدرو تبعثولي للبويرة؟").text
    assert "ما نوصلوش لـ وهران" in chat("pizzeria-assil", "توصلو لوهران؟").text


def test_filler_does_not_alert_owner(chat, notifications):
    for i, t in enumerate(["ok", "؟", "😂😂"]):
        r = chat("boutique-leila", t, user=f"f{i}")
        assert "كيفاش نقدر نعاونك" in r.text or "سقسيني" in r.text
    assert not notifications


def test_waiting_delivery_is_complaint(chat):
    r = chat("boutique-leila", "راني نستنى في الليفرور من البارح")
    assert r.conversation["needs_attention"] and "complaint" in r.events


def test_quantity_and_size_from_first_message(chat):
    r = chat("boutique-leila", "نحب نطلب 2 تيشورتات M")
    assert "(M) × 2" in r.text and "2400 دج" in r.text
    r = chat("boutique-leila", "ابعثلي الجينز 38", user="u9")
    assert "(38)" in r.text and "× " not in r.text
