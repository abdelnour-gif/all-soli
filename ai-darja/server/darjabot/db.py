"""قاعدة البيانات (SQLite): المحلات، المنتجات، المحادثات، الرسائل، الطلبيات."""
from __future__ import annotations

import json
import secrets
import sqlite3
import threading
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator

from .config import get_settings

SCHEMA = """
CREATE TABLE IF NOT EXISTS shops (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    info TEXT NOT NULL DEFAULT '{}',
    bot_enabled INTEGER NOT NULL DEFAULT 1,
    owner_key TEXT UNIQUE NOT NULL,
    owner_telegram_chat_id TEXT NOT NULL DEFAULT '',
    telegram_token TEXT NOT NULL DEFAULT '',
    telegram_secret TEXT NOT NULL DEFAULT '',
    wa_phone_number_id TEXT NOT NULL DEFAULT '',
    wa_token TEXT NOT NULL DEFAULT '',
    fb_page_id TEXT NOT NULL DEFAULT '',
    fb_page_token TEXT NOT NULL DEFAULT '',
    created_at REAL NOT NULL,
    updated_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_id INTEGER NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    price INTEGER NOT NULL DEFAULT 0,
    sizes TEXT NOT NULL DEFAULT '',
    in_stock INTEGER NOT NULL DEFAULT 1,
    aliases TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_products_shop ON products(shop_id);
CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_id INTEGER NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
    channel TEXT NOT NULL,
    external_id TEXT NOT NULL,
    customer_name TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'bot',
    needs_attention INTEGER NOT NULL DEFAULT 0,
    order_state TEXT NOT NULL DEFAULT '{}',
    created_at REAL NOT NULL,
    updated_at REAL NOT NULL,
    UNIQUE(shop_id, channel, external_id)
);
CREATE INDEX IF NOT EXISTS idx_conv_shop ON conversations(shop_id, updated_at);
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT '',
    corrected TEXT,
    created_at REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id, id);
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shop_id INTEGER NOT NULL REFERENCES shops(id) ON DELETE CASCADE,
    conversation_id INTEGER REFERENCES conversations(id) ON DELETE SET NULL,
    kind TEXT NOT NULL DEFAULT 'order',
    items TEXT NOT NULL DEFAULT '[]',
    customer_name TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    wilaya TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT '',
    preferred_time TEXT NOT NULL DEFAULT '',
    total INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'new',
    note TEXT NOT NULL DEFAULT '',
    created_at REAL NOT NULL,
    updated_at REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_shop ON orders(shop_id, created_at);
"""

ORDER_STATUSES = ("new", "confirmed", "shipped", "delivered", "cancelled")
SHOP_SECRET_FIELDS = ("telegram_token", "telegram_secret", "wa_token", "fb_page_token")
SHOP_CHANNEL_FIELDS = ("owner_telegram_chat_id", "telegram_token", "wa_phone_number_id", "wa_token",
                       "fb_page_id", "fb_page_token")

_lock = threading.RLock()
_initialized: set[str] = set()


def _connect(path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(path, timeout=30, check_same_thread=False)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA busy_timeout = 30000")
    return conn


@contextmanager
def connect() -> Iterator[sqlite3.Connection]:
    path = get_settings().db_path
    path.parent.mkdir(parents=True, exist_ok=True)
    with _lock:
        conn = _connect(path)
        try:
            if str(path) not in _initialized:
                conn.executescript(SCHEMA)
                _initialized.add(str(path))
            yield conn
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            conn.close()


def now() -> float:
    return time.time()


def _row(r: sqlite3.Row | None) -> dict | None:
    return dict(r) if r is not None else None


# ---------------------------------------------------------------------------
# المحلات
# ---------------------------------------------------------------------------

def _shop_out(r: sqlite3.Row | None) -> dict | None:
    if r is None:
        return None
    d = dict(r)
    d["info"] = json.loads(d.get("info") or "{}")
    d["bot_enabled"] = bool(d["bot_enabled"])
    return d


def create_shop(slug: str, name: str, info: dict | None = None, **channels: str) -> dict:
    t = now()
    with connect() as c:
        cur = c.execute(
            "INSERT INTO shops (slug, name, info, owner_key, telegram_secret, created_at, updated_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            (slug, name, json.dumps(info or {}, ensure_ascii=False), secrets.token_urlsafe(18),
             secrets.token_urlsafe(16), t, t),
        )
        shop_id = cur.lastrowid
    if channels:
        update_shop(shop_id, **channels)
    return get_shop(shop_id)


def get_shop(shop_id: int) -> dict | None:
    with connect() as c:
        return _shop_out(c.execute("SELECT * FROM shops WHERE id = ?", (shop_id,)).fetchone())


def get_shop_by(field: str, value: str) -> dict | None:
    if field not in {"slug", "owner_key", "wa_phone_number_id", "fb_page_id"} or not value:
        return None
    with connect() as c:
        return _shop_out(c.execute(f"SELECT * FROM shops WHERE {field} = ?", (value,)).fetchone())


def list_shops() -> list[dict]:
    with connect() as c:
        return [_shop_out(r) for r in c.execute("SELECT * FROM shops ORDER BY id").fetchall()]


def update_shop(shop_id: int, **fields: Any) -> dict | None:
    allowed = {"slug", "name", "info", "bot_enabled", *SHOP_CHANNEL_FIELDS}
    sets, vals = [], []
    for k, v in fields.items():
        if k not in allowed or v is None:
            continue
        if k == "info":
            v = json.dumps(v, ensure_ascii=False)
        elif k == "bot_enabled":
            v = 1 if v else 0
        sets.append(f"{k} = ?")
        vals.append(v)
    if sets:
        sets.append("updated_at = ?")
        vals.extend([now(), shop_id])
        with connect() as c:
            c.execute(f"UPDATE shops SET {', '.join(sets)} WHERE id = ?", vals)
    return get_shop(shop_id)


def rotate_owner_key(shop_id: int) -> str:
    key = secrets.token_urlsafe(18)
    with connect() as c:
        c.execute("UPDATE shops SET owner_key = ?, updated_at = ? WHERE id = ?", (key, now(), shop_id))
    return key


def delete_shop(shop_id: int) -> None:
    with connect() as c:
        c.execute("DELETE FROM shops WHERE id = ?", (shop_id,))


def public_shop(shop: dict) -> dict:
    """نسخة بلا أسرار (للوحة التحكم)."""
    d = dict(shop)
    for f in SHOP_SECRET_FIELDS:
        d[f + "_set"] = bool(d.get(f))
        d.pop(f, None)
    return d


# ---------------------------------------------------------------------------
# المنتجات
# ---------------------------------------------------------------------------

def _product_out(r: sqlite3.Row) -> dict:
    d = dict(r)
    d["in_stock"] = bool(d["in_stock"])
    return d


def list_products(shop_id: int) -> list[dict]:
    with connect() as c:
        rows = c.execute("SELECT * FROM products WHERE shop_id = ? ORDER BY position, id", (shop_id,)).fetchall()
    return [_product_out(r) for r in rows]


def get_product(product_id: int) -> dict | None:
    with connect() as c:
        r = c.execute("SELECT * FROM products WHERE id = ?", (product_id,)).fetchone()
    return _product_out(r) if r else None


def add_product(shop_id: int, name: str, price: int = 0, sizes: str = "", in_stock: bool = True,
                aliases: str = "", description: str = "", position: int | None = None) -> dict:
    with connect() as c:
        if position is None:
            position = (c.execute("SELECT COALESCE(MAX(position), 0) + 1 FROM products WHERE shop_id = ?",
                                  (shop_id,)).fetchone()[0])
        cur = c.execute(
            "INSERT INTO products (shop_id, name, price, sizes, in_stock, aliases, description, position) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (shop_id, name, int(price), sizes, 1 if in_stock else 0, aliases, description, position),
        )
        pid = cur.lastrowid
    return get_product(pid)


def update_product(product_id: int, **fields: Any) -> dict | None:
    allowed = {"name", "price", "sizes", "in_stock", "aliases", "description", "position"}
    sets, vals = [], []
    for k, v in fields.items():
        if k in allowed and v is not None:
            if k == "in_stock":
                v = 1 if v else 0
            sets.append(f"{k} = ?")
            vals.append(v)
    if sets:
        vals.append(product_id)
        with connect() as c:
            c.execute(f"UPDATE products SET {', '.join(sets)} WHERE id = ?", vals)
    return get_product(product_id)


def delete_product(product_id: int) -> None:
    with connect() as c:
        c.execute("DELETE FROM products WHERE id = ?", (product_id,))


# ---------------------------------------------------------------------------
# المحادثات والرسائل
# ---------------------------------------------------------------------------

def _conv_out(r: sqlite3.Row | None) -> dict | None:
    if r is None:
        return None
    d = dict(r)
    d["order_state"] = json.loads(d.get("order_state") or "{}")
    d["needs_attention"] = bool(d["needs_attention"])
    return d


def get_or_create_conversation(shop_id: int, channel: str, external_id: str, customer_name: str = "") -> dict:
    t = now()
    with connect() as c:
        r = c.execute("SELECT * FROM conversations WHERE shop_id = ? AND channel = ? AND external_id = ?",
                      (shop_id, channel, external_id)).fetchone()
        if r is None:
            c.execute(
                "INSERT INTO conversations (shop_id, channel, external_id, customer_name, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, ?, ?)",
                (shop_id, channel, external_id, customer_name, t, t),
            )
            r = c.execute("SELECT * FROM conversations WHERE shop_id = ? AND channel = ? AND external_id = ?",
                          (shop_id, channel, external_id)).fetchone()
        elif customer_name and not r["customer_name"]:
            c.execute("UPDATE conversations SET customer_name = ? WHERE id = ?", (customer_name, r["id"]))
            r = c.execute("SELECT * FROM conversations WHERE id = ?", (r["id"],)).fetchone()
    return _conv_out(r)


def get_conversation(conv_id: int) -> dict | None:
    with connect() as c:
        return _conv_out(c.execute("SELECT * FROM conversations WHERE id = ?", (conv_id,)).fetchone())


def update_conversation(conv_id: int, **fields: Any) -> dict | None:
    allowed = {"customer_name", "status", "needs_attention", "order_state"}
    sets, vals = [], []
    for k, v in fields.items():
        if k not in allowed or v is None:
            continue
        if k == "order_state":
            v = json.dumps(v, ensure_ascii=False)
        elif k == "needs_attention":
            v = 1 if v else 0
        sets.append(f"{k} = ?")
        vals.append(v)
    sets.append("updated_at = ?")
    vals.extend([now(), conv_id])
    with connect() as c:
        c.execute(f"UPDATE conversations SET {', '.join(sets)} WHERE id = ?", vals)
    return get_conversation(conv_id)


def list_conversations(shop_ids: list[int] | None, limit: int = 100, attention_only: bool = False) -> list[dict]:
    q = ("SELECT c.*, s.name AS shop_name, "
         "(SELECT content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message, "
         "(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS message_count "
         "FROM conversations c JOIN shops s ON s.id = c.shop_id WHERE 1 = 1")
    params: list[Any] = []
    if shop_ids is not None:
        if not shop_ids:
            return []
        q += f" AND c.shop_id IN ({','.join('?' * len(shop_ids))})"
        params.extend(shop_ids)
    if attention_only:
        q += " AND (c.needs_attention = 1 OR c.status = 'human')"
    q += " ORDER BY c.updated_at DESC LIMIT ?"
    params.append(limit)
    with connect() as c:
        return [_conv_out(r) for r in c.execute(q, params).fetchall()]


def add_message(conv_id: int, role: str, content: str, provider: str = "") -> dict:
    t = now()
    with connect() as c:
        cur = c.execute(
            "INSERT INTO messages (conversation_id, role, content, provider, created_at) VALUES (?, ?, ?, ?, ?)",
            (conv_id, role, content, provider, t),
        )
        c.execute("UPDATE conversations SET updated_at = ? WHERE id = ?", (t, conv_id))
        r = c.execute("SELECT * FROM messages WHERE id = ?", (cur.lastrowid,)).fetchone()
    return dict(r)


def get_message(msg_id: int) -> dict | None:
    with connect() as c:
        return _row(c.execute("SELECT * FROM messages WHERE id = ?", (msg_id,)).fetchone())


def list_messages(conv_id: int, limit: int | None = None) -> list[dict]:
    with connect() as c:
        if limit:
            rows = c.execute(
                "SELECT * FROM (SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?) "
                "ORDER BY id", (conv_id, limit)).fetchall()
        else:
            rows = c.execute("SELECT * FROM messages WHERE conversation_id = ? ORDER BY id", (conv_id,)).fetchall()
    return [dict(r) for r in rows]


def correct_message(msg_id: int, corrected: str | None) -> dict | None:
    with connect() as c:
        c.execute("UPDATE messages SET corrected = ? WHERE id = ?", (corrected or None, msg_id))
    return get_message(msg_id)


# ---------------------------------------------------------------------------
# الطلبيات
# ---------------------------------------------------------------------------

def _order_out(r: sqlite3.Row | None) -> dict | None:
    if r is None:
        return None
    d = dict(r)
    d["items"] = json.loads(d.get("items") or "[]")
    return d


def create_order(shop_id: int, conversation_id: int | None, kind: str, items: list[dict], customer_name: str,
                 phone: str, wilaya: str = "", address: str = "", preferred_time: str = "", total: int = 0,
                 note: str = "") -> dict:
    t = now()
    with connect() as c:
        cur = c.execute(
            "INSERT INTO orders (shop_id, conversation_id, kind, items, customer_name, phone, wilaya, address, "
            "preferred_time, total, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            (shop_id, conversation_id, kind, json.dumps(items, ensure_ascii=False), customer_name, phone, wilaya,
             address, preferred_time, int(total), note, t, t),
        )
        oid = cur.lastrowid
    return get_order(oid)


def get_order(order_id: int) -> dict | None:
    with connect() as c:
        return _order_out(c.execute("SELECT * FROM orders WHERE id = ?", (order_id,)).fetchone())


def list_orders(shop_ids: list[int] | None, status: str | None = None, limit: int = 200) -> list[dict]:
    q = "SELECT o.*, s.name AS shop_name FROM orders o JOIN shops s ON s.id = o.shop_id WHERE 1 = 1"
    params: list[Any] = []
    if shop_ids is not None:
        if not shop_ids:
            return []
        q += f" AND o.shop_id IN ({','.join('?' * len(shop_ids))})"
        params.extend(shop_ids)
    if status:
        q += " AND o.status = ?"
        params.append(status)
    q += " ORDER BY o.created_at DESC LIMIT ?"
    params.append(limit)
    with connect() as c:
        return [_order_out(r) for r in c.execute(q, params).fetchall()]


def update_order(order_id: int, status: str | None = None, note: str | None = None) -> dict | None:
    sets, vals = [], []
    if status is not None:
        if status not in ORDER_STATUSES:
            raise ValueError(f"status must be one of {ORDER_STATUSES}")
        sets.append("status = ?")
        vals.append(status)
    if note is not None:
        sets.append("note = ?")
        vals.append(note)
    if sets:
        sets.append("updated_at = ?")
        vals.extend([now(), order_id])
        with connect() as c:
            c.execute(f"UPDATE orders SET {', '.join(sets)} WHERE id = ?", vals)
    return get_order(order_id)


def stats(shop_ids: list[int] | None) -> dict:
    where, params = "", []
    if shop_ids is not None:
        if not shop_ids:
            return {"conversations": 0, "messages": 0, "orders": 0, "orders_new": 0, "attention": 0, "revenue": 0}
        where = f" WHERE shop_id IN ({','.join('?' * len(shop_ids))})"
        params = list(shop_ids)
    with connect() as c:
        conv = c.execute(f"SELECT COUNT(*) FROM conversations{where}", params).fetchone()[0]
        att = c.execute(
            f"SELECT COUNT(*) FROM conversations{where}{' AND' if where else ' WHERE'} "
            "(needs_attention = 1 OR status = 'human')", params).fetchone()[0]
        msgs = c.execute(
            f"SELECT COUNT(*) FROM messages WHERE conversation_id IN (SELECT id FROM conversations{where})",
            params).fetchone()[0]
        orders = c.execute(f"SELECT COUNT(*) FROM orders{where}", params).fetchone()[0]
        new = c.execute(f"SELECT COUNT(*) FROM orders{where}{' AND' if where else ' WHERE'} status = 'new'",
                        params).fetchone()[0]
        rev = c.execute(
            f"SELECT COALESCE(SUM(total), 0) FROM orders{where}{' AND' if where else ' WHERE'} status != 'cancelled'",
            params).fetchone()[0]
    return {"conversations": conv, "messages": msgs, "orders": orders, "orders_new": new, "attention": att,
            "revenue": rev}
