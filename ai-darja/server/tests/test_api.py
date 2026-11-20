"""السيرفر: الدخول، الصلاحيات، الدردشة العامة، والـ webhooks."""
import hashlib
import hmac
import json

import pytest
from fastapi.testclient import TestClient

from darjabot import channels, db


@pytest.fixture
def client(shops):
    from darjabot import app as appmod
    appmod._public_limiter = None
    with TestClient(appmod.app) as c:
        yield c


@pytest.fixture
def admin(client):
    r = client.post("/api/auth/login", json={"password": "test-pass"})
    assert r.status_code == 200
    return client


@pytest.fixture
def sent(monkeypatch):
    out = []
    monkeypatch.setattr(channels, "send", lambda shop, conv, text: out.append((conv["channel"], conv["external_id"], text)) or True)
    from darjabot import app as appmod
    monkeypatch.setattr(appmod.channels, "send", channels.send)
    return out


def test_login(client):
    assert client.get("/api/shops").status_code == 401
    assert client.post("/api/auth/login", json={"password": "wrong"}).status_code == 401
    assert client.post("/api/auth/login", json={"password": "test-pass"}).status_code == 200
    assert client.get("/api/auth/me").json()["role"] == "admin"


def test_admin_crud(admin):
    r = admin.post("/api/shops", json={"slug": "boutique-amel", "name": "بوتيك أمال",
                                        "info": {"mode": "delivery", "delivery_available": True, "phone": "0550"}})
    assert r.status_code == 200, r.text
    sid = r.json()["id"]
    assert "telegram_token" not in r.json() and "owner_key" in r.json()
    assert admin.post("/api/shops", json={"slug": "boutique-amel", "name": "x x"}).status_code == 409
    assert admin.post("/api/shops", json={"slug": "Bad Slug!", "name": "x x"}).status_code == 400
    p = admin.post(f"/api/shops/{sid}/products", json={"name": "صباط", "price": 4500, "sizes": "من 39 حتى 45"}).json()
    assert admin.put(f"/api/products/{p['id']}", json={"price": 4200}).json()["price"] == 4200
    r = admin.post(f"/api/shops/{sid}/test-chat", json={"text": "بشحال الصباط؟"}).json()
    assert "4200 دج" in r["reply"]
    assert "صباط: 4200 دج" in admin.get(f"/api/shops/{sid}/prompt").text
    assert admin.delete(f"/api/shops/{sid}").json()["ok"]


def test_owner_scope(client, shops):
    leila, pizza = shops["boutique-leila"], shops["pizzeria-assil"]
    h = {"X-Owner-Key": leila["owner_key"]}
    lst = client.get("/api/shops", headers=h).json()
    assert [s["id"] for s in lst] == [leila["id"]] and "owner_key" not in lst[0]
    assert client.get(f"/api/shops/{pizza['id']}", headers=h).status_code == 404
    assert client.post("/api/shops", headers=h, json={"slug": "abc-def", "name": "xx"}).status_code == 403
    assert client.delete(f"/api/shops/{leila['id']}", headers=h).status_code == 403
    pizza_product = db.list_products(pizza["id"])[0]
    assert client.put(f"/api/products/{pizza_product['id']}", headers=h, json={"price": 1}).status_code == 404
    assert client.get("/api/shops", headers={"X-Owner-Key": "nope"}).status_code == 401


def test_secrets_never_returned(admin, shops):
    sid = shops["boutique-leila"]["id"]
    r = admin.put(f"/api/shops/{sid}/channels", json={"telegram_token": "123:SECRET", "wa_token": "WA_SECRET"}).json()
    assert r["telegram_token_set"] and r["wa_token_set"]
    assert "SECRET" not in json.dumps(admin.get("/api/shops").json())


def test_public_chat_and_owner_reply(client, admin):
    r = client.post("/api/public/boutique-leila/chat", json={"text": "نحب نهدر مع المسؤول"}).json()
    vid = r["visitor_id"]
    assert r["human"]
    conv = admin.get("/api/conversations?attention=true").json()[0]
    admin.post(f"/api/conversations/{conv['id']}/reply", json={"text": "سلام، أنا صاحب المحل"})
    msgs = client.get(f"/api/public/boutique-leila/messages?visitor_id={vid}").json()["messages"]
    assert msgs[-1] == {"id": msgs[-1]["id"], "role": "owner", "text": "سلام، أنا صاحب المحل"}
    after = msgs[-1]["id"]
    assert client.get(f"/api/public/boutique-leila/messages?visitor_id={vid}&after={after}").json()["messages"] == []


def test_public_rate_limit(client, monkeypatch):
    from darjabot import app as appmod
    from darjabot.auth import RateLimiter
    appmod._public_limiter = RateLimiter(2)
    for _ in range(2):
        assert client.post("/api/public/boutique-leila/chat", json={"text": "سلام"}).status_code == 200
    assert client.post("/api/public/boutique-leila/chat", json={"text": "سلام"}).status_code == 429


def test_chat_page_escapes(admin, client):
    admin.post("/api/shops", json={"slug": "xss-shop", "name": "<script>alert(1)</script>"})
    html = client.get("/c/xss-shop").text
    assert "<script>alert(1)</script>" not in html and "&lt;script&gt;" in html


def test_telegram_webhook(client, shops, sent):
    shop = shops["boutique-leila"]
    upd = {"update_id": 1, "message": {"chat": {"id": 42, "type": "private"}, "from": {"first_name": "Amine"},
                                       "text": "بشحال السروال؟"}}
    assert client.post("/webhook/telegram/boutique-leila", json=upd).status_code == 403
    r = client.post("/webhook/telegram/boutique-leila", json=upd,
                    headers={"X-Telegram-Bot-Api-Secret-Token": shop["telegram_secret"]})
    assert r.status_code == 200
    assert sent and sent[0][0] == "telegram" and sent[0][1] == "42" and "2800 دج" in sent[0][2]
    conv = db.list_conversations([shop["id"]])[0]
    assert conv["customer_name"] == "Amine"


def test_meta_verify(client):
    q = {"hub.mode": "subscribe", "hub.verify_token": "verify-me", "hub.challenge": "12345"}
    assert client.get("/webhook/meta", params=q).text == "12345"
    q["hub.verify_token"] = "bad"
    assert client.get("/webhook/meta", params=q).status_code == 403


def _wa_payload(pnid, frm, text):
    return {"object": "whatsapp_business_account", "entry": [{"changes": [{"value": {
        "metadata": {"phone_number_id": pnid}, "contacts": [{"wa_id": frm, "profile": {"name": "Sara"}}],
        "messages": [{"from": frm, "type": "text", "text": {"body": text}}]}}]}]}


def test_whatsapp_webhook(admin, shops, sent):
    sid = shops["pizzeria-assil"]["id"]
    admin.put(f"/api/shops/{sid}/channels", json={"wa_phone_number_id": "PN1", "wa_token": "T"})
    r = admin.post("/webhook/meta", json=_wa_payload("PN1", "213555000111", "بشحال الطاكوس؟"))
    assert r.status_code == 200
    assert sent[0][0] == "whatsapp" and "650 دج" in sent[0][2]


def test_whatsapp_signature(admin, shops, sent, monkeypatch):
    monkeypatch.setenv("META_APP_SECRET", "s3cret")
    from darjabot import config
    config.reset_settings()
    sid = shops["pizzeria-assil"]["id"]
    admin.put(f"/api/shops/{sid}/channels", json={"wa_phone_number_id": "PN1"})
    body = json.dumps(_wa_payload("PN1", "2135", "سلام")).encode()
    assert admin.post("/webhook/meta", content=body, headers={"X-Hub-Signature-256": "sha256=bad"}).status_code == 403
    sig = "sha256=" + hmac.new(b"s3cret", body, hashlib.sha256).hexdigest()
    assert admin.post("/webhook/meta", content=body, headers={"X-Hub-Signature-256": sig,
                                                                "Content-Type": "application/json"}).status_code == 200


def test_messenger_webhook(admin, shops, sent):
    sid = shops["clinique-chifa"]["id"]
    admin.put(f"/api/shops/{sid}/channels", json={"fb_page_id": "PAGE9", "fb_page_token": "T"})
    payload = {"object": "page", "entry": [{"id": "PAGE9", "messaging": [
        {"sender": {"id": "USER7"}, "message": {"text": "وقتاش تحلو؟"}},
        {"sender": {"id": "PAGE9"}, "message": {"text": "echo", "is_echo": True}}]}]}
    admin.post("/webhook/meta", json=payload)
    assert len(sent) == 1 and sent[0][:2] == ("messenger", "USER7") and "8:30" in sent[0][2]


def test_orders_api_and_csv(admin, client):
    vid = None
    for t in ["حاب نطلب تيشورت", "M", "يوسف 0550112233 وهران السانيا", "ايه"]:
        r = client.post("/api/public/boutique-leila/chat", json={"text": t, "visitor_id": vid}).json()
        vid = r["visitor_id"]
    orders = admin.get("/api/orders").json()
    assert len(orders) == 1 and orders[0]["customer_name"] == "يوسف"
    oid = orders[0]["id"]
    assert admin.put(f"/api/orders/{oid}", json={"status": "shipped"}).json()["status"] == "shipped"
    assert admin.put(f"/api/orders/{oid}", json={"status": "lost"}).status_code == 422
    csv = admin.get("/api/orders.csv").text
    assert "يوسف" in csv and "0550112233" in csv
    assert admin.get("/api/stats").json()["orders"] == 1


def test_export_endpoint(admin):
    admin.post("/api/public/boutique-leila/chat", json={"text": "بشحال السروال؟"})
    lines = admin.get("/api/export/training.jsonl").text.strip().splitlines()
    assert lines and json.loads(lines[0])["messages"][0]["role"] == "system"


def test_system_info(admin):
    s = admin.get("/api/system").json()
    assert s["provider"] == "rules" and s["health"]["ok"] and s["meta_verify_token"] == "verify-me"
