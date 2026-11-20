"""مزوّدو الذكاء الاصطناعي.

- rules     : المحرك المدمج (بلا نموذج، مجاني، يخدم ديما).
- ollama    : نموذجك المدرَّب (ملف GGUF) على Ollama.
- openai    : أي سيرفر متوافق مع OpenAI (vLLM، LM Studio، llama.cpp server...).
- anthropic : Claude عبر الـ API الرسمي (يحتاج ANTHROPIC_API_KEY).
"""
from __future__ import annotations

import logging
from dataclasses import dataclass

import httpx

from .config import Settings

log = logging.getLogger("darjabot.providers")


class ProviderError(Exception):
    pass


@dataclass
class LLMReply:
    text: str
    provider: str


class Provider:
    name = "base"

    def __init__(self, settings: Settings):
        self.settings = settings

    def generate(self, system: str, messages: list[dict]) -> LLMReply:  # pragma: no cover - interface
        raise NotImplementedError

    def health(self) -> dict:
        return {"provider": self.name, "ok": True}


class OllamaProvider(Provider):
    name = "ollama"

    def generate(self, system: str, messages: list[dict]) -> LLMReply:
        s = self.settings
        payload = {
            "model": s.ollama_model,
            "messages": [{"role": "system", "content": system}, *messages],
            "stream": False,
            "options": {"temperature": 0.3, "num_predict": 300},
        }
        try:
            r = httpx.post(f"{s.ollama_url}/api/chat", json=payload, timeout=s.llm_timeout)
            r.raise_for_status()
            text = (r.json().get("message") or {}).get("content", "")
        except (httpx.HTTPError, ValueError) as e:
            raise ProviderError(f"ollama: {e}") from e
        return LLMReply(text=text.strip(), provider=f"ollama:{s.ollama_model}")

    def health(self) -> dict:
        s = self.settings
        try:
            r = httpx.get(f"{s.ollama_url}/api/tags", timeout=5)
            r.raise_for_status()
            models = [m.get("name", "") for m in r.json().get("models", [])]
            found = any(m == s.ollama_model or m.split(":")[0] == s.ollama_model for m in models)
            return {"provider": self.name, "ok": found, "models": models,
                    "detail": "" if found else f"النموذج '{s.ollama_model}' ماكانش فـ Ollama"}
        except (httpx.HTTPError, ValueError) as e:
            return {"provider": self.name, "ok": False, "detail": f"Ollama ما يجاوبش على {s.ollama_url}: {e}"}


class OpenAICompatProvider(Provider):
    """لسيرفرات النماذج المفتوحة اللي تستعمل صيغة /v1/chat/completions."""
    name = "openai"

    def _headers(self) -> dict:
        h = {"Content-Type": "application/json"}
        if self.settings.openai_api_key:
            h["Authorization"] = f"Bearer {self.settings.openai_api_key}"
        return h

    def generate(self, system: str, messages: list[dict]) -> LLMReply:
        s = self.settings
        payload = {"model": s.openai_model, "messages": [{"role": "system", "content": system}, *messages],
                   "temperature": 0.3, "max_tokens": 300}
        try:
            r = httpx.post(f"{s.openai_base_url}/chat/completions", json=payload, headers=self._headers(),
                           timeout=s.llm_timeout)
            r.raise_for_status()
            text = r.json()["choices"][0]["message"]["content"] or ""
        except (httpx.HTTPError, ValueError, KeyError, IndexError) as e:
            raise ProviderError(f"openai-compatible: {e}") from e
        return LLMReply(text=text.strip(), provider=f"openai:{s.openai_model}")

    def health(self) -> dict:
        try:
            r = httpx.get(f"{self.settings.openai_base_url}/models", headers=self._headers(), timeout=5)
            r.raise_for_status()
            return {"provider": self.name, "ok": True}
        except httpx.HTTPError as e:
            return {"provider": self.name, "ok": False, "detail": str(e)}


class AnthropicProvider(Provider):
    name = "anthropic"

    def __init__(self, settings: Settings):
        super().__init__(settings)
        self._client = None

    def _get_client(self):
        if self._client is None:
            try:
                import anthropic
            except ImportError as e:  # pragma: no cover
                raise ProviderError("مكتبة anthropic ماهيش مثبّتة: pip install anthropic") from e
            self._client = anthropic.Anthropic(timeout=self.settings.llm_timeout, max_retries=2)
        return self._client

    def generate(self, system: str, messages: list[dict]) -> LLMReply:
        import anthropic

        s = self.settings
        client = self._get_client()
        # بطاقة المحل ثابتة: نحطو عليها cache_control باش التكلفة تنقص في المحادثات الطويلة
        system_blocks = [{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}]
        try:
            response = client.beta.messages.create(
                model=s.anthropic_model,
                max_tokens=16000,
                system=system_blocks,
                messages=_merge_roles(messages),
                output_config={"effort": s.anthropic_effort},
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
            )
        except anthropic.RateLimitError as e:
            raise ProviderError(f"anthropic rate limit: {e}") from e
        except anthropic.APIStatusError as e:
            raise ProviderError(f"anthropic {e.status_code}: {e}") from e
        except anthropic.APIConnectionError as e:
            raise ProviderError(f"anthropic connection: {e}") from e
        if response.stop_reason == "refusal":
            raise ProviderError("anthropic refusal")
        text = "".join(b.text for b in response.content if getattr(b, "type", "") == "text")
        return LLMReply(text=text.strip(), provider=f"anthropic:{response.model}")

    def health(self) -> dict:
        import os
        ok = bool(os.environ.get("ANTHROPIC_API_KEY") or os.environ.get("ANTHROPIC_AUTH_TOKEN")
                  or os.environ.get("ANTHROPIC_PROFILE"))
        return {"provider": self.name, "ok": ok, "model": self.settings.anthropic_model,
                "detail": "" if ok else "ANTHROPIC_API_KEY ماهوش محطوط"}


def _merge_roles(messages: list[dict]) -> list[dict]:
    """Claude يحب الأدوار تتناوب (user/assistant). نجمعو الرسائل المتتالية من نفس الدور."""
    out: list[dict] = []
    for m in messages:
        role = "assistant" if m["role"] == "assistant" else "user"
        if out and out[-1]["role"] == role:
            out[-1]["content"] += "\n" + m["content"]
        else:
            out.append({"role": role, "content": m["content"]})
    while out and out[0]["role"] != "user":
        out.pop(0)
    return out or [{"role": "user", "content": "سلام"}]


PROVIDERS = {"ollama": OllamaProvider, "openai": OpenAICompatProvider, "anthropic": AnthropicProvider}


def get_provider(settings: Settings) -> Provider | None:
    """يرجع المزوّد المختار، ولا None إذا LLM_PROVIDER=rules."""
    cls = PROVIDERS.get(settings.llm_provider)
    return cls(settings) if cls else None
