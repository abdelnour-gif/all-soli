"""التوحيد، الولايات، والاستخراج."""
from darjabot.extract import (find_phone, find_quantity, find_size, is_cancel, is_no, is_yes, looks_like_name,
                              match_products, parse_customer_fields, size_options)
from darjabot.normalize import normalize
from darjabot.wilayas import WILAYAS, find_wilaya

PRODUCTS = [
    {"id": 1, "name": "سروال جينز", "aliases": "جينز, jean", "sizes": "من 36 حتى 46"},
    {"id": 2, "name": "جاكيطة جلد", "aliases": "veste", "sizes": "M, L, XL"},
    {"id": 3, "name": "تيشورت قطن", "aliases": "tshirt", "sizes": "S حتى XXL"},
    {"id": 4, "name": "بقلاوة (الكيلو)", "aliases": "", "sizes": ""},
]


def test_normalize():
    assert normalize("إيــــه") == "ايه"
    assert normalize("بزاااااف") == "بزاف"
    assert normalize("٠٥٥٥") == "0555"
    assert normalize("الطلبيّة!!") == "الطلبيه"


def test_all_58_wilayas():
    assert len(WILAYAS) == 58
    assert [w[0] for w in WILAYAS] == list(range(1, 59))


def test_find_wilaya():
    assert find_wilaya("نسكن في وهران")[0] == 31
    assert find_wilaya("من العاصمة")[0] == 16
    assert find_wilaya("ولاية 19")[0] == 19
    assert find_wilaya("tizi ouzou")[0] == 15
    assert find_wilaya("لبليدة")[0] == 9
    assert find_wilaya("برج باجي مختار")[0] == 50
    assert find_wilaya("سلام خويا") is None


def test_find_phone():
    assert find_phone("رقمي 0555 12 34 56")[0] == "0555123456"
    assert find_phone("+213 661 22 33 44")[0] == "0661223344"
    assert find_phone("٠٧٧٠٩٨٧٦٥٤")[0] == "0770987654"
    assert find_phone("025 41 22 33")[0] == "025412233"
    assert find_phone("السعر 2800 دج") is None


def test_match_products():
    names = lambda t: [m.product["name"] for m in match_products(t, PRODUCTS)]
    assert names("بشحال السروال؟") == ["سروال جينز"]
    assert names("كاين الجينز 40؟") == ["سروال جينز"]
    assert names("حاب نطلب جاكيطة") == ["جاكيطة جلد"]
    assert names("عندكم البقلاوة") == ["بقلاوة (الكيلو)"]
    assert names("tshirt XL") == ["تيشورت قطن"]
    assert names("شحال التوصيل") == []


def test_sizes():
    assert "40" in size_options("من 36 حتى 46")
    assert size_options("S حتى XXL") == ["S", "M", "L", "XL", "XXL"]
    assert find_size("نحب 40", "من 36 حتى 46") == "40"
    assert find_size("xl من فضلك", "M, L, XL") == "XL"
    assert find_size("0555123440", "من 36 حتى 46") is None
    assert find_size("كبيرة", "صغيرة, متوسطة, كبيرة") == "كبيرة"


def test_yes_no_cancel_qty():
    assert is_yes("ايه نأكد") and is_yes("ok") and is_yes("واه")
    assert not is_yes("لا") and is_no("لا بدل") and is_no("non")
    assert is_cancel("خلاص ما حبيتش") and is_cancel("annuler")
    assert find_quantity("زوج سراول") == 2
    assert find_quantity("x3") == 3
    assert find_quantity("سروال") is None


def test_names():
    assert looks_like_name("أمين")
    assert looks_like_name("Yacine Benali")
    assert not looks_like_name("واش كاين")
    assert not looks_like_name("0555")


def test_parse_customer_fields():
    f = parse_customer_fields("محمد بن علي، 0555123456، وهران بير الجير")
    assert (f.phone, f.wilaya, f.address, f.leftovers) == ("0555123456", "وهران", "بير الجير", ["محمد بن علي"])
    f = parse_customer_fields("اسمي كريم ورقمي 0661223344 من سطيف")
    assert (f.name, f.phone, f.wilaya) == ("كريم", "0661223344", "سطيف")
    f = parse_customer_fields("الاسم: سارة\n0770112233\nقسنطينة علي منجلي")
    assert (f.name, f.wilaya, f.address) == ("سارة", "قسنطينة", "علي منجلي")
