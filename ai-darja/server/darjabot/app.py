"""السيرفر (FastAPI): لوحة التحكم، دردشة الموقع، ووصلات تيليغرام/واتساب/ماسنجر.

التشغيل:  uvicorn darjabot.app:app --host 0.0.0.0 --port 8000
"""
from __future__ import annotations

import csv
import io
import json
import logging
import re
import secrets
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

from fastapi import BackgroundTasks, Depends, FastAPI, HTTPException, Query, Request, Response
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse, PlainTextResponse, RedirectResponse
from pydantic import BaseModel, Field

from . import __version__, channels, db, engine
from .auth import (COOKIE, SESSION_SECONDS, Principal, RateLimiter, check_password, client_ip, make_session,
                   principal, require_admin, require_shop)
from .channels import meta, telegram
from .config import get_settings
from .export import training_jsonl
from .prompt import system_prompt
from .providers import get_provider

log = logging.getLogger("darjabot")
STATIC = Path(__file__).parent / "static"
SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$")
VISITOR_RE = re.compile(r"^[A-Za-z0-9_-]{8,64}$")

@asynccontextmanager
async def lifespan(_app: FastAPI):
    _startup()
    yield


app = FastAPI(title="Darja Bot", version=__version__, docs_url="/api/docs", redoc_url=None, lifespan=lifespan)

_public_limiter: RateLimiter | None = None
_login_limiter = RateLimiter(10)


def public_limiter() -> RateLimiter:
    global _public_limiter
    if _public_limiter is None:
        _public_limiter = RateLimiter(get_settings().public_rate_limit)
    return _public_limiter


def _startup() -> None:
    s = get_settings()
    logging.basicConfig(level=logging.DEBUG if s.extra.get("debug") else logging.INFO,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    with db.connect():
        pass
    if not db.list_shops():
        from .demo import seed
        seed()
        log.info("تزادو محلات تجريبية")
    log.info("Darja Bot %s | provider=%s | admin password file: %s", __version__, s.llm_provider,
             s.data_dir / "admin_password.txt")


# ---------------------------------------------------------------------------
# نماذج المدخلات
# ---------------------------------------------------------------------------

class LoginIn(BaseModel):
    password: str = Field(max_length=200)


class FAQ(BaseModel):
    q: str = Field(max_length=300)
    a: str = Field(max_length=1000)


class ShopInfo(BaseModel):
    mode: str = Field("delivery", pattern="^(delivery|pickup|booking)$")
    city: str = Field("", max_length=100)
    address: str = Field("", max_length=300)
    hours: str = Field("", max_length=300)
    phone: str = Field("", max_length=50)
    delivery_available: bool = False
    delivery_price: str = Field("", max_length=300)
    delivery_time: str = Field("", max_length=200)
    delivery_areas: str = Field("", max_length=300)
    payment: str = Field("", max_length=300)
    returns: str = Field("", max_length=500)
    maps_url: str = Field("", max_length=500)
    welcome: str = Field("", max_length=500)
    extra: str = Field("", max_length=3000)
    faqs: list[FAQ] = Field(default_factory=list, max_length=50)


class ShopIn(BaseModel):
    slug: str = Field(max_length=50)
    name: str = Field(min_length=2, max_length=120)
    info: ShopInfo = Field(default_factory=ShopInfo)
    bot_enabled: bool = True


class ShopUpdate(BaseModel):
    slug: str | None = Field(None, max_length=50)
    name: str | None = Field(None, min_length=2, max_length=120)
    info: ShopInfo | None = None
    bot_enabled: bool | None = None


class ChannelsIn(BaseModel):
    owner_telegram_chat_id: str | None = Field(None, max_length=50)
    telegram_token: str | None = Field(None, max_length=100)
    wa_phone_number_id: str | None = Field(None, max_length=50)
    wa_token: str | None = Field(None, max_length=1000)
    fb_page_id: str | None = Field(None, max_length=50)
    fb_page_token: str | None = Field(None, max_length=1000)


class ProductIn(BaseModel):
    name: str = Field(min_length=1, max_length=150)
    price: int = Field(0, ge=0, le=100_000_000)
    sizes: str = Field("", max_length=200)
    in_stock: bool = True
    aliases: str = Field("", max_length=300)
    description: str = Field("", max_length=500)


class ProductUpdate(BaseModel):
    name: str | None = Field(None, min_length=1, max_length=150)
    price: int | None = Field(None, ge=0, le=100_000_000)
    sizes: str | None = Field(None, max_length=200)
    in_stock: bool | None = None
    aliases: str | None = Field(None, max_length=300)
    description: str | None = Field(None, max_length=500)
    position: int | None = None


class ReplyIn(BaseModel):
    text: str = Field(min_length=1, max_length=4000)
    take_over: bool = True


class ConvStatusIn(BaseModel):
    status: str | None = Field(None, pattern="^(bot|human)$")
    needs_attention: bool | None = None


class CorrectIn(BaseModel):
    corrected: str | None = Field(None, max_length=4000)


class OrderUpdate(BaseModel):
    status: str | None = Field(None, pattern="^(new|confirmed|shipped|delivered|cancelled)$")
    note: str | None = Field(None, max_length=1000)


class ChatIn(BaseModel):
    text: str = Field(min_length=1, max_length=2000)
    visitor_id: str | None = Field(None, max_length=64)
    name: str = Field("", max_length=80)


class TestChatIn(BaseModel):
    text: str = Field(min_length=1, max_length=2000)
    session: str = Field("default", max_length=64)
    reset: bool = False


# ---------------------------------------------------------------------------
# صفحات
# ---------------------------------------------------------------------------

@app.get("/", include_in_schema=False)
def index() -> RedirectResponse:
    return RedirectResponse("/admin/")


@app.get("/admin", include_in_schema=False)
@app.get("/admin/", include_in_schema=False)
def admin_page() -> FileResponse:
    return FileResponse(STATIC / "admin.html", media_type="text/html; charset=utf-8")


@app.get("/widget.js", include_in_schema=False)
def widget_js() -> FileResponse:
    return FileResponse(STATIC / "widget.js", media_type="application/javascript; charset=utf-8",
                        headers={"Cache-Control": "public, max-age=300"})


@app.get("/c/{slug}", include_in_schema=False)
def chat_page(slug: str) -> HTMLResponse:
    shop = db.get_shop_by("slug", slug)
    if not shop:
        raise HTTPException(404, "المحل ماكانش")
    html = (STATIC / "chat.html").read_text(encoding="utf-8")
    safe_name = (shop["name"].replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                 .replace('"', "&quot;"))
    html = html.replace("{{SHOP_NAME}}", safe_name).replace("{{SHOP_SLUG}}", slug)
    return HTMLResponse(html)


@app.get("/health")
def health() -> dict:
    return {"ok": True, "version": __version__}


# ---------------------------------------------------------------------------
# الدخول
# ---------------------------------------------------------------------------

@app.post("/api/auth/login")
def login(body: LoginIn, request: Request, response: Response) -> dict:
    ip = client_ip(request)
    if not _login_limiter.allow(ip):
        raise HTTPException(429, "محاولات بزاف، جرب من بعد دقيقة")
    if not check_password(body.password):
        raise HTTPException(401, "كلمة السر غالطة")
    _login_limiter.hits.pop(ip, None)  # نحسبو غير المحاولات الغالطة
    secure = get_settings().public_base_url.startswith("https://")
    response.set_cookie(COOKIE, make_session(), max_age=SESSION_SECONDS, httponly=True, samesite="lax",
                        secure=secure)
    return {"role": "admin"}


@app.post("/api/auth/logout")
def logout(response: Response) -> dict:
    response.delete_cookie(COOKIE)
    return {"ok": True}


@app.get("/api/auth/me")
def me(p: Principal = Depends(principal)) -> dict:
    return {"role": p.role, "shop_ids": p.shop_ids}


# ---------------------------------------------------------------------------
# المحلات والمنتجات
# ---------------------------------------------------------------------------

def _shop_view(shop: dict, p: Principal) -> dict:
    d = db.public_shop(shop)
    if p.role != "admin":
        d.pop("owner_key", None)
    base = get_settings().public_base_url
    d["links"] = {
        "chat_page": f"{base}/c/{shop['slug']}",
        "widget": f'<script src="{base}/widget.js" data-shop="{shop["slug"]}" defer></script>',
        "telegram_webhook": f"{base}/webhook/telegram/{shop['slug']}",
        "meta_webhook": f"{base}/webhook/meta",
    }
    if p.role == "admin":
        d["links"]["owner_dashboard"] = f"{base}/admin/?key={shop['owner_key']}"
    return d


@app.get("/api/shops")
def shops_list(p: Principal = Depends(principal)) -> list[dict]:
    return [_shop_view(s, p) for s in db.list_shops() if p.can(s["id"])]


@app.post("/api/shops")
def shops_create(body: ShopIn, p: Principal = Depends(require_admin)) -> dict:
    slug = body.slug.strip().lower()
    if not SLUG_RE.match(slug):
        raise HTTPException(400, "الرابط (slug) لازم يكون حروف لاتينية صغيرة وأرقام و - (مثال: boutique-amel)")
    if db.get_shop_by("slug", slug):
        raise HTTPException(409, "هاد الرابط مستعمل")
    shop = db.create_shop(slug, body.name.strip(), body.info.model_dump())
    db.update_shop(shop["id"], bot_enabled=body.bot_enabled)
    return _shop_view(db.get_shop(shop["id"]), p)


@app.get("/api/shops/{shop_id}")
def shops_get(shop_id: int, p: Principal = Depends(principal)) -> dict:
    shop = require_shop(p, shop_id)
    d = _shop_view(shop, p)
    d["products"] = db.list_products(shop_id)
    return d


@app.put("/api/shops/{shop_id}")
def shops_update(shop_id: int, body: ShopUpdate, p: Principal = Depends(principal)) -> dict:
    require_shop(p, shop_id)
    fields: dict[str, Any] = {}
    if body.slug is not None:
        slug = body.slug.strip().lower()
        if not SLUG_RE.match(slug):
            raise HTTPException(400, "الرابط (slug) ماهوش صحيح")
        other = db.get_shop_by("slug", slug)
        if other and other["id"] != shop_id:
            raise HTTPException(409, "هاد الرابط مستعمل")
        if p.role != "admin":
            raise HTTPException(403, "تبديل الرابط للمدير برك")
        fields["slug"] = slug
    if body.name is not None:
        fields["name"] = body.name.strip()
    if body.info is not None:
        fields["info"] = body.info.model_dump()
    if body.bot_enabled is not None:
        fields["bot_enabled"] = body.bot_enabled
    return _shop_view(db.update_shop(shop_id, **fields), p)


@app.delete("/api/shops/{shop_id}")
def shops_delete(shop_id: int, p: Principal = Depends(require_admin)) -> dict:
    require_shop(p, shop_id)
    db.delete_shop(shop_id)
    return {"ok": True}


@app.post("/api/shops/{shop_id}/rotate-key")
def shops_rotate(shop_id: int, p: Principal = Depends(require_admin)) -> dict:
    require_shop(p, shop_id)
    db.rotate_owner_key(shop_id)
    return _shop_view(db.get_shop(shop_id), p)


@app.put("/api/shops/{shop_id}/channels")
def shops_channels(shop_id: int, body: ChannelsIn, p: Principal = Depends(principal)) -> dict:
    require_shop(p, shop_id)
    fields = {k: v.strip() for k, v in body.model_dump().items() if v is not None}
    if fields.get("wa_phone_number_id"):
        other = db.get_shop_by("wa_phone_number_id", fields["wa_phone_number_id"])
        if other and other["id"] != shop_id:
            raise HTTPException(409, "رقم واتساب هذا مربوط بمحل آخر")
    if fields.get("fb_page_id"):
        other = db.get_shop_by("fb_page_id", fields["fb_page_id"])
        if other and other["id"] != shop_id:
            raise HTTPException(409, "صفحة فيسبوك هذي مربوطة بمحل آخر")
    return _shop_view(db.update_shop(shop_id, **fields), p)


@app.post("/api/shops/{shop_id}/telegram/connect")
def telegram_connect(shop_id: int, p: Principal = Depends(principal)) -> dict:
    shop = require_shop(p, shop_id)
    token = shop.get("telegram_token")
    if not token:
        raise HTTPException(400, "حط توكن البوت تاع تيليغرام أولاً")
    me_info = telegram.get_me(token)
    if not me_info:
        raise HTTPException(400, "التوكن غالط ولا تيليغرام ما جاوبش")
    base = get_settings().public_base_url
    if not base.startswith("https://"):
        return {"ok": False, "bot": me_info.get("username"),
                "detail": "تيليغرام يحتاج رابط https. حط PUBLIC_BASE_URL، ولا شغّل scripts/telegram_polling.py"}
    res = telegram.set_webhook(token, f"{base}/webhook/telegram/{shop['slug']}", shop["telegram_secret"])
    return {"ok": bool(res), "bot": me_info.get("username")}


@app.get("/api/shops/{shop_id}/prompt")
def shops_prompt(shop_id: int, p: Principal = Depends(principal)) -> PlainTextResponse:
    shop = require_shop(p, shop_id)
    return PlainTextResponse(system_prompt(shop, db.list_products(shop_id)))


@app.get("/api/shops/{shop_id}/products")
def products_list(shop_id: int, p: Principal = Depends(principal)) -> list[dict]:
    require_shop(p, shop_id)
    return db.list_products(shop_id)


@app.post("/api/shops/{shop_id}/products")
def products_create(shop_id: int, body: ProductIn, p: Principal = Depends(principal)) -> dict:
    require_shop(p, shop_id)
    return db.add_product(shop_id, **body.model_dump())


def _product_for(p: Principal, product_id: int) -> dict:
    prod = db.get_product(product_id)
    if not prod or not p.can(prod["shop_id"]):
        raise HTTPException(404, "المنتج ماكانش")
    return prod


@app.put("/api/products/{product_id}")
def products_update(product_id: int, body: ProductUpdate, p: Principal = Depends(principal)) -> dict:
    _product_for(p, product_id)
    return db.update_product(product_id, **body.model_dump())


@app.delete("/api/products/{product_id}")
def products_delete(product_id: int, p: Principal = Depends(principal)) -> dict:
    _product_for(p, product_id)
    db.delete_product(product_id)
    return {"ok": True}


# ---------------------------------------------------------------------------
# المحادثات
# ---------------------------------------------------------------------------

def _scope(p: Principal, shop_id: int | None) -> list[int] | None:
    if shop_id is not None:
        require_shop(p, shop_id)
        return [shop_id]
    return p.shop_ids


@app.get("/api/conversations")
def conversations_list(shop_id: int | None = None, attention: bool = False, limit: int = Query(100, le=500),
                       p: Principal = Depends(principal)) -> list[dict]:
    return db.list_conversations(_scope(p, shop_id), limit=limit, attention_only=attention)


def _conv_for(p: Principal, conv_id: int) -> dict:
    conv = db.get_conversation(conv_id)
    if not conv or not p.can(conv["shop_id"]):
        raise HTTPException(404, "المحادثة ماكانش")
    return conv


@app.get("/api/conversations/{conv_id}")
def conversations_get(conv_id: int, p: Principal = Depends(principal)) -> dict:
    conv = _conv_for(p, conv_id)
    conv["messages"] = db.list_messages(conv_id)
    conv["shop_name"] = (db.get_shop(conv["shop_id"]) or {}).get("name", "")
    return conv


@app.post("/api/conversations/{conv_id}/reply")
def conversations_reply(conv_id: int, body: ReplyIn, p: Principal = Depends(principal)) -> dict:
    conv = _conv_for(p, conv_id)
    shop = db.get_shop(conv["shop_id"])
    msg = engine.owner_reply(conv_id, body.text, take_over=body.take_over)
    sent = channels.send(shop, conv, body.text)
    return {"message": msg, "sent": sent}


@app.post("/api/conversations/{conv_id}/status")
def conversations_status(conv_id: int, body: ConvStatusIn, p: Principal = Depends(principal)) -> dict:
    _conv_for(p, conv_id)
    fields: dict[str, Any] = {}
    if body.status:
        fields["status"] = body.status
    if body.needs_attention is not None:
        fields["needs_attention"] = body.needs_attention
    return db.update_conversation(conv_id, **fields)


@app.put("/api/messages/{msg_id}/correct")
def messages_correct(msg_id: int, body: CorrectIn, p: Principal = Depends(principal)) -> dict:
    msg = db.get_message(msg_id)
    if not msg:
        raise HTTPException(404, "الرسالة ماكانش")
    _conv_for(p, msg["conversation_id"])
    if msg["role"] != "assistant":
        raise HTTPException(400, "نقدرو نصححو غير ردود البوت")
    return db.correct_message(msg_id, (body.corrected or "").strip() or None)


# ---------------------------------------------------------------------------
# الطلبيات والإحصائيات
# ---------------------------------------------------------------------------

@app.get("/api/orders")
def orders_list(shop_id: int | None = None, status: str | None = None, p: Principal = Depends(principal)) -> list:
    return db.list_orders(_scope(p, shop_id), status=status)


@app.put("/api/orders/{order_id}")
def orders_update(order_id: int, body: OrderUpdate, p: Principal = Depends(principal)) -> dict:
    order = db.get_order(order_id)
    if not order or not p.can(order["shop_id"]):
        raise HTTPException(404, "الطلبية ماكانش")
    return db.update_order(order_id, status=body.status, note=body.note)


@app.get("/api/orders.csv")
def orders_csv(shop_id: int | None = None, p: Principal = Depends(principal)) -> Response:
    rows = db.list_orders(_scope(p, shop_id), limit=100000)
    buf = io.StringIO()
    w = csv.writer(buf)
    w.writerow(["id", "date", "shop", "kind", "status", "name", "phone", "wilaya", "address", "time", "items",
                "total", "note"])
    import datetime as _dt
    for o in rows:
        items = "; ".join(f"{i['name']}{' (' + i['size'] + ')' if i.get('size') else ''} x{i['qty']}"
                          for i in o["items"])
        w.writerow([o["id"], _dt.datetime.fromtimestamp(o["created_at"]).strftime("%Y-%m-%d %H:%M"),
                    o.get("shop_name", ""), o["kind"], o["status"], o["customer_name"], o["phone"], o["wilaya"],
                    o["address"], o["preferred_time"], items, o["total"], o["note"]])
    return Response("﻿" + buf.getvalue(), media_type="text/csv; charset=utf-8",
                    headers={"Content-Disposition": "attachment; filename=orders.csv"})


@app.get("/api/stats")
def stats(shop_id: int | None = None, p: Principal = Depends(principal)) -> dict:
    return db.stats(_scope(p, shop_id))


@app.get("/api/system")
def system_info(p: Principal = Depends(principal)) -> dict:
    s = get_settings()
    provider = get_provider(s)
    health_info = provider.health() if provider else {"provider": "rules", "ok": True}
    return {
        "version": __version__, "provider": s.llm_provider, "health": health_info,
        "public_base_url": s.public_base_url,
        "meta_verify_token": s.wa_verify_token if p.role == "admin" else None,
        "notify_bot": bool(s.notify_telegram_token),
    }


@app.get("/api/export/training.jsonl")
def export_training(shop_id: int | None = None, only_reviewed: bool = False,
                    p: Principal = Depends(principal)) -> Response:
    data = training_jsonl(_scope(p, shop_id), only_reviewed=only_reviewed)
    return Response(data, media_type="application/jsonl; charset=utf-8",
                    headers={"Content-Disposition": "attachment; filename=my_examples.jsonl"})


@app.post("/api/shops/{shop_id}/test-chat")
def test_chat(shop_id: int, body: TestChatIn, p: Principal = Depends(principal)) -> dict:
    shop = require_shop(p, shop_id)
    ext = f"test-{p.role}-{body.session}"
    if body.reset:
        conv = db.get_or_create_conversation(shop_id, "test", ext)
        with db.connect() as c:
            c.execute("DELETE FROM conversations WHERE id = ?", (conv["id"],))
    r = engine.handle_message(shop, "test", ext, body.text, customer_name="تجربة")
    return {"reply": r.text, "provider": r.provider, "events": r.events, "order": r.order,
            "order_state": (r.conversation or {}).get("order_state")}


# ---------------------------------------------------------------------------
# الدردشة العامة (ويدجت الموقع وصفحة /c/slug)
# ---------------------------------------------------------------------------

def _public_shop(slug: str) -> dict:
    shop = db.get_shop_by("slug", slug)
    if not shop:
        raise HTTPException(404, "المحل ماكانش")
    return shop


@app.get("/api/public/{slug}/info")
def public_info(slug: str) -> dict:
    shop = _public_shop(slug)
    info = shop.get("info") or {}
    return {"name": shop["name"], "welcome": info.get("welcome") or f"مرحبا بيك فـ {shop['name']}! كيفاش نقدر نعاونك؟",
            "phone": info.get("phone", ""), "online": shop["bot_enabled"]}


@app.post("/api/public/{slug}/chat")
def public_chat(slug: str, body: ChatIn, request: Request) -> JSONResponse:
    shop = _public_shop(slug)
    visitor = body.visitor_id if body.visitor_id and VISITOR_RE.match(body.visitor_id) else secrets.token_urlsafe(16)
    if not public_limiter().allow(f"{client_ip(request)}:{slug}"):
        raise HTTPException(429, "رسائل بزاف، استنى شوية")
    r = engine.handle_message(shop, "web", visitor, body.text, customer_name=body.name.strip())
    last = db.list_messages(r.conversation["id"], limit=1) if r.conversation else []
    return JSONResponse({"visitor_id": visitor, "reply": r.text, "last_id": last[-1]["id"] if last else 0,
                         "human": (r.conversation or {}).get("status") == "human"})


@app.get("/api/public/{slug}/messages")
def public_messages(slug: str, visitor_id: str, after: int = 0) -> dict:
    shop = _public_shop(slug)
    if not VISITOR_RE.match(visitor_id):
        raise HTTPException(400, "visitor_id غالط")
    with db.connect() as c:
        row = c.execute("SELECT id, status FROM conversations WHERE shop_id = ? AND channel = 'web' AND "
                        "external_id = ?", (shop["id"], visitor_id)).fetchone()
    if not row:
        return {"messages": [], "human": False}
    roles = {"user": "user", "assistant": "bot", "owner": "owner"}
    msgs = [{"id": m["id"], "role": roles.get(m["role"], "bot"), "text": m["content"]}
            for m in db.list_messages(row["id"], limit=100) if m["id"] > after]
    return {"messages": msgs, "human": row["status"] == "human"}


# ---------------------------------------------------------------------------
# Webhooks
# ---------------------------------------------------------------------------

def process_incoming(shop_id: int, channel: str, external_id: str, text: str, name: str = "") -> None:
    shop = db.get_shop(shop_id)
    if not shop:
        return
    try:
        r = engine.handle_message(shop, channel, external_id, text, customer_name=name)
        if r.text:
            channels.send(shop, r.conversation, r.text)
    except Exception:
        log.exception("failed to process %s message for shop %s", channel, shop_id)


@app.post("/webhook/telegram/{slug}")
async def webhook_telegram(slug: str, request: Request, background: BackgroundTasks) -> dict:
    shop = db.get_shop_by("slug", slug)
    if not shop:
        raise HTTPException(404)
    secret = request.headers.get("X-Telegram-Bot-Api-Secret-Token", "")
    if not shop.get("telegram_secret") or not secrets.compare_digest(secret, shop["telegram_secret"]):
        raise HTTPException(403)
    try:
        update = await request.json()
    except ValueError:
        raise HTTPException(400)
    parsed = telegram.parse_update(update)
    if parsed:
        chat_id, name, text = parsed
        if text.strip() == "/start":
            text = "سلام"
        elif text.strip() == "/myid":
            telegram.send_message(shop["telegram_token"], chat_id, f"رقم المحادثة تاعك (chat id): {chat_id}")
            return {"ok": True}
        background.add_task(process_incoming, shop["id"], "telegram", chat_id, text, name)
    return {"ok": True}


@app.get("/webhook/meta", include_in_schema=False)
def webhook_meta_verify(request: Request) -> PlainTextResponse:
    q = request.query_params
    if q.get("hub.mode") == "subscribe" and secrets.compare_digest(q.get("hub.verify_token", ""),
                                                                  get_settings().wa_verify_token):
        return PlainTextResponse(q.get("hub.challenge", ""))
    raise HTTPException(403)


@app.post("/webhook/meta")
async def webhook_meta(request: Request, background: BackgroundTasks) -> dict:
    body = await request.body()
    if not meta.verify_signature(get_settings().meta_app_secret, body, request.headers.get("X-Hub-Signature-256")):
        raise HTTPException(403)
    try:
        payload = json.loads(body or b"{}")
    except ValueError:
        raise HTTPException(400)
    obj = payload.get("object")
    if obj == "whatsapp_business_account":
        for m in meta.parse_whatsapp(payload):
            shop = db.get_shop_by("wa_phone_number_id", m["phone_number_id"])
            if shop:
                background.add_task(process_incoming, shop["id"], "whatsapp", m["from"], m["text"], m["name"])
    elif obj in ("page", "instagram"):
        for m in meta.parse_messenger(payload):
            shop = db.get_shop_by("fb_page_id", m["page_id"])
            if shop:
                background.add_task(process_incoming, shop["id"], m["channel"], m["sender"], m["text"])
    return {"ok": True}
