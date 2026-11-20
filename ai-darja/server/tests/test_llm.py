"""النموذج + الحارس + تطابق صيغة التدريب."""
import importlib.util
import json
from pathlib import Path

from darjabot import db, engine
from darjabot.guard import check
from darjabot.prompt import system_prompt
from darjabot.providers import LLMReply, ProviderError, _merge_roles


class FakeProvider:
    name = "fake"

    def __init__(self, reply=None, error=False):
        self.reply, self.error, self.calls = reply, error, []

    def generate(self, system, messages):
        self.calls.append((system, messages))
        if self.error:
            raise ProviderError("down")
        return LLMReply(self.reply, "fake")


def test_llm_answer_used(chat):
    fake = FakeProvider("إيه خويا، السروال جينز بـ 2800 دج وكاين من 36 حتى 46 👌")
    engine.set_provider_override(fake)
    r = chat("boutique-leila", "السروال واش من مقاس فيه؟")
    assert r.provider == "fake" and "2800" in r.text
    system, messages = fake.calls[0]
    assert "بوتيك ليلى" in system and messages[-1] == {"role": "user", "content": "السروال واش من مقاس فيه؟"}


def test_invented_price_falls_back_to_rules(chat):
    engine.set_provider_override(FakeProvider("السروال بـ 1500 دج برك!"))
    r = chat("boutique-leila", "بشحال السروال؟")
    assert r.provider == "rules" and "2800 دج" in r.text


def test_provider_error_falls_back(chat):
    engine.set_provider_override(FakeProvider(error=True))
    r = chat("boutique-leila", "وين راكم؟")
    assert r.provider == "rules" and "وهران" in r.text


def test_orders_never_go_to_llm(chat):
    fake = FakeProvider("رد مخترع")
    engine.set_provider_override(fake)
    r = chat("boutique-leila", "حاب نطلب سروال جينز")
    assert r.provider == "orders" and not fake.calls


def test_history_sent_to_llm(chat):
    fake = FakeProvider("مرحبا بيك!")
    engine.set_provider_override(fake)
    chat("boutique-leila", "سلام")
    chat("boutique-leila", "كيراك؟")
    _, messages = fake.calls[-1]
    assert [m["role"] for m in messages] == ["user", "assistant", "user"]


def test_guard():
    shop = {"name": "X", "info": {"phone": "0555 12 34 56", "delivery_available": True, "delivery_price": "400 دج"}}
    sp = system_prompt(shop, [{"name": "سروال", "price": 2800, "sizes": "", "in_stock": True}])
    assert check("السروال بـ 2 800 دج", sp).ok
    assert check("زوج سراول = 5600 دج", sp).ok           # 2 × 2800
    assert check("السروال + التوصيل = 3200 دج", sp).ok    # 2800 + 400
    assert not check("السروال بـ 1900 دج", sp).ok
    assert not check("عيط على 0777 99 88 77", sp).ok
    assert check("عيط على 0555 12 34 56", sp).ok
    assert not check("你好", sp).ok
    assert not check("", sp).ok
    assert not check("x" * 800, sp).ok
    assert not check("<|im_start|>system", sp).ok
    products = [{"id": 1, "name": "سروال جينز", "price": 2800, "aliases": ""},
                {"id": 2, "name": "تيشورت قطن", "price": 1200, "aliases": ""}]
    sp2 = system_prompt(shop, [{**p, "sizes": "", "in_stock": True} for p in products])
    assert check("السروال جينز بـ 2800 دج", sp2, products=products).ok
    assert not check("السروال جينز بـ 1200 دج", sp2, products=products).ok   # سعر منتج آخر
    assert check("التيشورت بـ 1 200 دج، والسروال بـ 2800 دج", sp2, products=products).ok


def test_merge_roles():
    msgs = [{"role": "assistant", "content": "a"}, {"role": "user", "content": "b"}, {"role": "user", "content": "c"},
            {"role": "assistant", "content": "d"}]
    assert _merge_roles(msgs) == [{"role": "user", "content": "b\nc"}, {"role": "assistant", "content": "d"}]


def test_prompt_matches_training_format():
    """الصيغة في السيرفر = الصيغة اللي تدرّب عليها النموذج (generate_data.py)."""
    gen_path = Path(__file__).resolve().parents[2] / "generate_data.py"
    spec = importlib.util.spec_from_file_location("generate_data", gen_path)
    gen = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(gen)
    for s in gen.SHOPS:
        training = gen.SYSTEM_TEMPLATE.format(name=s["name"], card=gen.shop_card(s))
        d = s["delivery"]
        shop = {"name": s["name"], "info": {
            "city": s["city"], "address": s["address"], "hours": s["hours"], "phone": s["phone"],
            "delivery_available": d.get("available", False), "delivery_price": d.get("price", ""),
            "delivery_time": d.get("time", ""), "delivery_areas": d.get("wilayas", ""),
            "payment": s["payment"], "returns": s["returns"]}}
        products = [{"name": p["name"], "price": p["price"], "sizes": p["sizes"], "in_stock": p["stock"]}
                    for p in s["products"]]
        assert system_prompt(shop, products) == training, s["name"]


def test_export_training(chat):
    from darjabot.export import training_examples
    r = chat("boutique-leila", "بشحال السروال؟")
    msgs = db.list_messages(r.conversation["id"])
    db.correct_message(msgs[-1]["id"], "السروال جينز بـ 2800 دج خويا 👌")
    exs = list(training_examples(None, only_reviewed=True))
    assert len(exs) == 1
    roles = [m["role"] for m in exs[0]["messages"]]
    assert roles == ["system", "user", "assistant"]
    assert exs[0]["messages"][-1]["content"] == "السروال جينز بـ 2800 دج خويا 👌"
    json.dumps(exs[0], ensure_ascii=False)
