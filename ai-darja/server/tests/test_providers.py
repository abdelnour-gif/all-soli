"""المزوّدين (Ollama / OpenAI-compatible) مع ردود HTTP وهمية."""
import httpx
import pytest

from darjabot.config import get_settings
from darjabot.providers import OllamaProvider, OpenAICompatProvider, ProviderError, get_provider


class Resp:
    def __init__(self, status, data):
        self.status_code, self._data = status, data

    def json(self):
        return self._data

    def raise_for_status(self):
        if self.status_code >= 400:
            raise httpx.HTTPStatusError("err", request=httpx.Request("POST", "http://x"), response=None)


def test_ollama_generate(monkeypatch):
    seen = {}

    def fake_post(url, json=None, timeout=None, **kw):
        seen["url"], seen["json"] = url, json
        return Resp(200, {"message": {"role": "assistant", "content": " إيه كاين 👌 "}})
    monkeypatch.setattr(httpx, "post", fake_post)
    out = OllamaProvider(get_settings()).generate("SYS", [{"role": "user", "content": "كاين؟"}])
    assert out.text == "إيه كاين 👌" and out.provider == "ollama:darja"
    assert seen["url"].endswith("/api/chat") and seen["json"]["messages"][0] == {"role": "system", "content": "SYS"}
    assert seen["json"]["stream"] is False


def test_ollama_down(monkeypatch):
    def boom(*a, **k):
        raise httpx.ConnectError("refused")
    monkeypatch.setattr(httpx, "post", boom)
    with pytest.raises(ProviderError):
        OllamaProvider(get_settings()).generate("SYS", [])


def test_ollama_health(monkeypatch):
    monkeypatch.setattr(httpx, "get", lambda *a, **k: Resp(200, {"models": [{"name": "darja:latest"}]}))
    assert OllamaProvider(get_settings()).health()["ok"]
    monkeypatch.setattr(httpx, "get", lambda *a, **k: Resp(200, {"models": [{"name": "llama3"}]}))
    assert not OllamaProvider(get_settings()).health()["ok"]


def test_openai_compat(monkeypatch):
    monkeypatch.setattr(httpx, "post", lambda url, **k: Resp(200, {"choices": [{"message": {"content": "سلام"}}]}))
    assert OpenAICompatProvider(get_settings()).generate("S", []).text == "سلام"
    monkeypatch.setattr(httpx, "post", lambda url, **k: Resp(200, {"oops": 1}))
    with pytest.raises(ProviderError):
        OpenAICompatProvider(get_settings()).generate("S", [])


def test_get_provider(monkeypatch):
    from darjabot import config
    assert get_provider(get_settings()) is None  # rules
    monkeypatch.setenv("LLM_PROVIDER", "ollama")
    config.reset_settings()
    assert isinstance(get_provider(get_settings()), OllamaProvider)
