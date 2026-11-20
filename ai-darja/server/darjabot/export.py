"""تصدير المحادثات الحقيقية كبيانات تدريب (نفس صيغة generate_data.py).

هكذا تتسكّر الحلقة: البوت يخدم ← صاحب المحل يصحّح الردود الغالطة ← تصدّر ←
تعاود تدرّب النموذج ← النموذج يولّي يهدر كيما الزبائن الحقيقيين.
"""
from __future__ import annotations

import json
from typing import Iterator

from . import db
from .prompt import system_prompt


def training_examples(shop_ids: list[int] | None, only_reviewed: bool = False,
                      max_messages: int = 20) -> Iterator[dict]:
    shops = {s["id"]: s for s in db.list_shops() if shop_ids is None or s["id"] in shop_ids}
    for shop_id, shop in shops.items():
        system = system_prompt(shop, db.list_products(shop_id))
        for conv in db.list_conversations([shop_id], limit=100000):
            msgs = db.list_messages(conv["id"])
            reviewed = any(m.get("corrected") or m["role"] == "owner" for m in msgs)
            if only_reviewed and not reviewed:
                continue
            turns: list[dict] = []
            for m in msgs:
                if m["role"] == "user":
                    content = m["content"]
                    if turns and turns[-1]["role"] == "user":
                        turns[-1]["content"] += "\n" + content
                    else:
                        turns.append({"role": "user", "content": content})
                elif m["role"] in ("assistant", "owner"):
                    content = m.get("corrected") or m["content"]
                    if not turns:
                        continue
                    if turns[-1]["role"] == "assistant":
                        turns[-1]["content"] += "\n" + content
                    else:
                        turns.append({"role": "assistant", "content": content})
            # لازم تبدا بزبون وتكمل بالمساعد
            while turns and turns[-1]["role"] != "assistant":
                turns.pop()
            if not turns:
                continue
            turns = turns[-max_messages:]
            while turns and turns[0]["role"] != "user":
                turns.pop(0)
            if turns:
                yield {"messages": [{"role": "system", "content": system}, *turns]}


def training_jsonl(shop_ids: list[int] | None, only_reviewed: bool = False) -> str:
    return "".join(json.dumps(ex, ensure_ascii=False) + "\n" for ex in training_examples(shop_ids, only_reviewed))
