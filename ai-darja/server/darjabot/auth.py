"""الدخول للوحة التحكم.

- المدير (admin): بكلمة السر ADMIN_PASSWORD، يشوف ويتحكم في كل المحلات.
- صاحب المحل (owner): برابط خاص فيه owner_key، يشوف محلو برك.
"""
from __future__ import annotations

import hashlib
import hmac
import time
from collections import defaultdict, deque
from dataclasses import dataclass

from fastapi import HTTPException, Request

from . import db
from .config import get_settings

COOKIE = "djb_session"
SESSION_SECONDS = 14 * 24 * 3600


@dataclass
class Principal:
    role: str                    # admin | owner
    shop_ids: list[int] | None   # None = كل المحلات

    def can(self, shop_id: int) -> bool:
        return self.shop_ids is None or shop_id in self.shop_ids


def _sign(payload: str) -> str:
    return hmac.new(get_settings().secret_key.encode(), payload.encode(), hashlib.sha256).hexdigest()


def make_session() -> str:
    exp = int(time.time()) + SESSION_SECONDS
    return f"{exp}.{_sign(f'admin.{exp}')}"


def valid_session(token: str | None) -> bool:
    if not token or "." not in token:
        return False
    exp, sig = token.split(".", 1)
    if not exp.isdigit() or int(exp) < time.time():
        return False
    return hmac.compare_digest(sig, _sign(f"admin.{exp}"))


def check_password(password: str) -> bool:
    return hmac.compare_digest(password.encode(), get_settings().admin_password.encode())


def principal(request: Request) -> Principal:
    if valid_session(request.cookies.get(COOKIE)):
        return Principal("admin", None)
    key = request.headers.get("X-Owner-Key") or request.query_params.get("owner_key")
    if key:
        shop = db.get_shop_by("owner_key", key)
        if shop:
            return Principal("owner", [shop["id"]])
    raise HTTPException(401, "لازم تدخل")


def require_admin(request: Request) -> Principal:
    p = principal(request)
    if p.role != "admin":
        raise HTTPException(403, "هاد العملية للمدير برك")
    return p


def require_shop(p: Principal, shop_id: int) -> dict:
    shop = db.get_shop(shop_id)
    if not shop or not p.can(shop_id):
        raise HTTPException(404, "المحل ماكانش")
    return shop


class RateLimiter:
    """حد بسيط في الذاكرة: N طلبات في الدقيقة لكل مفتاح."""

    def __init__(self, per_minute: int):
        self.per_minute = per_minute
        self.hits: dict[str, deque] = defaultdict(deque)

    def allow(self, key: str) -> bool:
        now = time.time()
        q = self.hits[key]
        while q and now - q[0] > 60:
            q.popleft()
        if len(q) >= self.per_minute:
            return False
        q.append(now)
        if len(self.hits) > 10000:  # تنظيف الزوار القدام
            for k in [k for k, v in self.hits.items() if not v or now - v[-1] > 60]:
                self.hits.pop(k, None)
        return True


def client_ip(request: Request) -> str:
    """ما نثقوش في X-Forwarded-For مباشرة (أي واحد يقدر يزوّرو).
    uvicorn يبدّل request.client بالـ IP الحقيقي غير إذا البروكسي موثوق (FORWARDED_ALLOW_IPS)."""
    return request.client.host if request.client else "unknown"
