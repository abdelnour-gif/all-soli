import random
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))


@pytest.fixture(autouse=True)
def fresh_env(tmp_path, monkeypatch):
    """كل اختبار عندو قاعدة بيانات جديدة."""
    monkeypatch.setenv("DATA_DIR", str(tmp_path))
    monkeypatch.setenv("LLM_PROVIDER", "rules")
    monkeypatch.setenv("ADMIN_PASSWORD", "test-pass")
    monkeypatch.setenv("WA_VERIFY_TOKEN", "verify-me")
    monkeypatch.delenv("META_APP_SECRET", raising=False)
    monkeypatch.delenv("NOTIFY_TELEGRAM_TOKEN", raising=False)
    from darjabot import config, db, engine
    config.reset_settings()
    db._initialized.clear()
    sent = []
    engine.set_notifier(lambda shop, text: sent.append(text))
    engine.set_provider_override(None)
    yield sent
    engine.set_provider_override(None)
    config.reset_settings()


@pytest.fixture
def notifications(fresh_env):
    return fresh_env


@pytest.fixture
def shops():
    from darjabot import db, demo
    demo.seed()
    return {s["slug"]: s for s in db.list_shops()}


@pytest.fixture
def chat(shops):
    """chat(slug, user, text) -> BotReply"""
    from darjabot import db, engine

    def _chat(slug, text, user="u1", channel="test"):
        shop = db.get_shop_by("slug", slug)
        return engine.handle_message(shop, channel, user, text, rng=random.Random(0))
    return _chat
